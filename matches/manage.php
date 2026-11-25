<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

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


$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))->format('Y-m-d');
$allowedFilters = ['all', 'scheduled', 'awaiting', 'completed'];
$filter = $_GET['filter'] ?? 'all';

if (
    !is_string($filter) ||
    !in_array($filter, $allowedFilters, true)
) {
    $filter = 'all';
}

$allMatches = [];
$matches = [];
$loadError = false;

$counts = [
    'all' => 0,
    'scheduled' => 0,
    'awaiting' => 0,
    'completed' => 0
];

try {
    $pdo = db();

    /*
     * Administrators can see matches from all active clubs.
     * Coaches and MICs can only see matches associated
     * with clubs where they have an active staff assignment.
     */

    $scope = '';
    $parameters = [];

    if ($userRole !== 'admin') {
        $scope = "
            AND EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = t.club_id
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

    /*
     * Verify that both teams belong to the
     * tournament and its associated sports club.
     */

    // Left joins retain fixtures without tournament or team relationships.
    // The tournament branch retains the original team/club integrity checks.
    $statement = $pdo->prepare(
        "SELECT
            m.m_id, m.title, m.date, m.venue, m.match_status,
            m.home_score, m.away_score, m.home_wickets, m.away_wickets, m.home_balls, m.away_balls,
            t.t_id, t.status AS tournament_status,
            COALESCE(t.t_name, 'Standalone match') AS t_name,
            m.sport,
            m.home_name AS home_team,
            m.away_name AS away_team
         FROM matches AS m
         LEFT JOIN tournaments AS t ON t.t_id = m.t_id
         LEFT JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id AND sc.status = 'active'
         LEFT JOIN teams AS home
            ON home.team_id = m.home_team_id
           AND home.t_id = t.t_id AND home.club_id = t.club_id
         LEFT JOIN teams AS away
            ON away.team_id = m.away_team_id
           AND away.t_id = t.t_id AND away.club_id = t.club_id
         WHERE (
             (m.t_id IS NULL
              AND m.home_team_id IS NULL AND m.away_team_id IS NULL
              AND m.home_u_id IS NULL AND m.away_u_id IS NULL)
             OR
             (m.t_id IS NOT NULL AND sc.club_id IS NOT NULL
              AND home.team_id IS NOT NULL AND away.team_id IS NOT NULL
              AND m.home_team_id <> m.away_team_id)
         )
         $scope
         ORDER BY m.date DESC, m.m_id DESC"
    );

    $statement->execute($parameters);

    $allMatches = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($allMatches as $match) {
        $status = strtolower(trim((string) $match['match_status']));
        $scheduled = in_array($status, ['scheduled', 'upcoming'], true);
        $completed = in_array($status, ['completed', 'finished'], true);
        $due = (string) $match['date'] <= $today;
        $category = $completed ? 'completed' : ($scheduled ? ($due ? 'awaiting' : 'scheduled') : null);

        $match['category'] = $category;
        $match['can_record_result'] = ($scheduled || $completed) && $due;
        $match['can_edit_fixture'] = $scheduled && ($match['t_id'] === null || in_array(
            strtolower(trim((string) $match['tournament_status'])), ['upcoming', 'ongoing'], true
        ));

        $counts['all']++;
        if ($category !== null) {
            $counts[$category]++;
        }
        if ($filter === 'all' || $filter === $category) {
            $matches[] = $match;
        }
    }
} catch (Throwable $error) {
    error_log(
        'Match management query: ' . $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

$pageStylesheets = ['/assets/css/matches.css'];

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page sports-hub-workflow match-management-page">
    <section class="section-container matches-section">
        <div class="matches-header">
            <h1>Manage matches.</h1>
            <p class="section-description">Schedule fixtures, record scores and review match results.</p>
        </div>

        <div class="match-management-toolbar">
            <?php if (!$loadError): ?>
                <nav class="matches-tabs" aria-label="Match management filters">
                    <?php foreach (['all' => 'All matches', 'scheduled' => 'Scheduled', 'awaiting' => 'Awaiting results', 'completed' => 'Completed'] as $key => $label): ?>
                        <a href="?filter=<?= sportsHubEscape($key) ?>"
                           class="<?= $filter === $key ? 'active' : '' ?>"
                           <?= $filter === $key ? 'aria-current="page"' : '' ?>>
                            <?= sportsHubEscape($label) ?> <span class="match-filter-count"><?= $counts[$key] ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <a href="/matches/create.php" class="button button-accent">Create match fixture</a>
        </div>

        <?php if ($loadError): ?>
            <div class="matches-empty" role="alert">
                <h2>Unable to load matches.</h2>
                <p>Match management information is temporarily unavailable.</p>
            </div>
        <?php elseif ($matches === []): ?>
            <div class="matches-empty">
                <h2>No matches found.</h2>
                <p><?= $filter === 'awaiting' ? 'There are no fixtures awaiting results.' : 'No fixtures match this filter in your authorized clubs.' ?></p>
            </div>
        <?php else: ?>
            <div class="matches-list">
                <?php foreach ($matches as $match): ?>
                    <?php
                    $completed = $match['category'] === 'completed';
                    $awaiting = $match['category'] === 'awaiting';
                    $statusLabel = match ($match['category']) {
                        'completed' => 'Completed',
                        'awaiting' => 'Awaiting result',
                        'scheduled' => 'Scheduled',
                        default => ucfirst(trim((string) $match['match_status']))
                    };
                    $matchId = (int) $match['m_id'];
                    ?>
                    <article class="match-card">
                        <div class="match-card-inner">
                            <div class="match-card-meta">
                                <span><?= sportsHubEscape($match['sport']) ?></span>
                                <span class="match-management-status<?= $awaiting ? ' is-awaiting' : '' ?>"><?= sportsHubEscape($statusLabel) ?></span>
                            </div>
                            <h2><a href="/matches/view.php?id=<?= $matchId ?>"><?= sportsHubEscape($match['title']) ?></a></h2>
                            <p class="match-management-tournament"><?= sportsHubEscape($match['t_name']) ?></p>
                            <div class="match-card-teams">
                                <span><?= sportsHubEscape($match['home_team']) ?></span>
                                <strong><?= $completed ? sportsHubEscape(sportsHubMatchScore($match, 'home') . ' – ' . sportsHubMatchScore($match, 'away')) : 'VS' ?><?php if ($completed && strcasecmp((string) $match['sport'], 'Volleyball') === 0): ?><small class="match-score-unit">sets</small><?php endif; ?></strong>
                                <span><?= sportsHubEscape($match['away_team']) ?></span>
                            </div>
                            <div class="match-card-details">
                                <span><?= sportsHubEscape(sportsHubDisplayDate($match['date'])) ?></span>
                                <span><?= sportsHubEscape($match['venue']) ?></span>
                            </div>
                            <div class="match-management-card-actions">
                                <?php if ($match['can_record_result']): ?>
                                    <a href="/matches/edit.php?id=<?= $matchId ?>&amp;mode=result#match-result" class="button button-accent"><?= $completed ? 'Edit result' : 'Record result' ?></a>
                                <?php endif; ?>
                                <?php if ($match['can_edit_fixture']): ?>
                                    <a href="/matches/edit.php?id=<?= $matchId ?>&amp;mode=fixture" class="button <?= $awaiting ? 'button-outline' : 'button-accent' ?>">Edit fixture</a>
                                <?php endif; ?>
                                <a href="/matches/edit.php?id=<?= $matchId ?>&amp;mode=details" class="button button-outline">Edit details</a>
                                <a href="/matches/view.php?id=<?= $matchId ?>" class="button button-outline">View details</a>
                                <?php if ($userRole === 'admin'): ?>
                                    <a href="/admin/delete.php?type=match&amp;id=<?= $matchId ?>" class="button button-danger-outline">Delete fixture</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
