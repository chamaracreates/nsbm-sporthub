<?php

require_once __DIR__ . '/../includes/db.php';


require_once __DIR__ . '/../includes/helpers.php';

// Filter tournament standings with a server-side search query.
$searchQuery = $_GET['search'] ?? '';
$searchQuery = is_string($searchQuery) ? trim($searchQuery) : '';
$searchQuery = substr($searchQuery, 0, 100);
$selectedSport = $_GET['sport'] ?? '';
$selectedSport = is_string($selectedSport) ? trim($selectedSport) : '';
$selectedSport = substr($selectedSport, 0, 100);
$availableSports = [];
$matchingTournaments = [];
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

    // Build sport options from current tournaments, then filter by name and sport.
    // Keep selection explicit: an imported tournament need not have team standings.
    foreach ($tournaments as $tournament) {
        $sportName = trim((string) ($tournament['sport'] ?? ''));
        if ($sportName !== '') {
            $availableSports[$sportName] = $sportName;
        }
        $nameMatches = $searchQuery === '' ||
            stripos((string) $tournament['t_name'], $searchQuery) !== false;
        $sportMatches = $selectedSport === '' ||
            strcasecmp($sportName, $selectedSport) === 0;
        if ($nameMatches && $sportMatches) {
            $matchingTournaments[] = $tournament;
        }
    }
    natcasesort($availableSports);

    if ($requestedId !== null && $requestedId !== false) {
        foreach ($matchingTournaments as $tournament) {
            if ((int) $tournament['t_id'] === (int) $requestedId) {
                $selectedTournament = $tournament;
                break;
            }
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

$pageStylesheets = ['/assets/css/matches.css'];

$pageTitle = 'Standings';

include __DIR__ . '/../includes/header.php';

?>

<main class="matches-page">

    <section class="section-container matches-section">

        <div class="matches-header">

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
            <!-- Tournament search and sport filters. -->
            <form action="/matches/standings.php" method="get" class="standings-search-form" role="search">
                <div class="standings-filter-grid">
                    <div class="standings-filter-item">
                        <label for="standings-tournament-search">Search tournaments</label>
                        <input class="standings-filter-input" type="search"
                               id="standings-tournament-search" name="search"
                               value="<?= sportsHubEscape($searchQuery) ?>"
                               placeholder="Search by tournament name"
                               maxlength="100" autocomplete="off">
                    </div>
                    <div class="standings-filter-item">
                        <label for="standings-sport">Sport</label>
                        <select class="standings-filter-select" id="standings-sport" name="sport">
                            <option value="">All sports</option>
                            <?php foreach ($availableSports as $sport): ?>
                                <option value="<?= sportsHubEscape($sport) ?>"
                                        <?= strcasecmp($selectedSport, $sport) === 0 ? 'selected' : '' ?>>
                                    <?= sportsHubEscape($sport) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="standings-filter-submit">Search tournaments</button>
                </div>
            </form>

            <?php if (($searchQuery !== '' || $selectedSport !== '') && $matchingTournaments): ?>
                <div class="standings-search-results" aria-label="Matching tournaments">
                    <?php foreach ($matchingTournaments as $candidate): ?>
                        <?php $isSelected = $selectedTournament !== null &&
                            (int) $candidate['t_id'] === (int) $selectedTournament['t_id']; ?>
                        <a href="/matches/standings.php?<?= http_build_query(['search' => $searchQuery, 'sport' => $selectedSport, 'tournament' => (int) $candidate['t_id']]) ?>"
                           class="standings-search-result<?= $isSelected ? ' is-selected' : '' ?>"
                           <?= $isSelected ? 'aria-current="true"' : '' ?>>
                            <span><?= sportsHubEscape($candidate['t_name']) ?></span>
                            <small><?= sportsHubEscape($candidate['sport']) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedTournament !== null): ?>

            <div class="standings-selected-event" aria-label="Selected tournament">
                <span class="standings-selected-label">Selected tournament</span>
                <strong><?= sportsHubEscape($selectedTournament['t_name']) ?></strong>
            </div>

            <?php if (empty($standings)): ?>

                <div class="matches-empty">

                    <h2>Standings aren't available yet</h2>

                    <p>This tournament has no registered teams in SportsHub. Rankings will appear when participating teams and results are recorded.</p>

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
                                        echo sportsHubEscape($team['name']);
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

            <?php else: ?>
                <div class="standings-intro" role="status">
                    <?php if ($searchQuery === '' && $selectedSport === ''): ?>
                        <h2>Find tournament standings</h2>
                        <p>Search for a tournament by name, then select it to view its participating teams and results.</p>
                    <?php elseif ($matchingTournaments): ?>
                        <h2>Select a tournament</h2>
                        <p>Choose one of the matching tournaments above to view its standings.</p>
                    <?php else: ?>
                        <h2>No tournaments found</h2>
                        <p>Try a different tournament name.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>