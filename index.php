<?php

require_once __DIR__ . '/includes/db.php';


require_once __DIR__ . '/includes/helpers.php';


$clubs = [];
$spotlightStories = [];
$upcomingTournaments = [];
$upcomingFixtures = [];

// Use active clubs from MySQL when the club catalogue has been populated.
try {
    $sql = "SELECT club_id, club_name, sport, description
            FROM sports_clubs
            WHERE status = 'active'
            ORDER BY club_name";

    $records = db()->query($sql)->fetchAll();

    if (count($records) > 0) {
        foreach ($records as $record) {
            $clubs[] = [
                'sport' => $record['sport'],
                'name' => $record['club_name'],
                'description' => $record['description'],
                'club_id' => (int) $record['club_id'],
                'image' => sportsHubClubCover($record['sport']),
            ];
        }
    }
} catch (Throwable $error) {
    error_log('Homepage club query: ' . $error->getMessage());
}

// Select the optional news image column only when the database schema provides it.
$phase14NewsImageSelect = 'NULL AS image_path,';
try {
    if (db()->query("SHOW COLUMNS FROM club_news LIKE 'image_path'")->fetch(PDO::FETCH_ASSOC)) {
        $phase14NewsImageSelect = 'n.image_path,';
    }
} catch (Throwable $error) {
    error_log('Club news image-column check: ' . $error->getMessage());
}
// Retrieve the latest five published articles.
try {
    $sql = "SELECT n.title, n.summary, n.published_at, {$phase14NewsImageSelect}
                   c.club_name, c.sport, c.club_id
            FROM club_news n
            JOIN sports_clubs c ON n.club_id = c.club_id
            WHERE n.status = 'published'
              AND c.status = 'active'
            ORDER BY n.published_at DESC, n.news_id DESC
            LIMIT 5";

    $spotlightStories = db()->query($sql)->fetchAll();
} catch (Throwable $error) {
    error_log('Homepage news query: ' . $error->getMessage());
}

// Show the nearest upcoming tournaments and fixtures.
try {
    $sql = "SELECT t.t_name, t.sport, t.start_date, t.venue
            FROM tournaments t JOIN sports_clubs sc ON sc.club_id=t.club_id
            WHERE t.start_date >= CURDATE() AND t.is_historical = 0
              AND sc.status = 'active' AND LOWER(TRIM(t.status)) = 'upcoming'
            ORDER BY t.start_date ASC, t.t_id ASC
            LIMIT 3";

    $upcomingTournaments = db()->query($sql)->fetchAll();
} catch (Throwable $error) {
    error_log('Homepage tournament query: ' . $error->getMessage());
}

try {
    $sql = "SELECT title, home_name, away_name, sport, date, venue
            FROM matches
            WHERE date >= CURDATE()
              AND LOWER(match_status) IN ('scheduled', 'upcoming')
            ORDER BY date ASC
            LIMIT 3";

    $upcomingFixtures = db()->query($sql)->fetchAll();
} catch (Throwable $error) {
    error_log('Homepage fixtures query: ' . $error->getMessage());
}

// Map news entries to their published article cover images.
$officialNewsMedia = sportsHubOfficialNews();

$preloadHomepageHero = true;
include __DIR__ . '/includes/header.php';
?>

