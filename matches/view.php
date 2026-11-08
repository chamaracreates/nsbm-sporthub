<?php

require_once __DIR__ . '/../includes/db.php';

function matchDetailEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

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

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page">

    <section class="section-container matches-section">

        <div class="matches-header">

            <p class="section-label">
                NSBM SPORTSHUB / MATCH DETAILS
            </p>

            <h1>
                <?php if ($match): ?>
                    <?php echo matchDetailEscape($match['title']); ?>
                <?php else: ?>
                    Match details.
                <?php endif; ?>
            </h1>

            <p class="section-description">
                Explore match information, participating teams
                and results from campus sporting events.
            </p>

        </div>

        <?php if (!$match): ?>

            <!-- Invalid or unavailable match -->
            <div class="matches-empty">

                <h2>Match unavailable.</h2>

                <p>
                    <?php echo matchDetailEscape($errorMessage); ?>
                </p>

                <a
                    href="/matches/index.php"
                    class="button button-outline"
                    style="margin-top: 24px;">
                    Back to matches
                </a>

            </div>

        <?php else: ?>

            <?php
            $status = strtolower(trim($match['match_status']));

            $isCompleted = in_array(
                $status,
                ['completed', 'finished'],
                true
            );
            ?>

            <!-- Selected match information -->
            <article class="match-card">

                <div class="match-card-meta">

                    <span>
                        <?php echo matchDetailEscape($match['sport']); ?>
                    </span>

                    <span>
                        <?php echo matchDetailEscape($match['match_status']); ?>
                    </span>

                </div>

                <h2>
                    <?php echo matchDetailEscape($match['title']); ?>
                </h2>

                <!-- Participating teams and result -->
                <div class="match-card-teams">

                    <span>
                        <?php echo matchDetailEscape($match['home_name']); ?>
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
                        <?php echo matchDetailEscape($match['away_name']); ?>
                    </span>

                </div>

                <!-- Match scheduling information -->
                <div class="match-card-details">

                    <span>
                        <?php echo date(
                            'd M Y',
                            strtotime($match['date'])
                        ); ?>
                    </span>

                    <span>
                        <?php echo matchDetailEscape($match['venue']); ?>
                    </span>

                </div>

            </article>

            <div style="margin-top: 30px;">

                <a
                    href="/matches/index.php"
                    class="button button-outline">
                    Back to matches
                </a>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>