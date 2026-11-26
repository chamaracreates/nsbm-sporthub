<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}


function fixtureId(mixed $value): int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $id === false ? 0 : (int) $id;
}

if (
    !isset($_SESSION['match_create_csrf']) ||
    !is_string($_SESSION['match_create_csrf'])
) {
    $_SESSION['match_create_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['match_create_csrf'];

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

$tournamentId = fixtureId(
    $method === 'POST'
        ? ($_POST['tournament_id'] ?? null)
        : ($_GET['tournament'] ?? null)
);

$tournaments = [];
$selectedTournament = null;
$teams = [];
$teamsById = [];
$errors = [];
$loadError = false;

$form = [
    'title' => '',
    'home_team_id' => '',
    'away_team_id' => '',
    'date' => '',
    'venue' => ''
];

if ($method === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = sportsHubInput($key);
    }
}

try {
    $pdo = db();

    // Admins can access all active clubs.
    // Coaches and MICs require an active assignment.
    $scope = '';
    $params = [];

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

        $params = [
            'staff_user_id' => $userId,
            'staff_role' => $userRole
        ];
    }

    $statement = $pdo->prepare(
        "SELECT
            t.t_id,
            t.t_name,
            t.club_id,
            t.sport,
            t.venue,
            t.start_date,
            t.end_date,
            t.status
         FROM tournaments AS t
         INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
         WHERE sc.status = 'active'
         $scope
         ORDER BY t.start_date DESC, t.t_id DESC"
    );

    $statement->execute($params);
    $tournaments = $statement->fetchAll(PDO::FETCH_ASSOC);

    // Only use a tournament within the user's scope.
    foreach ($tournaments as $tournament) {
        if ((int) $tournament['t_id'] === $tournamentId) {
            $selectedTournament = $tournament;
            break;
        }
    }

    if ($selectedTournament !== null) {
        $teamStatement = $pdo->prepare(
            "SELECT
                tm.team_id,
                tm.team_name,
                (
                    SELECT member.user_id
                    FROM team_members AS member
                    INNER JOIN users AS u
                        ON u.user_id = member.user_id
                    WHERE member.team_id = tm.team_id
                      AND u.role = 'student'
                    ORDER BY
                        CASE
                            WHEN LOWER(TRIM(member.role))
                                = 'captain'
                            THEN 0
                            ELSE 1
                        END,
                        member.team_mem_id
                    LIMIT 1
                ) AS representative_user_id
             FROM teams AS tm
             WHERE tm.t_id = :tournament_id
               AND tm.club_id = :club_id
             ORDER BY tm.team_name"
        );

        $teamStatement->execute([
            'tournament_id' => $tournamentId,
            'club_id' => $selectedTournament['club_id']
        ]);

        $teams = $teamStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($teams as $team) {
            if ($team['representative_user_id'] !== null) {
                $teamsById[(int) $team['team_id']] = $team;
            }
        }

        if ($method === 'GET') {
            $form['venue'] = (string) $selectedTournament['venue'];
        }
    }
} catch (Throwable $error) {
    error_log('Match fixture loading: ' . $error->getMessage());
    $loadError = true;
}

// Allow administrators to create fixtures without a tournament association.
// Staff are intentionally restricted to their authorized tournament clubs.
$requestedMode = $method === 'POST'
    ? sportsHubInput('match_mode')
    : (is_string($_GET['mode'] ?? null) ? $_GET['mode'] : '');

if ($method === 'POST' && $requestedMode === 'standalone' && $userRole !== 'admin') {
    http_response_code(403);
    exit('Only administrators can create independent fixtures.');
}

$standaloneMode = $userRole === 'admin'
    && ($requestedMode === 'standalone'
        || ($method === 'GET' && $requestedMode !== 'tournament' && $tournaments === []));

$standalone = [
    'title' => sportsHubInput('title'),
    'home_name' => sportsHubInput('home_name'),
    'away_name' => sportsHubInput('away_name'),
    'sport' => sportsHubInput('sport'),
    'date' => sportsHubInput('date'),
    'venue' => sportsHubInput('venue'),
];

