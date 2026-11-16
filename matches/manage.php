<?php

declare(strict_types=1);

/*
 * NSBM SportsHub
 * Member 06 - Match Management Dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
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

function matchManageEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function matchManageDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        ? $date->format('d M Y')
        : $value;
}

// Validate the selected filter.
$allowedFilters = ['all', 'scheduled', 'completed'];
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

    $statement = $pdo->prepare(
        "SELECT
            m.m_id,
            m.title,
            m.date,
            m.venue,
            m.match_status,
            m.home_score,
            m.away_score,
            t.t_id,
            t.t_name,
            t.sport,
            home.team_name AS home_team,
            away.team_name AS away_team
         FROM matches AS m
         INNER JOIN tournaments AS t
            ON t.t_id = m.t_id
         INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
           AND sc.status = 'active'
         INNER JOIN teams AS home
            ON home.team_id = m.home_team_id
           AND home.t_id = t.t_id
           AND home.club_id = t.club_id
         INNER JOIN teams AS away
            ON away.team_id = m.away_team_id
           AND away.t_id = t.t_id
           AND away.club_id = t.club_id
         WHERE m.home_team_id <> m.away_team_id
         $scope
         ORDER BY m.date DESC, m.m_id DESC"
    );

    $statement->execute($parameters);

    $allMatches = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    // Count and filter the authorized records.
    foreach ($allMatches as $match) {
        $status = strtolower(
            trim((string) $match['match_status'])
        );

        $isScheduled = in_array(
            $status,
            ['scheduled', 'upcoming'],
            true
        );

        $isCompleted = in_array(
            $status,
            ['completed', 'finished'],
            true
        );

        $counts['all']++;

        if ($isScheduled) {
            $counts['scheduled']++;
        }

        if ($isCompleted) {
            $counts['completed']++;
        }

        if (
            $filter === 'all' ||
            ($filter === 'scheduled' && $isScheduled) ||
            ($filter === 'completed' && $isCompleted)
        ) {
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

<main class="matches-page">

    <section class="section-container matches-section">

        <div class="matches-header">

            <p class="section-label">
                NSBM SPORTSHUB / MATCH MANAGEMENT
            </p>

            <h1>Manage matches.</h1>

            <p class="section-description">
                Manage match fixtures, review scheduled
                events and record tournament results.
            </p>

        </div>

        <div class="match-management-actions">

            <a
                href="/matches/create.php"
                class="button button-accent">
                Create match fixture
            </a>

            <a
                href="/matches/index.php"
                class="button button-outline">
                View public fixtures
            </a>

            <a
                href="/matches/standings.php"
                class="button button-outline">
                View standings
            </a>

        </div>

        <?php if ($loadError): ?>

            <div class="matches-empty">

                <h2>Unable to load matches.</h2>

                <p>
                    Match management information is
                    temporarily unavailable.
                </p>

            </div>

        <?php else: ?>

            <div class="match-management-summary">

                <div class="match-management-stat">
                    <span>Total matches</span>
                    <strong>
                        <?= number_format($counts['all']) ?>
                    </strong>
                </div>

                <div class="match-management-stat">
                    <span>Scheduled matches</span>
                    <strong>
                        <?= number_format($counts['scheduled']) ?>
                    </strong>
                </div>

                <div class="match-management-stat">
                    <span>Completed matches</span>
                    <strong>
                        <?= number_format($counts['completed']) ?>
                    </strong>
                </div>

            </div>

            <nav
                class="matches-tabs"
                aria-label="Match management filters">

                <a
                    href="?filter=all"
                    class="<?= $filter === 'all' ? 'active' : '' ?>"
                    <?= $filter === 'all' ? 'aria-current="page"' : '' ?>>
                    All matches
                </a>

                <a
                    href="?filter=scheduled"
                    class="<?= $filter === 'scheduled' ? 'active' : '' ?>"
                    <?= $filter === 'scheduled' ? 'aria-current="page"' : '' ?>>
                    Scheduled
                </a>

                <a
                    href="?filter=completed"
                    class="<?= $filter === 'completed' ? 'active' : '' ?>"
                    <?= $filter === 'completed' ? 'aria-current="page"' : '' ?>>
                    Completed
                </a>

            </nav>

            <?php if ($matches === []): ?>

                <div class="matches-empty">

                    <h2>No matches found.</h2>

                    <p>
                        No matches are available for the
                        selected filter and your assigned clubs.
                    </p>

                    <a
                        href="/matches/create.php"
                        class="button button-accent">
                        Create a fixture
                    </a>

                </div>

            <?php else: ?>

                <div class="matches-list">

                    <?php foreach ($matches as $match): ?>

                        <?php
                        $status = strtolower(
                            trim((string) $match['match_status'])
                        );

                        $completed = in_array(
                            $status,
                            ['completed', 'finished'],
                            true
                        );

                        $scoreLabel = $completed
                            ? (int) $match['home_score']
                            . ' - ' .
                            (int) $match['away_score']
                            : 'VS';
                        ?>

                        <article class="match-card">

                            <div class="match-card-meta">

                                <span>
                                    <?= matchManageEscape($match['sport']) ?>
                                </span>

                                <span>
                                    <?= matchManageEscape(
                                        ucfirst($status)
                                    ) ?>
                                </span>

                            </div>

                            <h2>
                                <?= matchManageEscape($match['title']) ?>
                            </h2>

                            <p class="match-management-tournament">
                                <?= matchManageEscape($match['t_name']) ?>
                            </p>

                            <div class="match-card-teams">

                                <span>
                                    <?= matchManageEscape(
                                        $match['home_team']
                                    ) ?>
                                </span>

                                <strong>
                                    <?= matchManageEscape($scoreLabel) ?>
                                </strong>

                                <span>
                                    <?= matchManageEscape(
                                        $match['away_team']
                                    ) ?>
                                </span>

                            </div>

                            <div class="match-card-details">

                                <span>
                                    <?= matchManageEscape(
                                        matchManageDate($match['date'])
                                    ) ?>
                                </span>

                                <span>
                                    <?= matchManageEscape($match['venue']) ?>
                                </span>

                            </div>

                            <div class="match-management-card-actions">

                                <a
                                    href="/matches/view.php?id=<?= (int) $match['m_id'] ?>"
                                    class="button button-outline">
                                    View details
                                </a>

                                <a
                                    href="/matches/edit.php?id=<?= (int) $match['m_id'] ?>"
                                    class="button button-accent">
                                    Manage fixture
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>