<main class="homepage">
    <!-- Homepage hero -->
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-section-header">
            <h1 id="hero-title">
                <span class="hero-title-accent">Your Game.</span>
                <span class="hero-title-secondary">Your Community.</span>
            </h1>

            <p class="hero-section-subtitle">
                Find your sport. Meet your team. Make every game count.
            </p>
        </div>

        <div class="hero-section-visual">
            <img
                src="/assets/images/herobanner.webp"
                data-hero-theme="dark"
                alt=""
                width="1888"
                height="833"
                fetchpriority="high">
            <img
                src="/assets/images/herobannerlight.webp"
                data-hero-theme="light"
                alt=""
                width="1888"
                height="833"
                fetchpriority="high">
        </div>

        <div class="hero-section-cta">
            <p>Discover clubs, upcoming competitions and campus sporting opportunities.</p>

            <div class="hero-section-actions">
                <a class="button button-accent" href="#discover">Explore sports</a>
                <a class="button button-outline" href="<?= sportsHubEscape($headerLoggedIn ? $headerAccountUrl : '/register.php') ?>"><?= sportsHubEscape($headerLoggedIn ? $headerAccountLabel : 'Join SportsHub') ?></a>
            </div>
        </div>
    </section>

    <!-- Sports club gallery -->
    <section class="club-showcase" id="discover" aria-labelledby="discover-title">
        <div class="section-container section-header club-showcase-header">
            <div>
                <h2 id="discover-title">Find your kind of game.</h2>
                <p class="section-description">From your first training to the next big fixture.</p>
            </div>

            <a class="section-link" href="/clubs/index.php">
                All sports clubs <span aria-hidden="true">↗</span>
            </a>
        </div>

        <div
            class="club-showcase-track"
            aria-label="Sports clubs; scroll horizontally to see more"
            tabindex="0">
            <?php if (!$clubs): ?><p class="section-description">No active sports clubs are currently available.</p><?php endif; ?>
            <?php foreach ($clubs as $club): ?>
                <?php
                $clubUrl = isset($club['club_id'])
                    ? '/clubs/view.php?id=' . (int) $club['club_id']
                    : '/clubs/index.php';
                ?>

                <article class="club-showcase-item">
                    <a
                        class="club-showcase-media"
                        href="<?php echo sportsHubEscape($clubUrl); ?>"
                        aria-label="More information about <?php echo sportsHubEscape($club['name']); ?>">
                        <?php if (!empty($club['image'])): ?>
                            <img
                                src="<?php echo sportsHubEscape($club['image']); ?>"
                                alt="<?php echo sportsHubEscape($club['sport']); ?> sports activity"
                                loading="lazy">
                        <?php else: ?>
                            <div class="club-showcase-placeholder">
                                <span><?php echo sportsHubEscape($club['sport']); ?></span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <div class="club-showcase-details">
                        <h3><?php echo sportsHubEscape($club['name']); ?></h3>
                        <p><?php echo sportsHubEscape($club['description']); ?></p>
                        <a class="text-link" href="<?php echo sportsHubEscape($clubUrl); ?>">
                            More info <span aria-hidden="true">↗</span>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Club news and highlights -->
    <section class="club-spotlight" id="club-spotlight">
        <div class="club-spotlight-header">
            <div>
                <h2>Club news.</h2>
                <p>
                    Stories, achievements and moments
                    from our sports community.
                </p>
            </div>

            <a href="/clubs/index.php" class="club-spotlight-all-link">Explore clubs ↗</a>
        </div>

        <?php if (count($spotlightStories) > 0): ?>
            <div
                class="club-spotlight-track"
                id="spotlight-track"
                aria-label="Club news stories; scroll horizontally to see more" tabindex="0">
                <?php foreach ($spotlightStories as $story): ?>
                    <?php
                    // Prefer article-specific media over general club gallery photographs.
                    $storyMedia = null;
                    foreach ($officialNewsMedia as $article) {
                        if (
                            (string) ($story['title'] ?? '') === $article['title'] ||
                            str_contains((string) ($story['summary'] ?? ''), $article['source'])
                        ) {
                            $storyMedia = $article;
                            break;
                        }
                    }
                    $newsImage = sportsHubNewsCover($story, $storyMedia['image'] ?? '');
                    $newsSource = $storyMedia['source'] ?? '';
                    // Keep source attribution as a link; remove it from the visible summary.
                    $newsSummary = preg_split(
                        '/\s*Originally reported by NSBM on\s+\d{4}-\d{2}-\d{2}\.\s*Official source:\s*https?:\/\/\S+/iu',
                        (string) ($story['summary'] ?? ''), 2
                    )[0];
                    ?>
                    <article class="club-spotlight-slide">
                        <div class="club-spotlight-content">
                            <p class="club-spotlight-category"><?= sportsHubEscape($story['club_name']) ?></p>
                            <h3><?= sportsHubEscape($story['title']) ?></h3>
                            <p class="club-spotlight-summary"><?= sportsHubEscape(trim($newsSummary)) ?></p>
                            <div class="club-spotlight-actions">
                                <a class="club-spotlight-link" href="/clubs/view.php?id=<?= (int) $story['club_id'] ?>">View club profile ↗</a>
                                <?php if ($newsSource !== ''): ?>
                                    <a class="club-spotlight-source" href="<?= sportsHubEscape($newsSource) ?>" target="_blank" rel="noopener noreferrer">Read original</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="club-spotlight-media">
                            <?php if ($newsImage !== ''): ?>
                                <img src="<?= sportsHubEscape($newsImage) ?>"
                                     alt="Photo from <?= sportsHubEscape($story['title']) ?>"
                                     loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="club-spotlight-placeholder" aria-label="No story photo available">
                                    <span><?= sportsHubEscape($story['club_name']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="club-spotlight-empty">
                <h3>Every club has a story.</h3>
                <p>
                    Club news and achievements will appear
                    here when published.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Upcoming tournaments and match fixtures -->
    <section class="coming-section section-container" id="coming-up" aria-labelledby="coming-title">
        <div class="section-header">
            <div>
                <h2 id="coming-title">What's Next</h2>
                <p class="section-description">Upcoming tournaments and the next fixtures on campus.</p>
            </div>
        </div>

        <div class="coming-grid">
            <div class="coming-panel">
                <div class="coming-panel-header">
                    <p class="section-label">TOURNAMENTS</p>
                    <h3>Next competitions.</h3>
                </div>

                <?php if (count($upcomingTournaments) > 0): ?>
                    <?php foreach ($upcomingTournaments as $tournament): ?>
                        <div class="coming-item">
                            <div class="coming-date" aria-hidden="true">
                                <strong><?php echo date('d', strtotime($tournament['start_date'])); ?></strong>
                                <span><?php echo date('M', strtotime($tournament['start_date'])); ?></span>
                            </div>
                            <div class="coming-info">
                                <span class="coming-type"><?php echo sportsHubEscape($tournament['sport']); ?></span>
                                <h4><?php echo sportsHubEscape($tournament['t_name']); ?></h4>
                                <p><?php echo sportsHubEscape($tournament['venue']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="coming-empty">No upcoming tournaments announced yet.
                        <a href="/tournaments/index.php?tab=past">Explore past NSBM tournaments</a>
                    </p>
                <?php endif; ?>

                <a class="text-link coming-all" href="/tournaments/index.php">
                    All tournaments <span aria-hidden="true">↗</span>
                </a>
            </div>

            <div class="coming-panel">
                <div class="coming-panel-header">
                    <p class="section-label">FIXTURES</p>
                    <h3>Next matchdays.</h3>
                </div>

                <?php if (count($upcomingFixtures) > 0): ?>
                    <?php foreach ($upcomingFixtures as $match): ?>
                        <?php
                        $matchTitle = trim(($match['home_name'] ?? '') . ' vs ' . ($match['away_name'] ?? ''));

                        if (empty($match['home_name']) || empty($match['away_name'])) {
                            $matchTitle = $match['title'];
                        }
                        ?>

                        <div class="coming-item">
                            <div class="coming-date" aria-hidden="true">
                                <strong><?php echo date('d', strtotime($match['date'])); ?></strong>
                                <span><?php echo date('M', strtotime($match['date'])); ?></span>
                            </div>
                            <div class="coming-info">
                                <span class="coming-type"><?php echo sportsHubEscape($match['sport']); ?></span>
                                <h4><?php echo sportsHubEscape($matchTitle); ?></h4>
                                <p><?php echo sportsHubEscape($match['venue']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="coming-empty">No upcoming matches have been scheduled yet.
                        <a href="/matches/index.php?tab=results">See completed match results</a>
                    </p>
                <?php endif; ?>

                <a class="text-link coming-all" href="/matches/index.php">
                    All fixtures <span aria-hidden="true">↗</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Sports discovery closing section -->
    <section class="closing-section" aria-labelledby="closing-title">
        <div class="closing-content">
            <p class="closing-label">NSBM SPORTSHUB</p>

            <h2 id="closing-title">
                <span class="closing-title-accent">The game doesn't stop here.</span>
            </h2>

            <p class="closing-description">
                Discover campus sports, explore clubs and stay connected
                with upcoming competitions.
            </p>

            <div class="closing-actions">
                <a href="/clubs/index.php" class="closing-register">Explore sports clubs</a>
                <a href="/matches/index.php" class="closing-login">View fixtures</a>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>