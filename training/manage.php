<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/timezone.php';

/* Require an authenticated staff account. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Escape dynamic content before rendering. */
function trainingManageEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$isAdmin = $userRole === 'admin';
$clubs = [];
$sessions = [];
$loadError = false;

try {
    $pdo = db();

    /* Restrict staff access to their active club assignments. */
    $clubScope = '';

    $parameters = [];

    if (!$isAdmin) {
        $clubScope = "
            AND EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = sc.club_id
                    AND ss.user_id = :staff_user_id
                    AND ss.staff_role = :staff_role
                    AND ss.status = 'active'
            )
        ";

        $parameters = [
            'staff_user_id' => $userId,
            'staff_role' => $userRole
        ];
    }

    /* Retrieve clubs that this account may manage. */
    $clubSql = "
        SELECT
            sc.club_id,
            sc.club_name,
            sc.sport
        FROM sports_clubs AS sc
        WHERE sc.status = 'active'
        $clubScope
        ORDER BY sc.club_name ASC
    ";

    $statement = $pdo->prepare($clubSql);
    $statement->execute($parameters);
    $clubs = $statement->fetchAll(PDO::FETCH_ASSOC);

    /* Retrieve training sessions within the authorized scope. */
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
        $clubScope
        ORDER BY
            ts.date DESC,
            ts.start_time DESC
        LIMIT 100
    ";

    $statement = $pdo->prepare($sessionSql);
    $statement->execute($parameters);
    $sessions = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $error) {
    error_log(
        'Training management error: ' . $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

/* Load the existing SportsHub layout. */
require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/training.css">

<main class="training-page">

    <div class="training-container">

        <section class="training-introduction">

            <p class="training-eyebrow">
                NSBM SPORTSHUB
            </p>

            <h1>Training Management</h1>

            <p class="training-intro-text">
                View authorized sports clubs, review training
                schedules, and access student attendance
                management.
            </p>

        </section>

        <?php if ($loadError): ?>

            <div class="training-notice" role="alert">

                <h3>Unable to load training information</h3>

                <p>
                    Please try again later.
                </p>

            </div>

        <?php else: ?>

            <section aria-labelledby="managed-clubs-heading">

                <div class="training-section-heading">

                    <div>

                        <p class="training-eyebrow">
                            CLUB MANAGEMENT
                        </p>

                        <h2 id="managed-clubs-heading">
                            Managed Sports Clubs
                        </h2>

                    </div>

                    <span class="training-session-count">
                        <?php echo count($clubs); ?> clubs
                    </span>

                </div>

                <?php if (empty($clubs)): ?>

                    <div class="training-notice">

                        <h3>No sports clubs available</h3>

                        <p>
                            There are currently no active
                            sports clubs available for
                            management under this account.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="training-grid">

                        <?php foreach ($clubs as $club): ?>

                            <article class="training-card">

                                <div class="training-card-header">

                                    <span class="training-sport">
                                        <?php
                                        echo trainingManageEscape(
                                            $club['sport']
                                        );
                                        ?>
                                    </span>

                                </div>

                                <h3 class="training-card-title">
                                    <?php
                                    echo trainingManageEscape(
                                        $club['club_name']
                                    );
                                    ?>
                                </h3>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

            <section
                aria-labelledby="managed-sessions-heading"
                style="margin-top: 70px;">

                <div class="training-section-heading">

                    <div>

                        <p class="training-eyebrow">
                            TRAINING SCHEDULE
                        </p>

                        <h2 id="managed-sessions-heading">
                            Training Sessions
                        </h2>

                    </div>

                    <div class="training-schedule-controls">

                        <span class="training-session-count">
                            <?php echo count($sessions); ?> sessions
                        </span>

                        <?php if (!empty($clubs)): ?>

                            <a
                                href="/training/create.php"
                                class="training-create-link">
                                Create Training Session
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

                <?php if (empty($sessions)): ?>

                    <div class="training-notice">

                        <h3>No training sessions available</h3>

                        <p>
                            Training sessions for your
                            authorized sports clubs will
                            appear here when scheduled.
                        </p>

                    </div>

                <?php else: ?>


                    <div class="training-attendance-sessions">

                        <?php foreach ($sessions as $session): ?>

                            <article class="training-attendance-session">

                                <strong>
                                    <?php
                                    echo trainingManageEscape(
                                        $session['title']
                                    );
                                    ?>
                                </strong>

                                <span>
                                    <?php
                                    echo trainingManageEscape(
                                        $session['club_name']
                                    );
                                    ?>
                                </span>

                                <span>
                                    <?php
                                    echo trainingManageEscape(
                                        $session['date']
                                    );
                                    ?>
                                </span>

                                <div class="training-session-actions">

                                    <a
                                        href="/training/attendance.php?session_id=<?php
                                                                                    echo (int) $session['session_id'];
                                                                                    ?>"
                                        class="training-session-action">
                                        Manage Attendance
                                    </a>

                                    <a
                                        href="/training/edit.php?session_id=<?php
                                                                            echo (int) $session['session_id'];
                                                                            ?>"
                                        class="training-session-action training-session-edit">
                                        Edit Session
                                    </a>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </div>

</main>

<?php

/* Load the shared SportsHub footer. */
require __DIR__ . '/../includes/footer.php';
