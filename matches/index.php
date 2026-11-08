<?php

require_once __DIR__ . '/../includes/db.php';

function matchEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Determine which match category to display.
$allowedTabs = ['upcoming', 'results', 'all'];
$activeTab = $_GET['tab'] ?? 'upcoming';

if (!is_string($activeTab) || !in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'upcoming';
}

$matches = [];
$loadError = false;

// Retrieve match information from the existing database.
try {
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
            $condition = "WHERE date >= CURDATE()
                          AND LOWER(match_status) IN ('scheduled', 'upcoming')";
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
                away_score
            FROM matches
            $condition
            $order";

    $matches = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('Matches page query: ' . $error->getMessage());
    $loadError = true;
}

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page">

    <section class="section-container matches-section">

        <div class="matches-header">

            <p class="section-label">
                NSBM SPORTSHUB / MATCHES
            </p>

            <h1>Match fixtures &amp; results.</h1>

            <p class="section-description">
                Follow upcoming fixtures, explore match results
                and stay connected with campus sports.
            </p>

        </div>

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
                    $status = strtolower($match['match_status']);
                    $isCompleted = in_array(
                        $status,
                        ['completed', 'finished'],
                        true
                    );
                    ?>

                    <article class="match-card">

                        <div class="match-card-meta">

                            <span>
                                <?php echo matchEscape($match['sport']); ?>
                            </span>

                            <span>
                                <?php echo matchEscape($match['match_status']); ?>
                            </span>

                        </div>

                        <h2>
                            <?php echo matchEscape($match['title']); ?>
                        </h2>

                        <div class="match-card-teams">

                            <span>
                                <?php echo matchEscape($match['home_name']); ?>
                            </span>

                            <?php if ($isCompleted): ?>

                                <strong>
                                    <?php echo (int) $match['home_score']; ?>
                                    -
                                    <?php echo (int) $match['away_score']; ?>
                                </strong>

                            <?php else: ?>

                                <strong>VS</strong>

                            <?php endif; ?>

                            <span>
                                <?php echo matchEscape($match['away_name']); ?>
                            </span>

                        </div>

                        <div class="match-card-details">

                            <span>
                                <?php echo date(
                                    'd M Y',
                                    strtotime($match['date'])
                                ); ?>
                            </span>

                            <span>
                                <?php echo matchEscape($match['venue']); ?>
                            </span>

                        </div>

                        <a
                            href="/matches/view.php?id=<?php echo (int) $match['m_id']; ?>"
                            class="text-link"
                            style="display: inline-block; margin-top: 24px;">
                            View match details ↗
                        </a>


                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>