$standaloneSchemaReady = false;
if ($standaloneMode && !$loadError) {
    try {
        $nullability = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'matches'
               AND COLUMN_NAME IN (
                   'home_team_id', 'away_team_id', 'home_u_id', 'away_u_id'
               )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $standaloneSchemaReady = count($nullability) === 4
            && count(array_filter(
                $nullability,
                static fn($value): bool => $value === 'YES'
            )) === 4;
    } catch (Throwable $error) {
        error_log('Independent fixture schema check: ' . $error->getMessage());
    }
}

// Independent fixtures use the already-present team-name and sport fields;
// optional tournament, roster and representative IDs remain NULL.
if ($method === 'POST' && $standaloneMode && !$loadError) {
    if (!hash_equals($csrfToken, sportsHubInput('csrf_token'))) {
        $errors[] = 'Your session expired. Refresh and submit again.';
    }
    if (!$standaloneSchemaReady) {
        $errors[] = 'Independent fixtures are not enabled in the database yet. '
            . 'An administrator must back up the database and apply the required schema upgrade.';
    }

    $limits = [
        'title' => [3, 100, 'Match title'],
        'home_name' => [2, 255, 'Home team name'],
        'away_name' => [2, 255, 'Away team name'],
        'sport' => [2, 255, 'Sport'],
        'venue' => [2, 255, 'Venue'],
    ];
    foreach ($limits as $field => [$minimum, $maximum, $label]) {
        $length = sportsHubLength($standalone[$field]);
        if ($length < $minimum || $length > $maximum) {
            $errors[] = sprintf('%s must contain %d–%d characters.', $label, $minimum, $maximum);
        }
    }
    if (strcasecmp($standalone['home_name'], $standalone['away_name']) === 0) {
        $errors[] = 'Home and away teams must be different.';
    }

    $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $standalone['date']) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $standalone['date'])
        : false;
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $standalone['date']) {
        $errors[] = 'Enter a valid match date.';
    } elseif ($standalone['date'] < $today) {
        $errors[] = 'The fixture date cannot be in the past.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insertStandalone = $pdo->prepare(
                "INSERT INTO matches (
                    t_id, title, home_team_id, away_team_id,
                    home_name, away_name, home_u_id, away_u_id,
                    sport, date, venue, match_status, home_score, away_score
                ) VALUES (
                    NULL, :title, NULL, NULL,
                    :home_name, :away_name, NULL, NULL,
                    :sport, :match_date, :venue, 'scheduled', 0, 0
                )"
            );
            $insertStandalone->execute([
                'title' => $standalone['title'],
                'home_name' => $standalone['home_name'],
                'away_name' => $standalone['away_name'],
                'sport' => $standalone['sport'],
                'match_date' => $standalone['date'],
                'venue' => $standalone['venue'],
            ]);
            $newMatchId = (int) $pdo->lastInsertId();
            $pdo->commit();
            header('Location: /matches/view.php?id=' . $newMatchId, true, 303);
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Independent fixture creation: ' . $error->getMessage());
            $errors[] = 'Unable to save the fixture. Check the database schema and application logs.';
        }
    }
}

