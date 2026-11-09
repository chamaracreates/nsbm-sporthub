<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

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
function attendanceEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

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

/* Retrieve the selected training session ID. */
$rawSessionId = $requestMethod === 'POST'
    ? ($_POST['session_id'] ?? null)
    : ($_GET['session_id'] ?? null);

$sessionId = filter_var(
    $rawSessionId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($rawSessionId !== null && $sessionId === false) {
    http_response_code(400);
    exit('Invalid training session.');
}

$sessionId = is_int($sessionId) ? $sessionId : null;

$sessions = [];
$selectedSession = null;
$students = [];
$loadError = false;

/* Retrieve attendance feedback from the previous request. */
$feedback = $_SESSION['training_attendance_feedback'] ?? null;

unset($_SESSION['training_attendance_feedback']);

try {
    $pdo = db();

    /* Retrieve sessions accessible to the current staff member. */
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
        WHERE
            :is_admin = 1
            OR EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = ts.club_id
                    AND ss.user_id = :staff_user_id
                    AND ss.staff_role = :staff_role
                    AND ss.status = 'active'
            )
        ORDER BY ts.date DESC, ts.start_time DESC
        LIMIT 100
    ";

    $statement = $pdo->prepare($sessionSql);

    $statement->execute([
        'is_admin' => $isAdmin ? 1 : 0,
        'staff_user_id' => $userId,
        'staff_role' => $userRole
    ]);

    $sessions = $statement->fetchAll();

    if ($sessionId !== null) {

        /* Verify authorization for the selected session. */
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

        $selectedSession = $statement->fetch();

        if (!$selectedSession) {
            http_response_code(404);
            exit('Training session not found or access denied.');
        }
    }

    /* Validate and process an attendance submission. */
    if ($requestMethod === 'POST') {

        $submittedToken = $_POST['csrf_token'] ?? null;

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            http_response_code(403);
            exit('Invalid form submission.');
        }

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
            !is_string($attendanceStatus) ||
            !in_array($attendanceStatus, ['present', 'absent'], true)
        ) {
            http_response_code(400);
            exit('Invalid attendance information.');
        }

        /* Verify approved club membership and student identity. */
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
            http_response_code(403);
            exit('Student is not eligible for this session.');
        }

        /* Insert or update the student's attendance record. */
        $saveSql = "
            INSERT INTO training_attendance (
                user_id,
                session_id,
                att_status,
                recorded_by
            )
            VALUES (
                :student_id,
                :session_id,
                :attendance_status,
                :recorded_by
            )
            ON DUPLICATE KEY UPDATE
                att_status = :updated_status,
                recorded_by = :updated_by
        ";

        $statement = $pdo->prepare($saveSql);

        $statement->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'attendance_status' => $attendanceStatus,
            'recorded_by' => $userId,
            'updated_status' => $attendanceStatus,
            'updated_by' => $userId
        ]);

        $_SESSION['training_attendance_feedback'] =
            'Attendance recorded successfully.';

        header(
            'Location: /training/attendance.php?session_id=' . $sessionId,
            true,
            303
        );

        exit;
    }

    if ($selectedSession !== null) {

        /* Retrieve approved members and existing attendance records. */
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

        $students = $statement->fetchAll();
    }
} catch (Throwable $error) {
    error_log(
        'Training attendance error: ' . $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

/* Load the shared SportsHub layout. */
require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/training.css">

<main class="training-page">

    <div class="training-container">

        <section class="training-introduction">

            <p class="training-eyebrow">
                TRAINING MANAGEMENT
            </p>

            <h1>Attendance Management</h1>

            <p class="training-intro-text">
                Manage student attendance for authorized
                NSBM sports training sessions.
            </p>

        </section>

        <?php if ($loadError): ?>

            <div class="training-notice" role="alert">
                <h3>Unable to load attendance information</h3>
                <p>Please try again later.</p>
            </div>

        <?php else: ?>

            <?php if (is_string($feedback)): ?>

                <p class="training-attendance-feedback" role="status">
                    <?php echo attendanceEscape($feedback); ?>
                </p>

            <?php endif; ?>

            <section aria-labelledby="attendance-sessions-title">

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
                                    echo attendanceEscape(
                                        $session['title']
                                    );
                                    ?>
                                </strong>

                                <span>
                                    <?php
                                    echo attendanceEscape(
                                        $session['club_name']
                                    );
                                    ?>
                                </span>

                                <span>
                                    <?php
                                    echo attendanceEscape(
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
                        echo attendanceEscape(
                            $selectedSession['title']
                        );
                        ?>
                    </h2>

                    <p class="training-attendance-context">
                        <?php
                        echo attendanceEscape(
                            $selectedSession['club_name']
                        );
                        ?>
                        —
                        <?php
                        echo attendanceEscape(
                            $selectedSession['date']
                        );
                        ?>
                    </p>

                    <?php if (
                        (int) $selectedSession['can_record'] !== 1
                    ): ?>

                        <div class="training-notice">
                            <h3>Attendance recording is not open</h3>
                            <p>
                                Attendance can be recorded
                                once the training session starts.
                            </p>
                        </div>

                    <?php elseif (empty($students)): ?>

                        <div class="training-notice">
                            <h3>No eligible students found</h3>
                            <p>
                                Approved club members will
                                appear here when available.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="training-attendance-list">

                            <?php foreach ($students as $student): ?>

                                <?php
                                $currentStatus = (string) (
                                    $student['att_status'] ?? ''
                                );

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
                                                echo attendanceEscape(
                                                    $csrfToken
                                                );
                                                ?>">

                                    <input
                                        type="hidden"
                                        name="session_id"
                                        value="<?php echo $sessionId; ?>">

                                    <input
                                        type="hidden"
                                        name="student_id"
                                        value="<?php echo $studentId; ?>">

                                    <strong>
                                        <?php
                                        echo attendanceEscape(
                                            $student['name']
                                        );
                                        ?>
                                    </strong>

                                    <label
                                        class="training-attendance-label"
                                        for="attendance-<?php echo $studentId; ?>">
                                        Attendance status
                                    </label>

                                    <select
                                        id="attendance-<?php echo $studentId; ?>"
                                        name="attendance_status"
                                        required>

                                        <option value="" disabled <?php
                                                                    echo !in_array(
                                                                        $currentStatus,
                                                                        ['present', 'absent'],
                                                                        true
                                                                    ) ? 'selected' : '';
                                                                    ?>>
                                            Select status
                                        </option>

                                        <option
                                            value="present"
                                            <?php
                                            echo $currentStatus === 'present'
                                                ? 'selected'
                                                : '';
                                            ?>>
                                            Present
                                        </option>

                                        <option
                                            value="absent"
                                            <?php
                                            echo $currentStatus === 'absent'
                                                ? 'selected'
                                                : '';
                                            ?>>
                                            Absent
                                        </option>

                                    </select>

                                    <button type="submit">
                                        Save Attendance
                                    </button>

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
