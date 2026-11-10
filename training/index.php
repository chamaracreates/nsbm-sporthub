<?php

declare(strict_types=1);

require_once __DIR__ . '/timezone.php';

/* Escape values before rendering them in HTML. */
function trainingEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* Initialize the session collection and database error state. */
$sessions = [];
$loadError = false;

/* Retrieve upcoming sessions belonging to active sports clubs. */
try {
    $sql = "
        SELECT
            ts.session_id,
            ts.title,
            ts.date,
            ts.start_time,
            ts.end_time,
            ts.description,
            sc.club_name,
            sc.sport
        FROM training_sessions AS ts
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = ts.club_id
        WHERE sc.status = 'active'
            AND TIMESTAMP(ts.date, ts.start_time) > NOW()
        ORDER BY
            ts.date ASC,
            ts.start_time ASC,
            ts.session_id ASC
    ";

    $statement = trainingDb()->query($sql);
    $sessions = $statement->fetchAll();
} catch (PDOException $error) {
    error_log(
        'Unable to retrieve training sessions: ' .
            $error->getMessage()
    );

    $loadError = true;
}

/* Load the shared SportsHub header. */
require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/training.css">

<main class="training-page">

    <div class="training-container">

        <section
            class="training-introduction"
            aria-labelledby="training-page-title">

            <p class="training-eyebrow">
                NSBM SPORTSHUB
            </p>

            <h1 id="training-page-title">
                Training Sessions
            </h1>

            <p class="training-intro-text">
                Explore upcoming sports training sessions,
                stay informed about schedules, and take
                the next step in your sporting development.
            </p>

        </section>

        <section
            class="training-schedule"
            aria-labelledby="training-schedule-title">

            <div class="training-section-heading">

                <div>

                    <p class="training-eyebrow">
                        TRAINING SCHEDULE
                    </p>

                    <h2 id="training-schedule-title">
                        Upcoming Sessions
                    </h2>

                </div>

                <?php if (!$loadError && count($sessions) > 0): ?>

                    <span class="training-session-count">
                        <?php echo count($sessions); ?>
                        <?php
                        echo count($sessions) === 1
                            ? 'session'
                            : 'sessions';
                        ?>
                    </span>

                <?php endif; ?>

            </div>

            <?php if ($loadError): ?>

                <div class="training-notice" role="alert">

                    <h3>Unable to load training sessions</h3>

                    <p>
                        Training information is currently
                        unavailable. Please try again later.
                    </p>

                </div>

            <?php elseif (empty($sessions)): ?>

                <div class="training-notice">

                    <h3>No upcoming sessions scheduled</h3>

                    <p>
                        Upcoming training schedules will
                        be published here when they are
                        available.
                    </p>

                </div>

            <?php else: ?>

                <div class="training-grid">

                    <?php foreach ($sessions as $session): ?>

                        <?php

                        /* Format the session schedule for display. */
                        $sessionDate = date(
                            'D, d M Y',
                            strtotime($session['date'])
                        );

                        $startTime = date(
                            'g:i A',
                            strtotime($session['start_time'])
                        );

                        $endTime = date(
                            'g:i A',
                            strtotime($session['end_time'])
                        );

                        ?>

                        <article class="training-card">

                            <div class="training-card-header">

                                <span class="training-sport">
                                    <?php
                                    echo trainingEscape(
                                        $session['sport']
                                    );
                                    ?>
                                </span>

                                <span class="training-status">
                                    Upcoming
                                </span>

                            </div>

                            <h3 class="training-card-title">
                                <?php
                                echo trainingEscape(
                                    $session['title']
                                );
                                ?>
                            </h3>

                            <p class="training-club">
                                <?php
                                echo trainingEscape(
                                    $session['club_name']
                                );
                                ?>
                            </p>

                            <div class="training-card-details">

                                <div class="training-detail">

                                    <span>Date</span>

                                    <strong>
                                        <?php
                                        echo trainingEscape(
                                            $sessionDate
                                        );
                                        ?>
                                    </strong>

                                </div>

                                <div class="training-detail">

                                    <span>Time</span>

                                    <strong>
                                        <?php
                                        echo trainingEscape(
                                            $startTime . ' – ' . $endTime
                                        );
                                        ?>
                                    </strong>

                                </div>

                            </div>

                            <?php if (
                                trim((string) $session['description']) !== ''
                            ): ?>

                                <p class="training-description">
                                    <?php
                                    echo trainingEscape(
                                        $session['description']
                                    );
                                    ?>
                                </p>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<?php

/* Load the shared SportsHub footer. */
require __DIR__ . '/../includes/footer.php';
