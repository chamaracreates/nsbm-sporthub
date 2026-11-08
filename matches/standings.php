<?php

require_once __DIR__ . '/../includes/db.php';

function standingsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$tournaments = [];
$selectedTournament = null;
$standings = [];
$completedMatchCount = 0;
$loadError = false;

// Retrieve tournaments for the selection menu.
try {
    $sql = "SELECT t_id, t_name, sport
            FROM tournaments
            ORDER BY start_date DESC, t_id DESC";

    $tournaments = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = filter_input(
        INPUT_GET,
        'tournament',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    // Select the first tournament by default.
    $selectedTournament = $tournaments[0] ?? null;

    foreach ($tournaments as $tournament) {
        if ((int) $tournament['t_id'] === $requestedId) {
            $selectedTournament = $tournament;
            break;
        }
    }

    if ($selectedTournament !== null) {
        $tournamentId = (int) $selectedTournament['t_id'];

        // Retrieve all teams registered for this tournament.
        $teamSql = "SELECT team_id, team_name
                    FROM teams
                    WHERE t_id = :tournament_id
                    ORDER BY team_name";

        $teamStatement = db()->prepare($teamSql);

        $teamStatement->execute([
            'tournament_id' => $tournamentId
        ]);

        $teams = $teamStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($teams as $team) {
            $teamId = (int) $team['team_id'];

            $standings[$teamId] = [
                'name' => $team['team_name'],
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'for' => 0,
                'against' => 0,
                'difference' => 0,
                'points' => 0
            ];
        }

        // Retrieve completed matches for the selected tournament.
        $matchSql = "SELECT
                        home_team_id,
                        away_team_id,
                        home_score,
                        away_score
                     FROM matches
                     WHERE t_id = :tournament_id
                       AND LOWER(TRIM(match_status))
                           IN ('completed', 'finished')";

        $matchStatement = db()->prepare($matchSql);

        $matchStatement->execute([
            'tournament_id' => $tournamentId
        ]);

        $completedMatches = $matchStatement->fetchAll(PDO::FETCH_ASSOC);

        // Calculate statistics for each participating team.
        foreach ($completedMatches as $match) {
            $homeId = (int) $match['home_team_id'];
            $awayId = (int) $match['away_team_id'];

            // Ignore records with invalid team associations.
            if (
                $homeId === $awayId ||
                !isset($standings[$homeId]) ||
                !isset($standings[$awayId])
            ) {
                continue;
            }

            $homeScore = (int) $match['home_score'];
            $awayScore = (int) $match['away_score'];

            $completedMatchCount++;

            $standings[$homeId]['played']++;
            $standings[$awayId]['played']++;

            $standings[$homeId]['for'] += $homeScore;
            $standings[$homeId]['against'] += $awayScore;

            $standings[$awayId]['for'] += $awayScore;
            $standings[$awayId]['against'] += $homeScore;

            if ($homeScore > $awayScore) {
                $standings[$homeId]['won']++;
                $standings[$homeId]['points'] += 3;

                $standings[$awayId]['lost']++;
            } elseif ($awayScore > $homeScore) {
                $standings[$awayId]['won']++;
                $standings[$awayId]['points'] += 3;

                $standings[$homeId]['lost']++;
            } else {
                $standings[$homeId]['drawn']++;
                $standings[$awayId]['drawn']++;

                $standings[$homeId]['points']++;
                $standings[$awayId]['points']++;
            }
        }

        // Calculate score difference.
        foreach ($standings as &$team) {
            $team['difference'] =
                $team['for'] - $team['against'];
        }

        unset($team);

        // Rank teams by points, score difference and scores for.
        uasort($standings, static function ($a, $b): int {
            return ($b['points'] <=> $a['points'])
                ?: ($b['difference'] <=> $a['difference'])
                ?: ($b['for'] <=> $a['for'])
                ?: strcmp($a['name'], $b['name']);
        });
    }
} catch (Throwable $error) {
    error_log('Tournament standings: ' . $error->getMessage());
    $loadError = true;
}

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page">

    <section class="section-container matches-section">

        <div class="matches-header">

            <p class="section-label">
                NSBM SPORTSHUB / STANDINGS
            </p>

            <h1>Team standings.</h1>

            <p class="section-description">
                Explore tournament rankings, team performance
                and completed match statistics.
            </p>

        </div>

        <!-- Matches Navigation -->
        <nav class="matches-tabs" aria-label="Match categories">

            <a href="/matches/index.php?tab=upcoming">
                Upcoming
            </a>

            <a href="/matches/index.php?tab=results">
                Results
            </a>

            <a href="/matches/index.php?tab=all">
                All matches
            </a>

            <a
                href="/matches/standings.php"
                class="active"
                aria-current="page">
                Standings
            </a>

        </nav>

        <?php if ($loadError): ?>

            <div class="matches-empty">
                <h2>Unable to load standings.</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif (empty($tournaments)): ?>

            <div class="matches-empty">
                <h2>No tournaments available yet.</h2>

                <p>
                    Team standings will appear here
                    when tournaments are created.
                </p>
            </div>

        <?php else: ?>

            <!-- Tournament Selection -->

            <form
                action="/matches/standings.php"
                method="get"
                class="standings-filter">

                <label for="tournament">
                    Select tournament
                </label>

                <select id="tournament" name="tournament">

                    <?php foreach ($tournaments as $tournament): ?>

                        <option
                            value="<?php echo (int) $tournament['t_id']; ?>"
                            <?php
                            echo (int) $selectedTournament['t_id'] ===
                                (int) $tournament['t_id']
                                ? 'selected'
                                : '';
                            ?>>
                            <?php
                            echo standingsEscape($tournament['t_name']);
                            ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button type="submit" class="button button-accent">
                    View standings
                </button>

            </form>

            <div class="standings-heading">

                <h2>
                    <?php
                    echo standingsEscape($selectedTournament['t_name']);
                    ?>
                </h2>

                <p>
                    <?php
                    echo standingsEscape($selectedTournament['sport']);
                    ?>
                    / <?php echo $completedMatchCount; ?> completed matches
                </p>

            </div>

            <?php if (empty($standings)): ?>

                <div class="matches-empty">

                    <h2>No teams registered yet.</h2>

                    <p>
                        Standings will appear when teams
                        are registered for this tournament.
                    </p>

                </div>

            <?php else: ?>

                <?php if ($completedMatchCount === 0): ?>

                    <p class="standings-notice">
                        No completed matches yet.
                        All teams currently have zero points.
                    </p>

                <?php endif; ?>

                <!-- Tournament Standings Table -->

                <div class="standings-table-wrap">

                    <table class="standings-table">

                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Team</th>
                                <th scope="col">P</th>
                                <th scope="col">W</th>
                                <th scope="col">D</th>
                                <th scope="col">L</th>
                                <th scope="col">For</th>
                                <th scope="col">Against</th>
                                <th scope="col">Diff</th>
                                <th scope="col">Pts</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php $position = 1; ?>

                            <?php foreach ($standings as $team): ?>

                                <tr>

                                    <td><?php echo $position++; ?></td>

                                    <th scope="row">
                                        <?php
                                        echo standingsEscape($team['name']);
                                        ?>
                                    </th>

                                    <td><?php echo $team['played']; ?></td>
                                    <td><?php echo $team['won']; ?></td>
                                    <td><?php echo $team['drawn']; ?></td>
                                    <td><?php echo $team['lost']; ?></td>
                                    <td><?php echo $team['for']; ?></td>
                                    <td><?php echo $team['against']; ?></td>
                                    <td><?php echo $team['difference']; ?></td>
                                    <td><?php echo $team['points']; ?></td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <p class="standings-notice">
                    Provisional ranking: 3 points for a win,
                    1 for a draw and 0 for a loss.
                    Tiebreakers use score difference,
                    then scores for.
                </p>

            <?php endif; ?>

        <?php endif; ?>

        <div class="standings-back">

            <a href="/matches/index.php" class="button button-outline">
                Back to matches
            </a>

        </div>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>