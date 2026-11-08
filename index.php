<?php

require_once __DIR__ . '/includes/db.php';

function homeEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Reference content used to preview the club gallery before clubs are added.
$referenceClubs = [
    ['sport' => 'Cricket',       'name' => 'Cricket Club',       'description' => 'Play for the team, strengthen your technique and take on friendly fixtures.', 'image' => '/assets/images/cricket.jpg'],
    ['sport' => 'Badminton',     'name' => 'Badminton Club',     'description' => 'Bring energy to every rally with singles, doubles and club practice.', 'image' => '/assets/images/badminton.jpg'],
    ['sport' => 'Rugby',         'name' => 'Rugby Club',         'description' => 'Play with discipline, courage and camaraderie on and off the pitch.', 'image' => '/assets/images/rugby.jpg'],
    ['sport' => 'Athletics',     'name' => 'Athletics Club',     'description' => 'From sprints to field events, challenge yourself and run together.', 'image' => '/assets/images/athletics.jpg'],
    ['sport' => 'Chess',         'name' => 'Chess Club',         'description' => 'Build strategic thinking through friendly games and tournaments.', 'image' => '/assets/images/chess.jpg'],
    ['sport' => 'Martial Arts',  'name' => 'Martial Arts Club',  'description' => 'Develop confidence, discipline and skills through martial arts.', 'image' => '/assets/images/martialarts.jpg'],
    ['sport' => 'Netball',       'name' => 'Netball Club',       'description' => 'Grow your skills and teamwork on the netball court.', 'image' =>  '/assets/images/netball.jpg'],
    ['sport' => 'Swimming',      'name' => 'Swimming Club',      'description' => 'Explore aquatic fitness, training and competitions.', 'image' => '/assets/images/swimming.jpg'],
    ['sport' => 'Table Tennis',  'name' => 'Table Tennis Club',  'description' => 'Develop quick reflexes through singles and doubles.', 'image' => '/assets/images/tabletennis.jpg'],
    ['sport' => 'Tennis',        'name' => 'Tennis Club',        'description' => 'Learn and improve your tennis skills through regular practice.', 'image' => '/assets/images/tennis.jpg'],
    ['sport' => 'Volleyball',    'name' => 'Volleyball Club',    'description' => 'Build team spirit and confidence through volleyball.', 'image' => '/assets/images/volleyball.jpg'],
];

$clubs = $referenceClubs;
$usingPreviewClubs = true;
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
        $clubs = [];
        $usingPreviewClubs = false;

        foreach ($records as $record) {
            $photo = '';

            foreach ($referenceClubs as $reference) {
                if (strcasecmp($reference['sport'], $record['sport']) === 0) {
                    $photo = $reference['image'];
                    break;
                }
            }

            $clubs[] = [
                'sport' => $record['sport'],
                'name' => $record['club_name'],
                'description' => $record['description'],
                'image' => $photo,
            ];
        }
    }
} catch (Throwable $error) {
    error_log('Homepage club query: ' . $error->getMessage());
}

