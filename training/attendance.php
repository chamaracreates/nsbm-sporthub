<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/timezone.php';

/* Restrict attendance management to authorized staff. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$isAdmin = $userRole === 'admin';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Escape dynamic values before rendering HTML. */


/* Initialize CSRF protection. */
if (
    !isset($_SESSION['training_attendance_csrf']) ||
    !is_string($_SESSION['training_attendance_csrf'])
) {
    $_SESSION['training_attendance_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['training_attendance_csrf'];

/* Retrieve and validate the selected session ID. */
$rawSessionId = $requestMethod === 'POST'
    ? ($_POST['session_id'] ?? null)
    : ($_GET['session_id'] ?? null);

$sessionId = null;

if ($rawSessionId !== null) {
    $validatedId = filter_var(
        $rawSessionId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($validatedId === false) {
        http_response_code(400);
        exit('Invalid training session.');
    }

    $sessionId = (int) $validatedId;
}

/* Validate attendance form security. */
if ($requestMethod === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission.');
    }

    if ($sessionId === null) {
        http_response_code(400);
        exit('Training session is required.');
    }
}

/* Initialize attendance page data. */
$sessions = [];
$selectedSession = null;
$students = [];
$loadError = false;

/* Retrieve feedback from the previous request. */
$feedback = $_SESSION['training_attendance_feedback'] ?? null;

unset($_SESSION['training_attendance_feedback']);

try {
    $pdo = trainingDb();

    /* Retrieve sessions available to the current staff member. */
    $sessionSql = "
        SELECT
            ts.session_id,
            ts.title,
            ts.date,
            ts.start_time,
            ts.end_time,
            sc.club_name
        FROM training_sessions AS ts
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = ts.club_id
        WHERE sc.status = 'active'
            AND (
                :is_admin = 1
                OR EXISTS (
                    SELECT 1
                    FROM sport_staff AS ss
                    WHERE ss.club_id = ts.club_id
                        AND ss.user_id = :staff_user_id
                        AND ss.staff_role = :staff_role
                        AND ss.status = 'active'
                )
            )
        ORDER BY
            ts.date DESC,
            ts.start_time DESC
        LIMIT 100
    ";

    $statement = $pdo->prepare($sessionSql);

    $statement->execute([
        'is_admin' => $isAdmin ? 1 : 0,
        'staff_user_id' => $userId,
        'staff_role' => $userRole
    ]);

    $sessions = $statement->fetchAll(PDO::FETCH_ASSOC);

    /* Retrieve the selected session with authorization checks. */
    if ($sessionId !== null) {
        $detailsSql = "
            SELECT
                ts.session_id,
                ts.club_id,
                ts.title,
                ts.date,
                ts.start_time,
                ts.end_time,
                sc.club_name,
                (
                    TIMESTAMP(ts.date, ts.start_time) <= NOW()
                ) AS can_record
            FROM training_sessions AS ts
            INNER JOIN sports_clubs AS sc
                ON sc.club_id = ts.club_id
            WHERE ts.session_id = :session_id
                AND sc.status = 'active'
                AND (
                    :is_admin = 1
                    OR EXISTS (
                        SELECT 1
                        FROM sport_staff AS ss
                        WHERE ss.club_id = ts.club_id
                            AND ss.user_id = :staff_user_id
                            AND ss.staff_role = :staff_role
                            AND ss.status = 'active'
                    )
                )
            LIMIT 1
        ";

        $statement = $pdo->prepare($detailsSql);

        $statement->execute([
            'session_id' => $sessionId,
            'is_admin' => $isAdmin ? 1 : 0,
            'staff_user_id' => $userId,
            'staff_role' => $userRole
        ]);

        $selectedSession = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$selectedSession) {
            http_response_code(404);
            exit('Training session not found or access denied.');
        }
    }

    /* Process a Present attendance submission. */
    if ($requestMethod === 'POST') {
        if (
            $selectedSession === null ||
            (int) $selectedSession['can_record'] !== 1
        ) {
            http_response_code(422);
            exit('Attendance cannot be recorded before the session starts.');
        }

        $studentId = filter_var(
            $_POST['student_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $attendanceStatus = $_POST['attendance_status'] ?? null;

        if (
            !is_int($studentId) ||
            !in_array($attendanceStatus, ['present', 'remove'], true)
        ) {
            http_response_code(400);
            exit('Invalid attendance information.');
        }

        $pdo->beginTransaction();
        $clubId = (int) $selectedSession['club_id'];
        $statement = $pdo->prepare('SELECT club_id FROM sports_clubs WHERE club_id = ? FOR UPDATE');
        $statement->execute([$clubId]);
        if (!$statement->fetchColumn() || !sportsHubCanManageClub($pdo, $clubId, $userId, $userRole)) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Access denied.');
        }
        $statement = $pdo->prepare('SELECT session_id FROM training_sessions WHERE session_id = ? AND club_id = ? AND TIMESTAMP(date, start_time) <= NOW() FOR UPDATE');
        $statement->execute([$sessionId, $clubId]);
        if (!$statement->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            exit('The training session has changed.');
        }
        $eligibilitySql = "
            SELECT 1
            FROM club_membership AS cm
            INNER JOIN users AS u
                ON u.user_id = cm.user_id
            WHERE cm.user_id = :student_id
                AND cm.club_id = :club_id
                AND cm.status = 'approved'
                AND u.role = 'student'
            LIMIT 1
        ";

        $statement = $pdo->prepare($eligibilitySql);

        $statement->execute([
            'student_id' => $studentId,
            'club_id' => (int) $selectedSession['club_id']
        ]);

        if (!$statement->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Student is not eligible for this session.');
        }

        if ($attendanceStatus === 'remove') {
            $statement = $pdo->prepare('DELETE FROM training_attendance WHERE user_id = ? AND session_id = ?');
            $statement->execute([$studentId, $sessionId]);
            $_SESSION['training_attendance_feedback'] = 'Attendance record removed.';
        } else {
            $statement = $pdo->prepare("INSERT INTO training_attendance (user_id, session_id, att_status, recorded_by)
                VALUES (?, ?, 'present', ?) ON DUPLICATE KEY UPDATE att_status = 'present', recorded_by = VALUES(recorded_by)");
            $statement->execute([$studentId, $sessionId, $userId]);
            $_SESSION['training_attendance_feedback'] = 'Student attendance marked as present.';
        }
        $pdo->commit();

        header(
            'Location: /training/attendance.php?session_id=' .
                $sessionId,
            true,
            303
        );

        exit;
    }

    /* Retrieve approved students and their attendance status. */
    if ($selectedSession !== null) {
        $studentsSql = "
            SELECT
                u.user_id,
                u.name,
                ta.att_status
            FROM club_membership AS cm
            INNER JOIN users AS u
                ON u.user_id = cm.user_id
            LEFT JOIN training_attendance AS ta
                ON ta.user_id = u.user_id
                AND ta.session_id = :session_id
            WHERE cm.club_id = :club_id
                AND cm.status = 'approved'
                AND u.role = 'student'
            ORDER BY u.name ASC
        ";

        $statement = $pdo->prepare($studentsSql);

        $statement->execute([
            'session_id' => $sessionId,
            'club_id' => (int) $selectedSession['club_id']
        ]);

        $students = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log(
        'Training attendance error: ' . $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}


$pageStylesheets = ['/assets/css/training.css'];
require __DIR__ . '/../includes/header.php';

?>

<main class="training-page sports-hub-workflow">

    <div class="training-container">

        <section
            class="training-introduction"
            aria-labelledby="attendance-page-title">

            <p class="training-eyebrow">
                TRAINING MANAGEMENT
            </p>

            <h1 id="attendance-page-title">
                Attendance Management
            </h1>

            <p class="training-intro-text">
                Manage student attendance for authorized
                NSBM sports training sessions.
                Mark eligible students as Present.
            </p>

        </section>

        <?php if ($loadError): ?>

            <div class="training-notice" role="alert">

                <h3>Unable to load attendance information</h3>

                <p>
                    Attendance information is currently
                    unavailable. Please try again later.
                </p>

            </div>

        <?php else: ?>

            <?php if (is_string($feedback)): ?>

                <p
                    class="training-attendance-feedback"
                    role="status">
                    <?php
                    echo sportsHubEscape($feedback);
                    ?>
                </p>

            <?php endif; ?>

            <section
                aria-labelledby="attendance-sessions-title">

                <div class="training-section-heading">

                    <div>

                        <p class="training-eyebrow">
                            AVAILABLE SESSIONS
                        </p>

                        <h2 id="attendance-sessions-title">
                            Select a Training Session
                        </h2>

                    </div>

                </div>

                <?php if (empty($sessions)): ?>

                    <div class="training-notice">

                        <h3>No training sessions available</h3>

                        <p>
                            No training sessions are currently
                            assigned to your account.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="training-attendance-sessions">

                        <?php foreach ($sessions as $session): ?>

                            <a
                                class="training-attendance-session"
                                href="/training/attendance.php?session_id=<?php
                                                                            echo (int) $session['session_id'];
                                                                            ?>">

                                <strong>
                                    <?php
                                    echo sportsHubEscape(
                                        $session['title']
                                    );
                                    ?>
                                </strong>

                                <span>
                                    <?php
                                    echo sportsHubEscape(
                                        $session['club_name']
                                    );
                                    ?>
                                </span>

                                <span>
                                    <?php
                                    echo sportsHubEscape(
                                        $session['date']
                                    );
                                    ?>
                                </span>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

            <?php if ($selectedSession !== null): ?>

                <section
                    class="training-attendance-roster"
                    aria-labelledby="attendance-roster-title">

                    <p class="training-eyebrow">
                        STUDENT ATTENDANCE
                    </p>

                    <h2 id="attendance-roster-title">
                        <?php
                        echo sportsHubEscape(
                            $selectedSession['title']
                        );
                        ?>
                    </h2>

                    <p class="training-attendance-context">
                        <?php
                        echo sportsHubEscape(
                            $selectedSession['club_name']
                        );
                        ?>
                        —
                        <?php
                        echo sportsHubEscape(
                            $selectedSession['date']
                        );
                        ?>
                    </p>

                    <?php if (
                        (int) $selectedSession['can_record'] !== 1
                    ): ?>

                        <div class="training-notice">

                            <h3>
                                Attendance recording is not open
                            </h3>

                            <p>
                                Attendance can be recorded once
                                the training session starts.
                            </p>

                        </div>

                    <?php elseif (empty($students)): ?>

                        <div class="training-notice">

                            <h3>No eligible students found</h3>

                            <p>
                                Students with approved memberships
                                in this sports club will appear
                                here when available.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="training-attendance-list">

                            <?php foreach ($students as $student): ?>

                                <?php

                                /* Determine whether attendance is recorded. */
                                $currentStatus = (string) (
                                    $student['att_status'] ?? ''
                                );

                                $isPresent = $currentStatus === 'present';

                                $studentId = (int) $student['user_id'];

                                ?>

                                <form
                                    class="training-attendance-row"
                                    action="/training/attendance.php"
                                    method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php
                                                echo sportsHubEscape(
                                                    $csrfToken
                                                );
                                                ?>">

                                    <input
                                        type="hidden"
                                        name="session_id"
                                        value="<?php
                                                echo (int) $sessionId;
                                                ?>">

                                    <input
                                        type="hidden"
                                        name="student_id"
                                        value="<?php
                                                echo $studentId;
                                                ?>">

                                    <strong>
                                        <?php
                                        echo sportsHubEscape(
                                            $student['name']
                                        );
                                        ?>
                                    </strong>

                                    <span
                                        class="training-attendance-current">
                                        <?php
                                        echo $isPresent
                                            ? 'Present recorded'
                                            : 'Not recorded';
                                        ?>
                                    </span>

                                    <?php if ($isPresent): ?>
                                        <button type="submit" name="attendance_status" value="remove" class="button button-danger-outline" onclick="return confirm('Remove this attendance record?');">Remove record</button>
                                    <?php else: ?>
                                        <button type="submit" name="attendance_status" value="present" class="button button-accent">Mark present</button>
                                    <?php endif; ?>

                                </form>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </section>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</main>

<?php

/* Load the shared SportsHub footer. */
require __DIR__ . '/../includes/footer.php';
