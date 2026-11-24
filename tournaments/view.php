<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';
auth_start();


$tournamentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$tournament = null;
$teams = [];
$pageError = null;

if (!is_int($tournamentId)) {
    http_response_code(400);
    $pageError = 'Invalid tournament link.';
} else {
    try {
        $pdo = db();
        $columnNames = $pdo->query('SHOW COLUMNS FROM `tournaments`')->fetchAll(PDO::FETCH_COLUMN);
        $historyExpr = in_array('is_historical', $columnNames, true) ? 't.is_historical' : '0';
        $yearExpr = in_array('event_year', $columnNames, true) ? 't.event_year' : 'YEAR(t.start_date)';
        $sourceExpr = in_array('source_url', $columnNames, true) ? 't.source_url' : 'NULL';
        $organizerExpr = in_array('organizer_name', $columnNames, true)
            ? "COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published')"
            : "COALESCE(sc.club_name, 'Organizer not published')";

        /* Retrieve the selected official tournament. */
        $statement = $pdo->prepare(
            "SELECT
                t.t_id,
                t.t_name,
                t.sport,
                t.des,
                t.reg_open_at,
                t.reg_end_at,
                t.start_date,
                t.end_date,
                t.venue,
                t.status,
                $yearExpr AS event_year,
                $historyExpr AS is_historical,
                $sourceExpr AS source_url,
                $organizerExpr AS organizer
            FROM tournaments AS t
            LEFT JOIN sports_clubs AS sc
                ON sc.club_id = t.club_id
            WHERE t.t_id = :tournament_id
                AND (sc.status = 'active' OR $historyExpr = 1)
                AND LOWER(TRIM(t.status)) <> 'draft'
            LIMIT 1"
        );

        $statement->execute([
            'tournament_id' => $tournamentId
        ]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($result)) {
            http_response_code(404);
            $pageError = 'Tournament not found.';
        } else {
            $tournament = $result;

            /* Retrieve teams associated with this tournament. */
            $statement = $pdo->prepare(
                "SELECT
                    tm.team_id,
                    tm.team_name,
                    sc.club_name
                FROM teams AS tm
                LEFT JOIN sports_clubs AS sc
                    ON sc.club_id = tm.club_id
                WHERE tm.t_id = :tournament_id
                ORDER BY tm.team_name ASC, tm.team_id ASC"
            );

            $statement->execute([
                'tournament_id' => $tournamentId
            ]);

            $teams = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $error) {
        error_log(
            'Tournament details error: ' .
                $error->getMessage()
        );

        http_response_code(500);
        $pageError = 'Tournament information is temporarily unavailable.';
    }
}

$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';

?>

<main class="tournaments-page tournament-profile-page">
    <?php if ($pageError !== null || $tournament === null): ?>
        <div class="shell tournament-profile-error">
            <a class="tournament-profile-back" href="/tournaments/index.php">&larr; Back to tournaments</a>
            <div class="tournaments-notice" role="alert">
                <h1>Tournament details unavailable</h1>
                <p><?= sportsHubEscape($pageError ?? 'Tournament not found.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <?php
        $rawDescription = trim((string) ($tournament['des'] ?? ''));
        // Omit score-availability notes from the event summary.
        $overview = trim(preg_replace('/\s*(Numerical final scores are not stated|Exact final scores are not stated)\.?/iu', '', $rawDescription) ?? $rawDescription);
        if (stripos((string) $tournament['t_name'], 'Challenge Trophy Basketball 2026') !== false) {
            $overview = 'NSBM alumni basketball teams secured victories against the university men’s and women’s teams at the 2026 Vice Chancellor’s Challenge Trophy.';
        }
        if ($overview === '') {
            $overview = 'Discover the tournament schedule, participating teams and competition details.';
        }
        $heroPhoto = sportsHubTournamentCover($tournament);

        $registrationToday = (new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Colombo')
        ))->format('Y-m-d');

        $registrationAvailable = sportsHubRegistrationOpen($tournament, $registrationToday);

        $canApply = !is_logged_in()
            || current_user_role() === 'student';

        $eventStatus = strtolower(trim((string) $tournament['status']));
        $isCompleted = $eventStatus === 'completed';
        $eventStart = trim((string) ($tournament['start_date'] ?? ''));
        $eventEnd = trim((string) ($tournament['end_date'] ?? ''));
        // Display a single date when the event starts and ends on the same day.
        $isSingleDate = $eventStart !== '' && ($eventStart === $eventEnd || $eventEnd === '');
        $venue = trim((string) ($tournament['venue'] ?? ''));
        ?>

        <section class="tournament-profile-cover" aria-labelledby="tournament-profile-title">
            <div class="tournament-profile-image" aria-hidden="true">
                <img src="<?= sportsHubEscape($heroPhoto) ?>" alt="" loading="eager" decoding="async">
            </div>
            <div class="shell tournament-profile-heading">
                <a class="tournament-profile-back" href="/tournaments/index.php">&larr; Back to tournaments</a>
                <h1 id="tournament-profile-title"><?= sportsHubEscape($tournament['t_name']) ?></h1>
                <p><?= sportsHubEscape($overview) ?></p>
            </div>
        </section>

        <div class="shell tournament-profile-content">
            <section class="tournament-profile-details" aria-labelledby="tournament-details-title">
                <div class="tournament-profile-section-heading">
                    <div>
                        <p class="tournament-profile-sport"><?= sportsHubEscape($tournament['sport']) ?></p>
                        <h2 id="tournament-details-title">Schedule and venue</h2>
                    </div>
                    <span class="tournament-profile-status"><?= sportsHubEscape(ucfirst($eventStatus)) ?></span>
                </div>

                <dl class="tournament-profile-facts">
                    <div>
                        <dt>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                            <?= $isSingleDate ? 'Date' : 'Event dates' ?>
                        </dt>
                        <?php if ($isSingleDate): ?>
                            <dd><?= sportsHubEscape(sportsHubTournamentDate($eventStart)) ?></dd>
                        <?php elseif ($eventStart !== '' && $eventEnd !== ''): ?>
                            <dd><?= sportsHubEscape(sportsHubTournamentDate($eventStart)) ?> &ndash; <?= sportsHubEscape(sportsHubTournamentDate($eventEnd)) ?></dd>
                        <?php elseif ($eventEnd !== ''): ?>
                            <dd>Ends <?= sportsHubEscape(sportsHubTournamentDate($eventEnd)) ?></dd>
                        <?php else: ?>
                            <dd>Not specified</dd>
                        <?php endif; ?>
                    </div>
                    <div>
                        <dt>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 10-8 10S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            Venue
                        </dt>
                        <dd><?= sportsHubEscape($venue !== '' ? $venue : 'Not published') ?></dd>
                    </div>
                    <div>
                        <dt>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h6"/></svg>
                            Registration
                        </dt>
                        <dd class="<?= $registrationAvailable ? 'tournament-registration-open' : '' ?>">
                            <?= $isCompleted ? 'Closed' : ($registrationAvailable ? 'Open' : 'Not currently open') ?>
                        </dd>
                    </div>
                </dl>

                <?php if (!$isCompleted): ?>
                    <div class="tournament-profile-registration">
                        <div>
                            <h3>Registration period</h3>
                            <p>Opens <?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_open_at'])) ?> &middot; Closes <?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_end_at'])) ?></p>
                        </div>
                        <?php if ($registrationAvailable && $canApply): ?>
                            <a class="button button-accent" href="/tournaments/register.php?id=<?= (int) $tournament['t_id'] ?>">Register for tournament</a>
                        <?php elseif ($registrationAvailable): ?>
                            <p>Registration is available to students.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tournament-profile-teams" aria-labelledby="tournament-teams-title">
                <div class="tournament-profile-section-heading">
                    <h2 id="tournament-teams-title">Participating teams</h2>
                    <?php if (!empty($teams)): ?>
                        <span class="tournament-profile-count"><?= count($teams) ?> <?= count($teams) === 1 ? 'team' : 'teams' ?></span>
                    <?php endif; ?>
                </div>
                <?php if (empty($teams)): ?>
                    <div class="tournament-profile-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M21 20v-2a6 6 0 0 0-4-5.7"/></svg>
                        <p><?= $isCompleted ? 'Team details are not available for this tournament.' : 'No participating teams have been listed yet.' ?></p>
                    </div>
                <?php else: ?>
                    <ul class="tournament-profile-team-list">
                        <?php foreach ($teams as $team): ?>
                            <li>
                                <strong><?= sportsHubEscape($team['team_name']) ?></strong>
                                <span><?= sportsHubEscape($team['club_name'] ?? 'Club information unavailable') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
