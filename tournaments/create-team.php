<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/* Require an authorized staff account. */
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

function teamCreateEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function teamCreateInput(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

/* Create a CSRF token for the current session. */
if (
    !isset($_SESSION['team_create_csrf']) ||
    !is_string($_SESSION['team_create_csrf'])
) {
    $_SESSION['team_create_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['team_create_csrf'];

$isAdmin = $userRole === 'admin';

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

$tournaments = [];
$eligibleTournaments = [];
$errors = [];
$loadError = false;

$teamName = '';
$selectedTournament = '';

/*
 * Apply the same club authorization to the dropdown
 * and to the final database insertion.
 */
$clubScope = '';
$staffParameters = [];

if (!$isAdmin) {
    $clubScope = "
        AND EXISTS (
            SELECT 1
            FROM sport_staff AS ss
            WHERE ss.club_id = t.club_id
              AND ss.user_id = :staff_user_id
              AND ss.staff_role = :staff_role
              AND ss.status = 'active'
        )
    ";

    $staffParameters = [
        'staff_user_id' => $userId,
        'staff_role' => $userRole
    ];
}

try {
    $pdo = db();

    /* Load upcoming tournaments in authorized clubs. */
    $statement = $pdo->prepare(
        "SELECT
            t.t_id,
            t.t_name,
            t.start_date,
            sc.club_name
         FROM tournaments AS t
         INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
         WHERE sc.status = 'active'
           AND LOWER(TRIM(t.status)) = 'upcoming'
           AND t.start_date > :today
           $clubScope
         ORDER BY t.start_date ASC, t.t_id ASC"
    );

    $statement->execute(
        array_merge(
            ['today' => $today],
            $staffParameters
        )
    );

    $tournaments = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($tournaments as $tournament) {
        $eligibleTournaments[(int) $tournament['t_id']] = true;
    }
} catch (Throwable $error) {
    error_log(
        'Team creation tournament loading: ' .
            $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

/* Process a submitted team creation form. */
if ($method === 'POST' && !$loadError) {

    $teamName = teamCreateInput('team_name');
    $selectedTournament = teamCreateInput('tournament_id');

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] =
            'Invalid form session. Refresh and try again.';
    }

    $nameLength = function_exists('mb_strlen')
        ? mb_strlen($teamName, 'UTF-8')
        : strlen($teamName);

    if ($nameLength < 1 || $nameLength > 255) {
        $errors[] =
            'Team name must contain 1–255 characters.';
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $teamName)) {
        $errors[] =
            'Team name contains invalid control characters.';
    }

    $tournamentId = filter_var(
        $selectedTournament,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_int($tournamentId) ||
        !isset($eligibleTournaments[$tournamentId])
    ) {
        $errors[] =
            'Select an available tournament you can manage.';
    }

    if (empty($errors)) {

        try {
            $pdo->beginTransaction();

            /*
             * Lock the selected tournament while creating
             * the team. Recheck permissions, status and
             * event dates before inserting any record.
             */
            $check = $pdo->prepare(
                "SELECT
                    t.t_id,
                    t.club_id
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc
                    ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id
                   AND sc.status = 'active'
                   AND LOWER(TRIM(t.status)) = 'upcoming'
                   AND t.start_date > :today
                   $clubScope
                 FOR UPDATE"
            );

            $check->execute(
                array_merge(
                    [
                        'tournament_id' => $tournamentId,
                        'today' => $today
                    ],
                    $staffParameters
                )
            );

            $selected = $check->fetch(PDO::FETCH_ASSOC);

            if (!is_array($selected)) {
                $pdo->rollBack();

                $errors[] =
                    'Tournament availability or your ' .
                    'club permissions have changed.';
            } else {

                /*
                 * Avoid creating the same named team
                 * more than once within a tournament.
                 */
                $duplicate = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM teams
                     WHERE t_id = :tournament_id
                       AND LOWER(TRIM(team_name)) =
                           LOWER(:team_name)"
                );

                $duplicate->execute([
                    'tournament_id' => $tournamentId,
                    'team_name' => $teamName
                ]);

                if ((int) $duplicate->fetchColumn() > 0) {
                    $pdo->rollBack();

                    $errors[] =
                        'A team with this name already exists ' .
                        'in the selected tournament.';
                } else {

                    /*
                     * The club ID comes from the tournament,
                     * never from a browser-submitted value.
                     */
                    $insert = $pdo->prepare(
                        "INSERT INTO teams (
                            t_id,
                            team_name,
                            club_id,
                            created_by,
                            created_at
                        ) VALUES (
                            :t_id,
                            :team_name,
                            :club_id,
                            :created_by,
                            :created_at
                        )"
                    );

                    $insert->execute([
                        't_id' => $tournamentId,
                        'team_name' => $teamName,
                        'club_id' => (int) $selected['club_id'],
                        'created_by' => $userId,
                        'created_at' => $today
                    ]);

                    $pdo->commit();

                    $_SESSION['team_created_flash'] =
                        'Team created successfully.';

                    header(
                        'Location: /tournaments/teams.php',
                        true,
                        303
                    );
                    exit;
                }
            }
        } catch (Throwable $error) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Team creation insertion: ' .
                    $error->getMessage()
            );

            http_response_code(500);

            $errors[] =
                'Unable to create the team. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">

    <section class="tournaments-container section-container">

        <header class="tournaments-heading">

            <p class="section-label">
                NSBM SPORTSHUB / TEAM MANAGEMENT
            </p>

            <h1>Create a team.</h1>

            <p class="section-description">
                Create a participating sports team for
                an upcoming tournament managed by
                your authorized club.
            </p>

        </header>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load tournaments</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif (empty($tournaments)): ?>

            <div class="tournaments-notice">

                <h2>No eligible tournaments</h2>

                <p>
                    No upcoming tournaments are currently
                    available within your authorized clubs.
                    Create an eligible tournament first.
                </p>

                <a
                    href="/tournaments/manage.php"
                    class="button button-outline">
                    Back to Tournament Management
                </a>

            </div>

        <?php else: ?>

            <?php if (!empty($errors)): ?>

                <div class="tournament-form-alert" role="alert">

                    <strong>Please correct these issues:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li>
                                <?= teamCreateEscape($error) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                </div>

            <?php endif; ?>

            <form
                method="post"
                action="/tournaments/create-team.php"
                class="tournament-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= teamCreateEscape($csrfToken) ?>">

                <div class="tournament-form-grid">

                    <div class="tournament-field tournament-field-full">

                        <label for="tournament_id">
                            Select tournament
                        </label>

                        <select
                            id="tournament_id"
                            name="tournament_id"
                            required>

                            <option value="">
                                Select an upcoming tournament
                            </option>

                            <?php foreach ($tournaments as $tournament): ?>

                                <option
                                    value="<?= (int) $tournament['t_id'] ?>"
                                    <?= $selectedTournament ===
                                        (string) $tournament['t_id']
                                        ? 'selected'
                                        : '' ?>>

                                    <?= teamCreateEscape(
                                        $tournament['t_name']
                                    ) ?>
                                    —
                                    <?= teamCreateEscape(
                                        $tournament['club_name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small>
                            Only upcoming tournaments from
                            your authorized clubs are available.
                        </small>

                    </div>

                    <div class="tournament-field tournament-field-full">

                        <label for="team_name">
                            Team name
                        </label>

                        <input
                            type="text"
                            id="team_name"
                            name="team_name"
                            maxlength="255"
                            required
                            placeholder="Enter team name"
                            value="<?= teamCreateEscape($teamName) ?>">

                    </div>

                </div>

                <div class="tournament-form-actions">

                    <button
                        type="submit"
                        class="button button-accent">
                        Create Team
                    </button>

                    <a
                        href="/tournaments/teams.php"
                        class="button button-outline">
                        Cancel
                    </a>

                </div>

            </form>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>