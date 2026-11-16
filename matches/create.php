<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
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

function fixtureEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function fixtureInput(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
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
        $form[$key] = fixtureInput($key);
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

// Process the submitted fixture.
if ($method === 'POST' && !$loadError) {

    if (
        !hash_equals(
            $csrfToken,
            fixtureInput('csrf_token')
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

    $titleLength = function_exists('mb_strlen')
        ? mb_strlen($form['title'], 'UTF-8')
        : strlen($form['title']);

    if ($titleLength < 3 || $titleLength > 100) {
        $errors[] = 'Match title must contain 3–100 characters.';
    }

    $venueLength = function_exists('mb_strlen')
        ? mb_strlen($form['venue'], 'UTF-8')
        : strlen($form['venue']);

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
             * Member 05's roster workflow locks this same row first.
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

<main class="matches-page">
    <section class="section-container matches-section">

        <div class="matches-header">
            <p class="section-label">
                NSBM SPORTSHUB / MATCH MANAGEMENT
            </p>

            <h1>Create match fixture.</h1>

            <p class="section-description">
                Schedule a match using registered tournament teams.
            </p>
        </div>

        <?php if ($loadError): ?>

            <div class="matches-empty">
                <h2>Unable to load tournament information.</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif ($tournaments === []): ?>

            <div class="matches-empty">
                <h2>No eligible tournaments available.</h2>
                <p>
                    Create a tournament or obtain an active
                    staff assignment before scheduling matches.
                </p>
            </div>

        <?php else: ?>

            <form method="get"
                action="/matches/create.php"
                class="match-create-form">

                <label for="tournament">
                    Select tournament
                </label>

                <select name="tournament"
                    id="tournament"
                    required>

                    <option value="">Choose tournament</option>

                    <?php foreach ($tournaments as $tournament): ?>
                        <option
                            value="<?= (int) $tournament['t_id'] ?>"
                            <?= $tournamentId === (int) $tournament['t_id']
                                ? 'selected' : '' ?>>
                            <?= fixtureEscape($tournament['t_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button type="submit" class="button button-accent">
                    Load teams
                </button>
            </form>

            <?php if ($errors !== []): ?>
                <div class="match-create-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= fixtureEscape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedTournament !== null): ?>

                <?php if (count($teamsById) < 2): ?>

                    <div class="matches-empty">
                        <h2>Not enough eligible teams.</h2>
                        <p>
                            At least two tournament teams must
                            have rostered students before a
                            fixture can be scheduled.
                        </p>
                    </div>

                <?php else: ?>

                    <form method="post"
                        action="/matches/create.php"
                        class="match-create-form">

                        <input type="hidden"
                            name="csrf_token"
                            value="<?= fixtureEscape($csrfToken) ?>">

                        <input type="hidden"
                            name="tournament_id"
                            value="<?= $tournamentId ?>">

                        <label for="match_title">Match title</label>
                        <input id="match_title"
                            name="title"
                            type="text"
                            maxlength="100"
                            required
                            value="<?= fixtureEscape($form['title']) ?>"
                            placeholder="Example: Semi-final 1">

                        <label for="home_team_id">Home team</label>
                        <select id="home_team_id"
                            name="home_team_id"
                            required>
                            <option value="">Select home team</option>
                            <?php foreach ($teamsById as $team): ?>
                                <option
                                    value="<?= (int) $team['team_id'] ?>"
                                    <?= (string) $team['team_id'] === $form['home_team_id']
                                        ? 'selected' : '' ?>>
                                    <?= fixtureEscape($team['team_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="away_team_id">Away team</label>
                        <select id="away_team_id"
                            name="away_team_id"
                            required>
                            <option value="">Select away team</option>
                            <?php foreach ($teamsById as $team): ?>
                                <option
                                    value="<?= (int) $team['team_id'] ?>"
                                    <?= (string) $team['team_id'] === $form['away_team_id']
                                        ? 'selected' : '' ?>>
                                    <?= fixtureEscape($team['team_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="match_date">Match date</label>
                        <input id="match_date"
                            type="date"
                            name="date"
                            min="<?= fixtureEscape($today) ?>"
                            required
                            value="<?= fixtureEscape($form['date']) ?>">

                        <label for="venue">Venue</label>
                        <input id="venue"
                            name="venue"
                            type="text"
                            maxlength="255"
                            required
                            value="<?= fixtureEscape($form['venue']) ?>">

                        <p class="section-description">
                            Team representatives are selected
                            automatically from their rosters,
                            prioritizing captains.
                        </p>

                        <button type="submit"
                            class="button button-accent">
                            Create fixture
                        </button>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>