// Process the submitted tournament fixture.
if ($method === 'POST' && !$loadError && !$standaloneMode) {

    if (
        !hash_equals(
            $csrfToken,
            sportsHubInput('csrf_token')
        )
    ) {
        $errors[] = 'Invalid form token. Refresh and try again.';
    }

    if ($selectedTournament === null) {
        $errors[] = 'Select a tournament you are authorized to manage.';
    } elseif (!in_array(
        strtolower(trim((string) $selectedTournament['status'])),
        ['upcoming', 'ongoing'],
        true
    )) {
        $errors[] = 'Fixtures can only be created for upcoming or ongoing tournaments.';
    }

    $homeId = fixtureId($form['home_team_id']);
    $awayId = fixtureId($form['away_team_id']);

    if ($homeId === $awayId) {
        $errors[] = 'Select two different teams.';
    }

    if (
        !isset($teamsById[$homeId]) ||
        !isset($teamsById[$awayId])
    ) {
        $errors[] = 'Both teams must have eligible roster members in this tournament.';
    }

    $titleLength = sportsHubLength($form['title']);

    if ($titleLength < 3 || $titleLength > 100) {
        $errors[] = 'Match title must contain 3–100 characters.';
    }

    $venueLength = sportsHubLength($form['venue']);

    if ($venueLength < 2 || $venueLength > 255) {
        $errors[] = 'Venue must contain 2–255 characters.';
    }

    // Validate the format before asking DateTimeImmutable to parse it.
    // This also rejects unexpected characters from a crafted POST request.
    $date = false;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $form['date']) === 1) {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $form['date']
        );
    }

    if (
        $date === false ||
        $date->format('Y-m-d') !== $form['date']
    ) {
        $errors[] = 'Enter a valid match date.';
    } elseif ($selectedTournament !== null) {
        if (
            $form['date'] < $today ||
            $form['date'] < $selectedTournament['start_date'] ||
            $form['date'] > $selectedTournament['end_date']
        ) {
            $errors[] = 'Match date must be upcoming and within the tournament dates.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the parent tournament before reading the current roster.
             * The tournament roster update process acquires the same lock.
             */
            $lockTournament = $pdo->prepare(
                "SELECT t_id
                 FROM tournaments
                 WHERE t_id = :tournament_id
                 FOR UPDATE"
            );

            $lockTournament->execute([
                'tournament_id' => $tournamentId
            ]);

            if ($lockTournament->fetchColumn() === false) {
                throw new DomainException(
                    'The tournament no longer exists. Reload the page.'
                );
            }

            /*
             * Recheck authorization and tournament availability while the
             * tournament lock is held. Do not trust the earlier GET/POST read.
             */
            $currentTournamentStatement = $pdo->prepare(
                "SELECT
                    t.t_id,
                    t.club_id,
                    t.sport,
                    t.status,
                    t.start_date,
                    t.end_date
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc
                    ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id
                   AND sc.status = 'active'
                   $scope
                 LIMIT 1"
            );

            $currentTournamentStatement->execute(array_merge(
                $params,
                ['tournament_id' => $tournamentId]
            ));

            $currentTournament = $currentTournamentStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (
                $currentTournament === false ||
                !in_array(
                    strtolower(trim((string) $currentTournament['status'])),
                    ['upcoming', 'ongoing'],
                    true
                )
            ) {
                throw new DomainException(
                    'Tournament availability or staff permissions changed. Reload the page.'
                );
            }

            if (
                $form['date'] < $today ||
                $form['date'] < $currentTournament['start_date'] ||
                $form['date'] > $currentTournament['end_date']
            ) {
                throw new DomainException(
                    'Match date is no longer within the allowed tournament dates.'
                );
            }

            /*
             * Re-read both teams and their representatives after acquiring
             * the tournament lock. This coordinates with roster modifications
             * through tournaments/team-roster.php.
             */
            $teamStatement->execute([
                'tournament_id' => $tournamentId,
                'club_id' => (int) $currentTournament['club_id']
            ]);

            $currentTeamsById = [];

            foreach ($teamStatement->fetchAll(PDO::FETCH_ASSOC) as $team) {
                if ($team['representative_user_id'] !== null) {
                    $currentTeamsById[(int) $team['team_id']] = $team;
                }
            }

            if (
                $homeId === $awayId ||
                !isset($currentTeamsById[$homeId]) ||
                !isset($currentTeamsById[$awayId])
            ) {
                throw new DomainException(
                    'Team selections or rosters changed. Reload the page and choose eligible teams.'
                );
            }

            $insert = $pdo->prepare(
                "INSERT INTO matches (
                    t_id,
                    title,
                    home_team_id,
                    away_team_id,
                    home_name,
                    away_name,
                    home_u_id,
                    away_u_id,
                    sport,
                    date,
                    venue,
                    match_status,
                    home_score,
                    away_score
                ) VALUES (
                    :t_id,
                    :title,
                    :home_team_id,
                    :away_team_id,
                    :home_name,
                    :away_name,
                    :home_u_id,
                    :away_u_id,
                    :sport,
                    :date,
                    :venue,
                    'scheduled',
                    0,
                    0
                )"
            );

            $insert->execute([
                't_id' => $tournamentId,
                'title' => $form['title'],
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'home_name' => $currentTeamsById[$homeId]['team_name'],
                'away_name' => $currentTeamsById[$awayId]['team_name'],
                'home_u_id' => $currentTeamsById[$homeId]['representative_user_id'],
                'away_u_id' => $currentTeamsById[$awayId]['representative_user_id'],
                'sport' => $currentTournament['sport'],
                'date' => $form['date'],
                'venue' => $form['venue']
            ]);

            $matchId = (int) $pdo->lastInsertId();
            $pdo->commit();

            header('Location: /matches/view.php?id=' . $matchId, true, 303);
            exit;
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Match fixture creation: ' . $error->getMessage());
            $errors[] = 'Unable to create the fixture.';
        }
    }
}

