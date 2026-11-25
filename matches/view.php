<?php

require_once __DIR__ . '/../includes/db.php';


require_once __DIR__ . '/../includes/helpers.php';

// Validate the match ID supplied through the URL.
$matchId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$match = null;
$errorMessage = '';

// Retrieve the selected match from the database.
if ($matchId === null || $matchId === false) {
    http_response_code(400);
    $errorMessage = 'A valid match ID is required.';
} else {
    try {
        $sql = "SELECT
                    m_id,
                    t_id,
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
                WHERE m_id = :match_id
                LIMIT 1";

        $statement = db()->prepare($sql);

        $statement->execute([
            'match_id' => $matchId
        ]);

        $match = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            http_response_code(404);
            $errorMessage = 'The requested match could not be found.';
        }
    } catch (Throwable $error) {
        error_log('Match details query: ' . $error->getMessage());

        http_response_code(500);
        $errorMessage = 'Match information is temporarily unavailable.';
    }
}

/* Source links for verified historical results (no extra database fields). */
$officialMatchSources = [
    'NSBM vs University of Colombo - Volleyball Friendly' =>
        'https://www.nsbm.ac.lk/nsbm-and-university-of-colombo-friendly-volleyball-clash-2025/',
    'NSBM vs Peradeniya - Mens Basketball Friendly' =>
        'https://www.nsbm.ac.lk/thrilling-basketball-showdown-of-nsbm-vs-university-of-peradeniya/',
    'NSBM vs Peradeniya - Womens Basketball Friendly' =>
        'https://www.nsbm.ac.lk/thrilling-basketball-showdown-of-nsbm-vs-university-of-peradeniya/',
    'NSBM vs Sabaragamuwa University - Sabra Super 7s Final 2025' =>
        'https://www.nsbm.ac.lk/nsbm-rugby-team-emerges-as-champions-of-sabra-super-7s-rugby-tournament-2025/',
];
$officialMatchSource = $match
    ? ($officialMatchSources[(string) $match['title']] ?? null)
    : null;

$pageStylesheets = ['/assets/css/matches.css'];

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page match-profile-page">
    <?php if (!$match): ?>
        <div class="shell match-profile-error">
            <a class="match-profile-back" href="/matches/index.php">&larr; Back to fixtures</a>
            <div class="match-profile-notice" role="alert">
                <h1>Match details unavailable</h1>
                <p><?= sportsHubEscape($errorMessage) ?></p>
            </div>
        </div>
    <?php else: ?>
        <?php
        // Select the match cover photograph using the associated sport.
        $detailSportImages = [
            'volleyball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/06/NSBM-University-of-Colombo-Friendly-Volleyball-Clash-2025.jpg',
            'basketball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/06/Thrilling-Basketball-Showdown-of-NSBM-vs-University-of-Peradeniya-1200x600.jpg',
            'rugby' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/03/NSBM-Rugby-Team-Emerges-as-Champions-of-Sabra-Super-7s-Rugby-Tournament-2025-6-1037x600.jpg',
        ];
        $detailImage = $match ? ($detailSportImages[strtolower(trim((string)$match['sport']))] ?? '/assets/images/hero-main.webp') : '';

        $detailImage = sportsHubCustomCover($match['image_path']) ?: $detailImage;
        $status = strtolower(trim((string) $match['match_status']));
        $isCompleted = in_array($status, ['completed', 'finished'], true);
        $hasScore = $isCompleted && $match['home_score'] !== null && $match['away_score'] !== null;
        $isVolleyball = strcasecmp((string) $match['sport'], 'Volleyball') === 0;
        $matchDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $match['date']);
        $venue = trim((string) $match['venue']);
        ?>

        <section class="match-profile-cover" aria-labelledby="match-profile-title">
            <div class="match-profile-image" aria-hidden="true">
                <img src="<?= sportsHubEscape($detailImage) ?>" alt="" loading="eager" decoding="async">
            </div>
            <div class="shell match-profile-heading">
                <a class="match-profile-back" href="/matches/index.php">&larr; Back to fixtures</a>
                <h1 id="match-profile-title"><?= sportsHubEscape($match['title']) ?></h1>
                <p><?= sportsHubEscape($match['sport']) ?> &middot; <?= sportsHubEscape($match['home_name']) ?> vs <?= sportsHubEscape($match['away_name']) ?></p>
            </div>
        </section>

        <div class="shell match-profile-content">
            <section class="match-profile-result" aria-labelledby="match-result-title">
                <div class="match-profile-section-heading">
                    <div>
                        <p class="match-profile-sport"><?= sportsHubEscape($match['sport']) ?></p>
                        <h2 id="match-result-title"><?= $isCompleted ? 'Final score' : 'Match details' ?></h2>
                    </div>
                    <span class="match-profile-status"><?= sportsHubEscape(ucfirst($status)) ?></span>
                </div>

                <div class="match-profile-scoreboard">
                    <div class="match-profile-team match-profile-home">
                        <span class="match-profile-team-label">Home</span>
                        <strong><?= sportsHubEscape($match['home_name']) ?></strong>
                        <?php if ($hasScore && sportsHubIsCricket((string) $match['sport']) && $match['home_balls'] !== null): ?><span class="match-profile-score-caption"><?= sportsHubEscape(sportsHubOversText($match['home_balls'])) ?> overs</span><?php endif; ?>
                    </div>
                    <div class="match-profile-score">
                        <?php if ($hasScore): ?>
                            <strong class="match-profile-score-value<?= sportsHubIsCricket((string) $match['sport']) ? ' match-profile-cricket-score' : '' ?>">
                                <span><?= sportsHubEscape(sportsHubMatchScore($match, 'home')) ?></span>
                                <span class="match-profile-score-separator">&ndash;</span>
                                <span><?= sportsHubEscape(sportsHubMatchScore($match, 'away')) ?></span>
                            </strong>
                            <span class="match-profile-score-caption"><?= $isVolleyball ? 'Sets' : (sportsHubIsCricket((string) $match['sport']) ? 'Runs' : 'Full time') ?></span>
                        <?php elseif ($isCompleted): ?>
                            <span class="match-profile-score-caption">Score not available</span>
                        <?php else: ?>
                            <strong class="match-profile-versus">VS</strong>
                        <?php endif; ?>
                    </div>
                    <div class="match-profile-team match-profile-away">
                        <span class="match-profile-team-label">Away</span>
                        <strong><?= sportsHubEscape($match['away_name']) ?></strong>
                        <?php if ($hasScore && sportsHubIsCricket((string) $match['sport']) && $match['away_balls'] !== null): ?><span class="match-profile-score-caption"><?= sportsHubEscape(sportsHubOversText($match['away_balls'])) ?> overs</span><?php endif; ?>
                    </div>
                </div>

                <?php if (trim((string) $match['description']) !== ''): ?>
                    <p class="match-profile-description"><?= sportsHubEscape($match['description']) ?></p>
                <?php endif; ?>
                <dl class="match-profile-facts">
                    <div>
                        <dt>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                            Date
                        </dt>
                        <dd><?= sportsHubEscape($matchDate !== false ? $matchDate->format('d M Y') : 'Not published') ?></dd>
                    </div>
                    <div>
                        <dt>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 10-8 10S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            Venue
                        </dt>
                        <dd><?= sportsHubEscape($venue !== '' ? $venue : 'Not published') ?></dd>
                    </div>
                </dl>
            </section>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
