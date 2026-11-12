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

function tournamentEditEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function tournamentEditInput(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function tournamentEditValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

function tournamentEditLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/* Load a tournament only within the user's club scope. */
function tournamentEditFind(
    PDO $pdo,
    int $id,
    int $userId,
    string $role,
    bool $lock = false
): ?array {
    $scope = '';
    $parameters = ['tournament_id' => $id];

    if ($role !== 'admin') {
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

        $parameters['staff_user_id'] = $userId;
        $parameters['staff_role'] = $role;
    }

    $sql = "
        SELECT
            t.t_id,
            t.club_id,
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
          $scope
        LIMIT 1
    ";

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $result = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($result) ? $result : null;
}

/*
 * Block edits when downstream records already exist.
 * Matches may reference a tournament without any teams
 * registered through the tournament management module.
 */
function tournamentEditHasDependencies(
    PDO $pdo,
    int $id
): bool {
    $statement = $pdo->prepare(
        "SELECT
            (
                EXISTS (
                    SELECT 1
                    FROM tournament_registration
                    WHERE t_id = :registration_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM teams
                    WHERE t_id = :team_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM matches
                    WHERE t_id = :match_id
                )
            ) AS has_dependencies"
    );

    $statement->execute([
        'registration_id' => $id,
        'team_id' => $id,
        'match_id' => $id
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function tournamentEditIsLocked(
    PDO $pdo,
    array $tournament,
    string $today
): bool {
    if (
        strtolower(trim((string) $tournament['status']))
        !== 'upcoming'
    ) {
        return true;
    }

    if ((string) $tournament['start_date'] <= $today) {
        return true;
    }

    return tournamentEditHasDependencies(
        $pdo,
        (int) $tournament['t_id']
    );
}

$tournamentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

if (
    !isset($_SESSION['tournament_edit_csrf']) ||
    !is_string($_SESSION['tournament_edit_csrf'])
) {
    $_SESSION['tournament_edit_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tournament_edit_csrf'];

$tournament = null;
$pageError = null;
$errors = [];
$isLocked = false;
$saved = false;
$pdo = null;

$form = [
    't_name' => '',
    'des' => '',
    'reg_open_at' => '',
    'reg_end_at' => '',
    'start_date' => '',
    'end_date' => '',
    'venue' => ''
];

if (!is_int($tournamentId)) {
    http_response_code(400);
    $pageError = 'Invalid tournament ID.';
} else {
    try {
        $pdo = db();

        $tournament = tournamentEditFind(
            $pdo,
            $tournamentId,
            $userId,
            (string) $userRole
        );

        if ($tournament === null) {
            http_response_code(404);
            $pageError = 'Tournament not found or access denied.';
        } else {
            foreach (array_keys($form) as $key) {
                $form[$key] = (string) $tournament[$key];
            }

            $isLocked = tournamentEditIsLocked(
                $pdo,
                $tournament,
                $today
            );

            if (
                ($_SESSION['tournament_edit_success'] ?? null)
                === $tournamentId
            ) {
                $saved = true;
                unset($_SESSION['tournament_edit_success']);
            }
        }
    } catch (Throwable $error) {
        error_log(
            'Tournament edit loading: ' . $error->getMessage()
        );

        http_response_code(500);
        $pageError = 'Unable to load tournament details.';
    }
}

/* Process an authorized modification. */
if (
    $method === 'POST' &&
    $tournament !== null &&
    $pageError === null
) {
    if ($isLocked) {
        http_response_code(409);
        $errors[] = 'This tournament can no longer be edited.';
    } else {
        foreach (array_keys($form) as $field) {
            $form[$field] = tournamentEditInput($field);
        }

        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            $errors[] = 'Invalid form session. Refresh and try again.';
        }

        $limits = [
            't_name' => ['Tournament name', 100],
            'des' => ['Description', 255],
            'venue' => ['Venue', 100]
        ];

        foreach ($limits as $key => [$label, $maximum]) {
            $length = tournamentEditLength($form[$key]);

            if ($length === 0 || $length > $maximum) {
                $errors[] =
                    "$label must contain 1–$maximum characters.";
            }
        }

        $dateFields = [
            'reg_open_at',
            'reg_end_at',
            'start_date',
            'end_date'
        ];

        $datesValid = true;

        foreach ($dateFields as $field) {
            if (!tournamentEditValidDate($form[$field])) {
                $errors[] = 'Invalid date: ' .
                    str_replace('_', ' ', $field);
                $datesValid = false;
            }
        }

        if ($datesValid) {
            if ($form['reg_open_at'] > $form['reg_end_at']) {
                $errors[] = 'Registration dates are out of order.';
            }

            if ($form['reg_end_at'] > $form['start_date']) {
                $errors[] =
                    'Registration must close by the start date.';
            }

            if ($form['start_date'] > $form['end_date']) {
                $errors[] =
                    'Tournament end date must follow its start date.';
            }

            if ($form['start_date'] <= $today) {
                $errors[] =
                    'The tournament must start after today.';
            }
        }

        if (empty($errors)) {
            try {
                /*
                 * Lock the parent tournament row, then
                 * recheck authorization and dependencies.
                 * Foreign-key inserts referencing this row
                 * must wait for the transaction to finish.
                 */
                $pdo->beginTransaction();

                $current = tournamentEditFind(
                    $pdo,
                    $tournamentId,
                    $userId,
                    (string) $userRole,
                    true
                );

                if (
                    $current === null ||
                    tournamentEditIsLocked(
                        $pdo,
                        $current,
                        $today
                    )
                ) {
                    $pdo->rollBack();
                    http_response_code(409);

                    $errors[] =
                        'Tournament permissions or linked records ' .
                        'have changed. Editing is unavailable.';
                } else {
                    $statement = $pdo->prepare(
                        "UPDATE tournaments
                         SET
                            t_name = :t_name,
                            des = :description,
                            reg_open_at = :reg_open_at,
                            reg_end_at = :reg_end_at,
                            start_date = :start_date,
                            end_date = :end_date,
                            venue = :venue
                         WHERE t_id = :tournament_id"
                    );

                    $statement->execute([
                        't_name' => $form['t_name'],
                        'description' => $form['des'],
                        'reg_open_at' => $form['reg_open_at'],
                        'reg_end_at' => $form['reg_end_at'],
                        'start_date' => $form['start_date'],
                        'end_date' => $form['end_date'],
                        'venue' => $form['venue'],
                        'tournament_id' => $tournamentId
                    ]);

                    $pdo->commit();

                    $_SESSION['tournament_edit_success'] =
                        $tournamentId;

                    header(
                        'Location: /tournaments/edit.php?id=' .
                            $tournamentId,
                        true,
                        303
                    );
                    exit;
                }
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Tournament edit update: ' .
                        $error->getMessage()
                );

                http_response_code(500);
                $errors[] =
                    'Unable to save your changes. Please try again.';
            }
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
                NSBM SPORTSHUB / TOURNAMENT ADMINISTRATION
            </p>

            <h1>Edit tournament.</h1>

            <p class="section-description">
                Update tournament details while preserving
                registrations, participating teams and fixtures.
            </p>
        </header>

        <?php if ($pageError !== null): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to open tournament</h2>
                <p><?= tournamentEditEscape($pageError) ?></p>
            </div>

        <?php elseif ($tournament !== null): ?>

            <div class="tournament-edit-context">
                <strong>
                    <?= tournamentEditEscape(
                        $tournament['club_name']
                    ) ?>
                </strong>

                <span>
                    <?= tournamentEditEscape(
                        $tournament['sport']
                    ) ?>
                </span>
            </div>

            <?php if ($saved): ?>
                <div class="tournament-form-success"
                    role="status">
                    Tournament changes saved successfully.
                </div>
            <?php endif; ?>

            <?php if ($isLocked): ?>

                <div class="tournaments-notice">
                    <h2>Editing unavailable</h2>
                    <p>
                        Only upcoming tournaments with a
                        future start date and no existing
                        registrations, teams or fixtures
                        may be edited.
                    </p>

                    <a
                        class="button button-outline"
                        href="/tournaments/manage.php">
                        Back to Management
                    </a>
                </div>

            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="tournament-form-alert"
                        role="alert">
                        <strong>Please correct these issues:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li>
                                    <?= tournamentEditEscape($error) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    action="/tournaments/edit.php?id=<?= $tournamentId ?>"
                    class="tournament-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= tournamentEditEscape(
                                    $csrfToken
                                ) ?>">

                    <div class="tournament-form-grid">

                        <div class="tournament-field">
                            <label for="t_name">
                                Tournament name
                            </label>

                            <input
                                id="t_name"
                                name="t_name"
                                type="text"
                                maxlength="100"
                                required
                                value="<?= tournamentEditEscape(
                                            $form['t_name']
                                        ) ?>">
                        </div>

                        <div class="tournament-field">
                            <label for="venue">
                                Tournament venue
                            </label>

                            <input
                                id="venue"
                                name="venue"
                                type="text"
                                maxlength="100"
                                required
                                value="<?= tournamentEditEscape(
                                            $form['venue']
                                        ) ?>">
                        </div>

                        <div class="tournament-field tournament-field-full">
                            <label for="des">
                                Description
                            </label>

                            <textarea
                                id="des"
                                name="des"
                                maxlength="255"
                                rows="4"
                                required><?= tournamentEditEscape(
                                                $form['des']
                                            ) ?></textarea>
                        </div>

                        <?php
                        $dateLabels = [
                            'reg_open_at' => 'Registration opens',
                            'reg_end_at' => 'Registration closes',
                            'start_date' => 'Tournament starts',
                            'end_date' => 'Tournament ends'
                        ];
                        ?>

                        <?php foreach ($dateLabels as $key => $label): ?>

                            <div class="tournament-field">
                                <label for="<?= $key ?>">
                                    <?= tournamentEditEscape($label) ?>
                                </label>

                                <input
                                    type="date"
                                    id="<?= $key ?>"
                                    name="<?= $key ?>"
                                    required
                                    value="<?= tournamentEditEscape(
                                                $form[$key]
                                            ) ?>">
                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div class="tournament-form-actions">
                        <button
                            type="submit"
                            class="button button-accent">
                            Save Changes
                        </button>

                        <a
                            href="/tournaments/manage.php"
                            class="button button-outline">
                            Cancel
                        </a>
                    </div>

                </form>

            <?php endif; ?>

        <?php endif; ?>

    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>