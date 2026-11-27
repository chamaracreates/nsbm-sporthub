<?php

declare(strict_types=1);

require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

// Restrict session management actions to authorized administrators.
$phase14TrainingIsAdmin = current_user_role() === 'admin';
if ($phase14TrainingIsAdmin && empty($_SESSION['phase14_training_csrf'])) {
    $_SESSION['phase14_training_csrf'] = bin2hex(random_bytes(32));
}
$phase14TrainingFlash = $_SESSION['phase14_training_flash'] ?? null;
unset($_SESSION['phase14_training_flash']);
// Separate completed sessions from future and ongoing sessions.
$trainingView = ($_GET['tab'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$trainingTimeFilter = $trainingView === 'past'
    ? 'TIMESTAMP(ts.date, ts.end_time) <= NOW()'
    : 'TIMESTAMP(ts.date, ts.end_time) > NOW()';
$trainingSort = $trainingView === 'past'
    ? 'ts.date DESC, ts.start_time DESC, ts.session_id DESC'
    : 'ts.date ASC, ts.start_time ASC, ts.session_id ASC';

/* Initialize the session collection and database error state. */
$sessions = [];
$loadError = false;

/* Retrieve sessions belonging to active sports clubs. */
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
            sc.sport,
            r.res_name AS facility,
            TIMESTAMP(ts.date, ts.start_time) > NOW() AS can_edit,
            EXISTS (SELECT 1 FROM training_attendance ta WHERE ta.session_id = ts.session_id) AS has_attendance
        FROM training_sessions AS ts
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = ts.club_id
        LEFT JOIN resources r ON r.res_id = ts.location
        WHERE sc.status = 'active'
            AND $trainingTimeFilter
        ORDER BY $trainingSort
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


$pageStylesheets = ['/assets/css/training.css'];
$pageTitle = 'Training';
require __DIR__ . '/../includes/header.php';

?>

<main class="training-page training-directory-page">
    <?php if (is_string($phase14TrainingFlash) && $phase14TrainingFlash !== ''): ?>
        <div class="phase13-feedback shell" role="status"><?= sportsHubEscape($phase14TrainingFlash) ?></div>
    <?php endif; ?>

    <div class="training-container">

        <section
            class="training-introduction"
            aria-labelledby="training-page-title">

            <h1 id="training-page-title">
                Training Sessions
            </h1>

            <p class="training-intro-text">
                Explore upcoming sports training sessions,
                stay informed about schedules, and take
                the next step in your sporting development.
            </p>

        </section>

        <nav aria-label="Training schedule views" class="training-view-links">
            <a href="/training/index.php?tab=upcoming" <?= $trainingView === 'upcoming' ? 'aria-current="page"' : '' ?>>Upcoming &amp; current sessions</a>
            <a href="/training/index.php?tab=past" <?= $trainingView === 'past' ? 'aria-current="page"' : '' ?>>Past sessions</a>
        </nav>

        <section
            class="training-schedule"
            aria-labelledby="training-schedule-title">

            <div class="training-section-heading">

                <div>

                    <h2 id="training-schedule-title">
                        <?= $trainingView === 'past' ? 'Past Sessions' : 'Upcoming &amp; Current Sessions' ?>
                    </h2>

                </div>
                <?php if ($phase14TrainingIsAdmin): ?>
                    <nav class="phase14-training-actions" aria-label="Training administration">
                        <a class="button button-outline" href="/training/manage.php">Manage sessions</a>
                        <a class="button button-accent" href="/training/create.php">Create session</a>
                    </nav>
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

                    <h3><?= $trainingView === 'past' ? 'No past sessions recorded' : 'No upcoming sessions scheduled' ?></h3>
                    <p><?= $trainingView === 'past'
                        ? 'No completed training sessions are recorded in SportsHub yet.'
                        : 'Upcoming training schedules will be published when officially confirmed.' ?></p>

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

                        <article class="training-session-card">
                            <div class="training-session-heading">
                                <span class="training-session-club"><?= sportsHubEscape($session['club_name'] ?: $session['sport']) ?></span>
                                <span class="training-session-status"><?= $trainingView === 'past' ? 'Past' : ((int) $session['can_edit'] === 1 ? 'Upcoming' : 'In progress') ?></span>
                            </div>
                            <h3 class="training-session-title"><?= sportsHubEscape($session['title']) ?></h3>
                            <p class="training-session-description"><?= sportsHubEscape(trim((string)($session['description'] ?? '')) ?: 'Session details will be provided by the club.') ?></p>
                            <div class="training-session-schedule">
                                <div class="training-session-schedule-item">
                                    <span class="training-session-schedule-label">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                                        Date
                                    </span>
                                    <time datetime="<?= sportsHubEscape($session['date']) ?>"><?= sportsHubEscape($sessionDate) ?></time>
                                </div>
                                <div class="training-session-schedule-item">
                                    <span class="training-session-schedule-label">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                        Time
                                    </span>
                                    <span class="training-session-value"><?= sportsHubEscape($startTime . ' – ' . $endTime) ?></span>
                                </div>
                                <div class="training-session-schedule-item training-session-venue">
                                    <span class="training-session-schedule-label">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                        Venue
                                    </span>
                                    <span class="training-session-value"><?= sportsHubEscape($session['facility'] ?? 'Not available') ?></span>
                                </div>
                            </div>
                            <?php if ($phase14TrainingIsAdmin && (int) $session['can_edit'] === 1): ?>
                            <div class="phase14-training-card-actions">
                                <a class="button button-outline" href="/training/edit.php?session_id=<?= (int)$session['session_id'] ?>">Edit</a>
                                <?php if (!(int) $session['has_attendance']): ?>
                                <form method="post" action="/training/admin-delete.php"
                                      onsubmit="return confirm('Delete this unused training session? This cannot be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($_SESSION['phase14_training_csrf']) ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$session['session_id'] ?>">
                                    <button type="submit" class="button button-outline phase14-danger">Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
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
