<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

// Show management navigation only to authorized staff.
$canManageMatches = in_array(
    current_user_role(),
    ['admin', 'coach', 'mic'],
    true
);


// Determine which match category to display.
$allowedTabs = ['upcoming', 'results', 'all'];
$activeTab = $_GET['tab'] ?? 'all';

if (!is_string($activeTab) || !in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'all';
}

$matches = [];
$loadError = false;

// Retrieve match information from the existing database.
try {
    $queryParams = [];

    switch ($activeTab) {
        case 'results':
            $condition = "WHERE LOWER(match_status) IN ('completed', 'finished')";
            $order = "ORDER BY date DESC, m_id DESC";
            break;

        case 'all':
            $condition = "";
            $order = "ORDER BY date DESC, m_id DESC";
            break;

        default:
            $condition = "WHERE date >= :today
                          AND LOWER(match_status) IN ('scheduled', 'upcoming')";

            $queryParams['today'] = (
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone('Asia/Colombo')
                )
            )->format('Y-m-d');
            $order = "ORDER BY date ASC, m_id ASC";
            break;
    }

    $sql = "SELECT
                m_id,
                title,
                home_name,
                away_name,
                sport,
                date,
                venue,
                match_status,
                home_score,
                description, image_path, home_wickets, away_wickets, home_balls, away_balls,
                away_score
            FROM matches
            $condition
            $order";

    $statement = db()->prepare($sql);
    $statement->execute($queryParams);
    $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('Matches page query: ' . $error->getMessage());
    $loadError = true;
}

$pageStylesheets = ['/assets/css/matches.css'];

$pageTitle = 'Fixtures';

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page matches-directory-page">

    <section class="section-container matches-section">

        <div class="matches-header">

            <h1>Match fixtures &amp; results.</h1>

            <p class="section-description">
                Follow upcoming fixtures, explore match results
                and stay connected with campus sports.
            </p>

        </div>

        <?php if ($canManageMatches): ?>

            <div class="match-management-actions">

                <a
                    href="/matches/manage.php"
                    class="button button-accent">
                    Manage Matches
                </a>

            </div>

        <?php endif; ?>

        <!-- Match category navigation -->
        <nav class="matches-tabs" aria-label="Match categories">

            <a
                href="?tab=upcoming"
                class="<?php echo $activeTab === 'upcoming' ? 'active' : ''; ?>"
                <?php echo $activeTab === 'upcoming' ? 'aria-current="page"' : ''; ?>>
                Upcoming
            </a>

            <a
                href="?tab=results"
                class="<?php echo $activeTab === 'results' ? 'active' : ''; ?>"
                <?php echo $activeTab === 'results' ? 'aria-current="page"' : ''; ?>>
                Results
            </a>

            <a
                href="?tab=all"
                class="<?php echo $activeTab === 'all' ? 'active' : ''; ?>"
                <?php echo $activeTab === 'all' ? 'aria-current="page"' : ''; ?>>
                All matches
            </a>

            <a href="/matches/standings.php">
                Standings
            </a>

        </nav>

        <!-- Match records -->
    <?php
    // Event photographs, never the club cover photos. For new fixtures the photo
    // is illustrative of the sport, not a claim about the particular match.
    $matchSportPhotos = [
        'Volleyball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/06/NSBM-University-of-Colombo-Friendly-Volleyball-Clash-2025.jpg',
        'Basketball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/06/Thrilling-Basketball-Showdown-of-NSBM-vs-University-of-Peradeniya-1200x600.jpg',
        'Rugby' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/03/NSBM-Rugby-Team-Emerges-as-Champions-of-Sabra-Super-7s-Rugby-Tournament-2025-6-1037x600.jpg',
        'Cricket' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-cricket-kdu-t20-champions-2026-1200x600.jpg',
        'Football' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2024/05/Vice-Chancellor-Challenge-Trophy-Football2-1037x600.jpg',
        'Athletics' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/09/NSBM-Athletic-Club-Wins-Big-at-SLIIT-Momentum-2025-1-1037x600.jpg',
    ];
    ?>
        <div class="matches-list">

            <?php if ($loadError): ?>

                <div class="matches-empty">
                    <h2>Unable to load matches.</h2>
                    <p>
                        Match information is temporarily unavailable.
                    </p>
                </div>

            <?php elseif (count($matches) === 0): ?>

                <div class="matches-empty">

                    <h2>No matches available yet.</h2>

                    <p>
                        Match information will appear here
                        when fixtures and results are published.
                    </p>

                </div>

            <?php else: ?>

                <?php foreach ($matches as $match): ?>
                    <?php
                    $status = strtolower(trim((string) ($match['match_status'] ?? '')));
                    $isCompleted = in_array($status, ['completed', 'finished'], true);
                    $matchSport = (string) ($match['sport'] ?? 'Sports');
                    $matchPhoto = sportsHubCustomCover($match['image_path']) ?: ($matchSportPhotos[$matchSport] ?? '');
                    $matchHref = '/matches/view.php?id=' . (int) $match['m_id'];
                    ?>
                    <article class="match-card">
                        <div class="match-card-media">
                            <?php if ($matchPhoto !== ''): ?>
                                <img src="<?= sportsHubEscape($matchPhoto) ?>" alt="NSBM sports event photo" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="match-photo-placeholder" aria-hidden="true"><?= sportsHubEscape($matchSport) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="match-card-inner">
                            <span class="match-card-sport"><?= sportsHubEscape($matchSport) ?></span>
                            <h2><a href="<?= sportsHubEscape($matchHref) ?>"><?= sportsHubEscape($match['title']) ?></a></h2>
                            <div class="match-card-teams">
                                <span><?= sportsHubEscape($match['home_name']) ?></span>
                                <?php if ($isCompleted): ?>
                                    <strong><?= sportsHubEscape(sportsHubMatchScore($match, 'home')) ?>–<?= sportsHubEscape(sportsHubMatchScore($match, 'away')) ?>
                                        <?php if (strcasecmp($matchSport, 'Volleyball') === 0): ?><small class="match-score-unit">sets</small><?php endif; ?>
                                    </strong>
                                <?php else: ?>
                                    <strong>VS</strong>
                                <?php endif; ?>
                                <span><?= sportsHubEscape($match['away_name']) ?></span>
                            </div>
                            <div class="match-card-footer">

                                <div class="match-card-details">
                                <span><?= date('d M Y', strtotime((string) $match['date'])) ?></span>
                                <span><?= sportsHubEscape($match['venue']) ?></span>
                            </div>

                                <a class="match-card-footer-link" href="<?= sportsHubEscape($matchHref) ?>" aria-label="View match details">View match</a>

                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>