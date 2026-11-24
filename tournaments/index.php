<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

$canManageTournaments = in_array(
    current_user_role(),
    ['admin', 'coach', 'mic'],
    true
);


/* Validate the tournament directory filter. */
$tabs = [
    'upcoming' => 'Upcoming',
    'ongoing' => 'Ongoing',
    'past' => 'Past',
    'all' => 'All tournaments'
];

$activeTab = $_GET['tab'] ?? 'all';

if (
    !is_string($activeTab) ||
    !array_key_exists($activeTab, $tabs)
) {
    $activeTab = 'all';
}

$tournaments = [];
$loadError = false;

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

/* Retrieve official tournament records. */
try {
    $pdo = db();

    // The page must also work before the optional historical columns are installed.
    $columnNames = $pdo->query('SHOW COLUMNS FROM `tournaments`')->fetchAll(PDO::FETCH_COLUMN);
    $hasHistory = in_array('is_historical', $columnNames, true);
    $historyExpr = $hasHistory ? 't.is_historical' : '0';
    $yearExpr = in_array('event_year', $columnNames, true) ? 't.event_year' : 'YEAR(t.start_date)';
    $sourceExpr = in_array('source_url', $columnNames, true) ? 't.source_url' : 'NULL';
    $organizerExpr = in_array('organizer_name', $columnNames, true)
        ? "COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published')"
        : "COALESCE(sc.club_name, 'Organizer not published')";

    $conditions = ["(sc.status = 'active' OR $historyExpr = 1)", "LOWER(TRIM(t.status)) NOT IN ('draft')"];
    $parameters = [];

    switch ($activeTab) {
        case 'upcoming':
            $conditions[] = "t.start_date > :today AND LOWER(TRIM(t.status)) = 'upcoming' AND $historyExpr = 0";
            $parameters['today'] = $today;
            break;

        case 'ongoing':
            $conditions[] = '
                t.start_date <= :start_day
                AND t.end_date >= :end_day
            ';
            $conditions[] = "LOWER(TRIM(t.status)) IN ('upcoming','ongoing') AND $historyExpr = 0";
            $parameters['start_day'] = $today;
            $parameters['end_day'] = $today;
            break;

        case 'past':
            $conditions[] = "($historyExpr = 1 OR LOWER(TRIM(t.status)) = 'completed' OR t.end_date < :today)";
            $parameters['today'] = $today;
            break;

    }

    $where = implode(' AND ', $conditions);

    $order = $activeTab === 'upcoming'
        ? 't.start_date ASC, t.t_id ASC'
        : "COALESCE(t.start_date, t.end_date, MAKEDATE($yearExpr, 1)) DESC, t.t_id DESC";

    $sql = "
        SELECT
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
        WHERE $where
        ORDER BY $order
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $tournaments = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
} catch (Throwable $error) {
    error_log(
        'Tournament directory error: ' .
            $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

$pageStylesheets = ['/assets/css/tournaments.css'];
$pageTitle = 'Tournaments';
require __DIR__ . '/../includes/header.php';

?>

<main class="tournaments-page tournaments-directory-page">

    <section class="tournaments-container section-container">

        <header class="tournaments-heading">
            <h1>Tournaments &amp; Competitions</h1>

            <p class="tournaments-description section-description">
                Explore official NSBM sports tournaments,
                competition schedules, participating clubs
                and registration periods.
            </p>
            <?php if ($canManageTournaments): ?>

                <div class="tournament-directory-actions">

                    <a
                        href="/tournaments/manage.php"
                        class="button button-outline">
                        Manage Tournaments
                    </a>

                    <a
                        href="/tournaments/create.php"
                        class="button button-accent">
                        Create Tournament
                    </a>

                </div>

            <?php endif; ?>

        </header>

        <nav class="tournaments-tabs"
            aria-label="Tournament categories">

            <?php foreach ($tabs as $tab => $label): ?>
                <a
                    href="/tournaments/index.php?tab=<?= sportsHubEscape($tab) ?>"
                    class="<?= $activeTab === $tab ? 'active' : '' ?>"
                    <?= $activeTab === $tab
                        ? 'aria-current="page"'
                        : '' ?>>
                    <?= sportsHubEscape($label) ?>
                </a>
            <?php endforeach; ?>

        </nav>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load tournaments</h2>
                <p>
                    Tournament information is temporarily
                    unavailable. Please try again later.
                </p>
            </div>

        <?php elseif (empty($tournaments)): ?>

            <div class="tournaments-notice">
                <h2>No tournaments available</h2>
                <p>
                    There are currently no tournaments
                    in the selected category.
                </p>
            </div>

        <?php else: ?>


            <div class="tournaments-grid">

                <?php foreach ($tournaments as $tournament): ?>
                    <?php
                    $sourceUrl = trim((string) ($tournament['source_url'] ?? ''));
                    $photoUrl = sportsHubTournamentCover($tournament, $officialPhoto);
                    $sport = trim((string) ($tournament['sport'] ?? 'Sports'));
                    $photoAlt = $officialPhoto
                        ? 'NSBM photograph for this tournament'
                        : 'Representative NSBM sports competition photograph';
                    $eventStart = (string) ($tournament['start_date'] ?? '');
                    $eventEnd = (string) ($tournament['end_date'] ?? '');
                    if ($eventStart !== '' && $eventEnd !== '' && $eventStart !== $eventEnd) {
                        $eventLabel = sportsHubTournamentDate($eventStart) . ' – ' . sportsHubTournamentDate($eventEnd);
                    } elseif ($eventStart !== '' || $eventEnd !== '') {
                        $eventLabel = sportsHubTournamentDate($eventStart ?: $eventEnd);
                    } else {
                        $eventLabel = !empty($tournament['event_year'])
                            ? (string) (int) $tournament['event_year']
                            : 'Date to be announced';
                    }
                    $organizer = trim((string) ($tournament['organizer'] ?? ''));
                    $venue = trim((string) ($tournament['venue'] ?? ''));
                    $venueIsDefault = $venue === '';
                    // An explicitly requested display fallback. Does not update the DB or claim verification.
                    if ($venueIsDefault) {
                        $venue = 'NSBM Phase 2 Grounds';
                    }
                    $detailsUrl = '/tournaments/view.php?id=' . (int) $tournament['t_id'];
                    ?>
                    <article class="tournament-card">
                        <div class="tournament-card-media">
                            <img src="<?= sportsHubEscape($photoUrl) ?>"
                                 alt="<?= sportsHubEscape($photoAlt) ?>"
                                 loading="lazy" decoding="async">
                        </div>
                        <div class="tournament-card-body">
                            <span class="tournament-card-sport"><?= sportsHubEscape($sport) ?></span>
                            <h2><a href="<?= sportsHubEscape($detailsUrl) ?>"><?= sportsHubEscape($tournament['t_name']) ?></a></h2>
                            <p class="tournament-card-organizer"><?= sportsHubEscape($organizer !== '' ? $organizer : 'Organizer not specified') ?></p>
                            <p class="tournament-card-description"><?= sportsHubEscape($tournament['des']) ?></p>
                            <div class="tournament-card-footer">
                                <div class="tournament-card-details">
                                    <span><?= sportsHubEscape($eventLabel) ?></span>
                                    <span<?= $venueIsDefault ? ' title="Venue not confirmed; display fallback"' : '' ?>><?= sportsHubEscape($venue) ?></span>
                                </div>
                                <a class="tournament-card-footer-link" href="<?= sportsHubEscape($detailsUrl) ?>"
                                   aria-label="View <?= sportsHubEscape($tournament['t_name']) ?>">View tournament</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php';

require_once __DIR__ . '/../includes/auth.php';

$canManageTournaments = in_array(
    current_user_role(),
    ['admin', 'coach', 'mic'],
    true
);
?>