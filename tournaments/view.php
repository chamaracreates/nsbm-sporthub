<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

/* Escape values displayed in the tournament details page. */
function tournamentViewEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* Format database dates for display. */
function tournamentViewDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        ? $date->format('d M Y')
        : $value;
}

/* Validate the requested tournament. */
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
                sc.club_name
            FROM tournaments AS t
            INNER JOIN sports_clubs AS sc
                ON sc.club_id = t.club_id
            WHERE t.t_id = :tournament_id
                AND sc.status = 'active'
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

require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">

    <section class="tournaments-container section-container">

        <div class="tournament-view-navigation">
            <a
                href="/tournaments/index.php"
                class="button button-outline">
                All Tournaments
            </a>
        </div>

        <?php if ($pageError !== null): ?>

            <div class="tournaments-notice" role="alert">
                <h1>Tournament details unavailable</h1>
                <p><?= tournamentViewEscape($pageError) ?></p>
            </div>

        <?php elseif ($tournament !== null): ?>

            <header class="tournaments-heading">

                <p class="section-label">
                    NSBM SPORTSHUB / TOURNAMENT DETAILS
                </p>

                <h1>
                    <?= tournamentViewEscape(
                        $tournament['t_name']
                    ) ?>
                </h1>

                <p class="section-description">
                    <?= tournamentViewEscape(
                        $tournament['sport']
                    ) ?>
                    tournament organized by
                    <?= tournamentViewEscape(
                        $tournament['club_name']
                    ) ?>.
                </p>

            </header>

            <div class="tournament-view-layout">

                <article class="tournament-view-panel">

                    <div class="tournament-card-top">

                        <span class="tournament-sport">
                            <?= tournamentViewEscape(
                                $tournament['sport']
                            ) ?>
                        </span>

                        <span class="tournament-status">
                            <?= tournamentViewEscape(
                                $tournament['status']
                            ) ?>
                        </span>

                    </div>

                    <h2>About this tournament</h2>

                    <p class="tournament-view-description">
                        <?= nl2br(
                            tournamentViewEscape(
                                $tournament['des']
                            )
                        ) ?>
                    </p>

                    <h2>Schedule and venue</h2>

                    <dl class="tournament-view-facts">

                        <div>
                            <dt>Start date</dt>
                            <dd>
                                <?= tournamentViewEscape(
                                    tournamentViewDate(
                                        $tournament['start_date']
                                    )
                                ) ?>
                            </dd>
                        </div>

                        <div>
                            <dt>End date</dt>
                            <dd>
                                <?= tournamentViewEscape(
                                    tournamentViewDate(
                                        $tournament['end_date']
                                    )
                                ) ?>
                            </dd>
                        </div>

                        <div>
                            <dt>Venue</dt>
                            <dd>
                                <?= tournamentViewEscape(
                                    $tournament['venue']
                                ) ?>
                            </dd>
                        </div>

                    </dl>

                    <h2>Registration period</h2>

                    <dl class="tournament-view-facts">

                        <div>
                            <dt>Opens</dt>
                            <dd>
                                <?= tournamentViewEscape(
                                    tournamentViewDate(
                                        $tournament['reg_open_at']
                                    )
                                ) ?>
                            </dd>
                        </div>

                        <div>
                            <dt>Closes</dt>
                            <dd>
                                <?= tournamentViewEscape(
                                    tournamentViewDate(
                                        $tournament['reg_end_at']
                                    )
                                ) ?>
                            </dd>
                        </div>

                    </dl>

                </article>

                <aside class="tournament-view-panel">

                    <div class="tournament-view-team-header">
                        <h2>Participating teams</h2>

                        <span class="tournament-status">
                            <?= count($teams) ?>
                        </span>
                    </div>

                    <?php if (empty($teams)): ?>

                        <p class="tournament-view-muted">
                            No teams are currently registered
                            for this tournament.
                        </p>

                    <?php else: ?>

                        <ul class="tournament-team-list">

                            <?php foreach ($teams as $team): ?>

                                <li class="tournament-team-item">

                                    <strong>
                                        <?= tournamentViewEscape(
                                            $team['team_name']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= tournamentViewEscape(
                                            $team['club_name'] ??
                                                'Club information unavailable'
                                        ) ?>
                                    </span>

                                </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php endif; ?>

                </aside>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>