$pageStylesheets = ['/assets/css/matches.css'];

include __DIR__ . '/../includes/header.php';
?>

<main class="matches-page sports-hub-workflow">
    <section class="section-container matches-section">

        <div class="matches-header">
            <h1>Create match fixture.</h1>

            <p class="section-description">
                Schedule a standalone fixture or a tournament match.
            </p>
        </div>

        <!-- Independent and tournament fixture entry options. -->
        <?php if ($userRole === 'admin' && !$loadError): ?>
            <nav class="match-mode-switch" aria-label="Fixture type">
                <a href="/matches/create.php?mode=standalone"
                   class="<?= $standaloneMode ? 'active' : '' ?>"
                   <?= $standaloneMode ? 'aria-current="page"' : '' ?>>
                    Standalone match
                </a>
                <a href="/matches/create.php?mode=tournament"
                   class="<?= !$standaloneMode ? 'active' : '' ?>"
                   <?= !$standaloneMode ? 'aria-current="page"' : '' ?>>
                    Tournament match
                </a>
            </nav>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <div class="matches-empty">
                <h2>Fixtures could not be loaded</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif ($standaloneMode): ?>
            <div class="match-create-intro">
                <h2>Schedule a standalone match</h2>
                <p>Create a friendly fixture or campus match without setting up a tournament or registering teams first.</p>
            </div>

            <?php if (!$standaloneSchemaReady): ?>
                <div class="match-create-notice" role="status">
                    Standalone fixtures require nullable tournament and team references in the
                    <code>matches</code> table. Update the existing database schema before
                    creating standalone fixtures; do not reimport the full schema over existing data.
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="match-create-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= sportsHubEscape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="match-create-form standalone-fixture-form"
                  action="/matches/create.php?mode=standalone" method="post">
                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                <input type="hidden" name="match_mode" value="standalone">

                <div class="match-field">
                    <label for="standalone-title">Match title</label>
                    <input id="standalone-title" name="title" maxlength="100" required
                           placeholder="e.g. Interfaculty friendly" value="<?= sportsHubEscape($standalone['title']) ?>">
                </div>
                <div class="match-field">
                    <label for="standalone-sport">Sport</label>
                    <input id="standalone-sport" name="sport" maxlength="255" required
                           list="standalone-sports" placeholder="e.g. Football"
                           value="<?= sportsHubEscape($standalone['sport']) ?>">
                    <datalist id="standalone-sports">
                        <option value="Athletics"><option value="Badminton"><option value="Basketball">
                        <option value="Cricket"><option value="Football"><option value="Netball">
                        <option value="Rugby"><option value="Swimming"><option value="Tennis">
                        <option value="Volleyball">
                    </datalist>
                </div>
                <div class="match-field">
                    <label for="standalone-home">Home team</label>
                    <input id="standalone-home" name="home_name" maxlength="255" required
                           placeholder="e.g. Faculty of Computing" value="<?= sportsHubEscape($standalone['home_name']) ?>">
                </div>
                <div class="match-field">
                    <label for="standalone-away">Away team</label>
                    <input id="standalone-away" name="away_name" maxlength="255" required
                           placeholder="e.g. Faculty of Engineering" value="<?= sportsHubEscape($standalone['away_name']) ?>">
                </div>
                <div class="match-field">
                    <label for="standalone-date">Match date</label>
                    <input id="standalone-date" type="date" name="date" min="<?= sportsHubEscape($today) ?>"
                           required value="<?= sportsHubEscape($standalone['date']) ?>">
                </div>
                <div class="match-field">
                    <label for="standalone-venue">Venue</label>
                    <input id="standalone-venue" name="venue" maxlength="255" required
                           placeholder="e.g. NSBM Sports Ground" value="<?= sportsHubEscape($standalone['venue']) ?>">
                </div>
                <div class="match-form-footer">
                    <p>The match will appear in public fixtures. You can update results later through Manage Matches.</p>
                    <button type="submit" class="button button-accent"
                            <?= !$standaloneSchemaReady ? 'disabled' : '' ?>>Create standalone match</button>
                </div>
            </form>

        <?php else: ?>
            <div class="match-create-intro">
                <h2>Schedule a tournament match</h2>
                <p>Choose a tournament, then select two eligible teams with registered student rosters.</p>
            </div>

            <?php if ($tournaments === []): ?>
                <div class="matches-empty">
                    <h2>No tournaments available</h2>
                    <p>Create a tournament to schedule a tournament fixture. Administrators can also create a standalone match without a tournament.</p>
                    <?php if ($userRole === 'admin'): ?>
                        <a href="/matches/create.php?mode=standalone" class="button button-accent">Create standalone match</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="get" action="/matches/create.php" class="match-create-form match-tournament-picker">
                    <input type="hidden" name="mode" value="tournament">
                    <div class="match-field">
                        <label for="tournament">Tournament</label>
                        <select name="tournament" id="tournament" required>
                            <option value="">Choose tournament</option>
                            <?php foreach ($tournaments as $tournament): ?>
                                <option value="<?= (int) $tournament['t_id'] ?>"
                                    <?= $tournamentId === (int) $tournament['t_id'] ? 'selected' : '' ?>>
                                    <?= sportsHubEscape($tournament['t_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="button button-accent">Load teams</button>
                </form>

                <?php if ($errors !== []): ?>
                    <div class="match-create-errors" role="alert">
                        <?php foreach ($errors as $error): ?>
                            <p><?= sportsHubEscape($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($selectedTournament !== null): ?>
                    <?php if (count($teamsById) < 2): ?>
                        <div class="matches-empty">
                            <h2>Two registered teams are required</h2>
                            <p>Add at least two tournament teams with rostered students before scheduling this fixture.</p>
                        </div>
                    <?php else: ?>
                        <form method="post" action="/matches/create.php?mode=tournament"
                              class="match-create-form tournament-fixture-form">
                            <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                            <input type="hidden" name="match_mode" value="tournament">
                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                            <div class="match-field match-field-wide">
                                <label for="match_title">Match title</label>
                                <input id="match_title" name="title" maxlength="100" required
                                       placeholder="e.g. Semi-final 1" value="<?= sportsHubEscape($form['title']) ?>">
                            </div>
                            <div class="match-field">
                                <label for="home_team_id">Home team</label>
                                <select id="home_team_id" name="home_team_id" required>
                                    <option value="">Select home team</option>
                                    <?php foreach ($teamsById as $team): ?>
                                        <option value="<?= (int) $team['team_id'] ?>"
                                            <?= (string) $team['team_id'] === $form['home_team_id'] ? 'selected' : '' ?>>
                                            <?= sportsHubEscape($team['team_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="match-field">
                                <label for="away_team_id">Away team</label>
                                <select id="away_team_id" name="away_team_id" required>
                                    <option value="">Select away team</option>
                                    <?php foreach ($teamsById as $team): ?>
                                        <option value="<?= (int) $team['team_id'] ?>"
                                            <?= (string) $team['team_id'] === $form['away_team_id'] ? 'selected' : '' ?>>
                                            <?= sportsHubEscape($team['team_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="match-field">
                                <label for="match_date">Match date</label>
                                <input id="match_date" type="date" name="date" min="<?= sportsHubEscape($today) ?>"
                                       required value="<?= sportsHubEscape($form['date']) ?>">
                            </div>
                            <div class="match-field">
                                <label for="venue">Venue</label>
                                <input id="venue" name="venue" maxlength="255" required
                                       value="<?= sportsHubEscape($form['venue']) ?>">
                            </div>
                            <div class="match-form-footer">
                                <p>Team representatives are selected from their rosters, prioritizing captains.</p>
                                <button type="submit" class="button button-accent">Create tournament match</button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>