// Show the three latest published club news records.
try {
    $sql = "SELECT n.title, n.summary, n.published_at,
                   c.club_name, c.sport
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
    $sql = "SELECT t_name, sport, start_date, venue
            FROM tournaments
            WHERE start_date >= CURDATE()
              AND LOWER(status) NOT IN ('cancelled', 'completed', 'draft')
            ORDER BY start_date ASC
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

include __DIR__ . '/includes/header.php';
?>

<main class="homepage">

    <!-- Hero: a centered headline and a standalone sports visual. -->
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
                src="/assets/images/herobanner.png"
                alt="basket ball court"
                width="1450"
                height="640"
                fetchpriority="high">
        </div>

        <div class="hero-section-cta">
            <p>Discover clubs, upcoming competitions and campus sporting opportunities.</p>
            <div class="hero-section-actions">
                <a class="button button-accent" href="#discover">Explore sports</a>
                <a class="button button-outline" href="/register.php">Join SportsHub</a>
            </div>
        </div>
    </section>

    <!-- Discover: images stand alone with descriptions beneath each image. -->
    <section class="club-showcase" id="discover" aria-labelledby="discover-title">
        <div class="section-container section-header club-showcase-header">
            <div>
                <h2 id="discover-title">Find your kind of game.</h2>
                <p class="section-description">From your first training to the next big fixture.</p>
            </div>
            <a class="section-link" href="/clubs/index.php">All sports clubs <span aria-hidden="true">↗</span></a>
        </div>

        <div class="club-showcase-track" aria-label="Sports clubs; scroll horizontally to see more" tabindex="0">
            <?php foreach ($clubs as $club): ?>
                <?php
                $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $club['sport']), '-'));
                $markPath = '/assets/images/club-marks/' . $slug . '.svg';
                ?>
                <article class="club-showcase-item">
                    <a class="club-showcase-media" href="/clubs/view.php?sport=<?php echo urlencode($club['sport']); ?>" aria-label="More information about <?php echo homeEscape($club['name']); ?>">
                        <?php if (!empty($club['image'])): ?>
                            <img src="<?php echo homeEscape($club['image']); ?>" alt="<?php echo homeEscape($club['sport']); ?> sports activity" loading="lazy">
                        <?php else: ?>
                            <div class="club-showcase-placeholder">
                                <img src="<?php echo homeEscape($markPath); ?>" alt="" aria-hidden="true" loading="lazy">
                                <span><?php echo homeEscape($club['sport']); ?></span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <div class="club-showcase-details">
                        <h3><?php echo homeEscape($club['name']); ?></h3>
                        <p><?php echo homeEscape($club['description']); ?></p>
                        <a class="text-link" href="/clubs/view.php?sport=<?php echo urlencode($club['sport']); ?>">More info <span aria-hidden="true">↗</span></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Spotlight: published stories are retrieved from club_news. -->
    <section class="club-spotlight" id="club-spotlight">

        <div class="club-spotlight-header">
            <div>
                <h2>Club highlights.</h2>

                <p>
                    Stories, achievements and moments
                    from our sports community.
                </p>
            </div>

            <a href="/clubs/index.php"
                class="club-spotlight-all-link">
                Explore clubs ↗
            </a>
        </div>

        <?php if (count($spotlightStories) > 0): ?>

            <div class="club-spotlight-track"
                id="spotlight-track"
                aria-label="Club news stories">

                <?php foreach ($spotlightStories as $story): ?>

                    <?php
                    // Find the photograph for this club.
                    $newsImage = '';

                    foreach ($clubs as $club) {
                        if (strcasecmp(
                            $club['sport'],
                            $story['sport']
                        ) == 0) {
                            $newsImage = $club['image'] ?? '';
                            break;
                        }
                    }
                    ?>

                    <article class="club-spotlight-slide">

                        <!-- Story information -->
                        <div class="club-spotlight-content">

                            <p class="club-spotlight-category">
                                CLUB NEWS /
                                <?php echo htmlspecialchars(
                                    $story['club_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </p>

                            <h3>
                                <?php echo htmlspecialchars(
                                    $story['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </h3>

                            <p class="club-spotlight-summary">
                                <?php echo htmlspecialchars(
                                    $story['summary'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </p>

                            <a
                                href="/clubs/view.php?sport=<?php
                                                            echo urlencode($story['sport']);
                                                            ?>"
                                class="club-spotlight-link">
                                View club profile ↗
                            </a>

                        </div>

                        <!-- Story photograph -->
                        <div class="club-spotlight-media">

                            <?php if ($newsImage != ''): ?>

                                <img
                                    src="<?php echo htmlspecialchars(
                                                $newsImage,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                    alt="<?php echo htmlspecialchars(
                                                $story['club_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                    loading="lazy">

                            <?php else: ?>

                                <div class="club-spotlight-placeholder">
                                    <span>
                                        <?php echo htmlspecialchars(
                                            $story['club_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </span>
                                </div>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

            <!-- Carousel navigation -->
            <?php if (count($spotlightStories) > 1): ?>
                <div class="club-spotlight-controls">

                    <button type="button"
                        id="spotlight-prev"
                        aria-label="Previous story">
                        &#8592;
                    </button>

                    <span class="club-spotlight-counter"
                        id="spotlight-counter">
                        1 / <?php echo count($spotlightStories); ?>
                    </span>

                    <button type="button"
                        id="spotlight-next"
                        aria-label="Next story">
                        &#8594;
                    </button>

                </div>
            <?php endif; ?>

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

    <!-- Coming Up: read-only previews owned by the tournaments and matches modules. -->
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
                                <span class="coming-type"><?php echo homeEscape($tournament['sport']); ?></span>
                                <h4><?php echo homeEscape($tournament['t_name']); ?></h4>
                                <p><?php echo homeEscape($tournament['venue']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="coming-empty">No upcoming tournaments announced yet.</p>
                <?php endif; ?>

                <a class="text-link coming-all" href="/tournaments/index.php">All tournaments <span aria-hidden="true">↗</span></a>
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
                                <span class="coming-type"><?php echo homeEscape($match['sport']); ?></span>
                                <h4><?php echo homeEscape($matchTitle); ?></h4>
                                <p><?php echo homeEscape($match['venue']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="coming-empty">No upcoming matches have been scheduled yet.</p>
                <?php endif; ?>

                <a class="text-link coming-all" href="/matches/index.php">All fixtures <span aria-hidden="true">↗</span></a>
            </div>
        </div>
    </section>


    <!-- Homepage Closing Section -->
    <section class="closing-section">

        <div class="closing-content">

            <p class="closing-label">
                ONE COMMUNITY. EVERY SPORT.
            </p>

            <h2>
                <span class="closing-title-accent">
                    Your next chapter
                </span>

                <span class="closing-title-line">
                    starts here.
                </span>
            </h2>

            <p class="closing-description">
                Discover sports clubs, connect with teammates,
                and explore opportunities across campus.
                Your sporting journey begins with SportsHub.
            </p>

            <div class="closing-actions">

                <a href="/register.php" class="closing-register">
                    Register
                </a>

                <a href="/login.php" class="closing-login">
                    Log in
                </a>

            </div>

        </div>

    </section>


</main>

<?php include __DIR__ . '/includes/footer.php'; ?>