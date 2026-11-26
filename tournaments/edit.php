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
            t.image_path,
            t.is_historical,
            t.reg_open_at,
            t.reg_end_at,
            t.start_date,
            t.end_date,
            t.venue,
            t.status,
            COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published') AS club_name
        FROM tournaments AS t
        LEFT JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
        WHERE t.t_id = :tournament_id
          AND (sc.status = 'active' $scope
              " . ($role === 'admin' ? "OR t.is_historical = 1" : "") . ")
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
    'image_path' => '',
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

if (
    $method === 'POST' &&
    $tournament !== null &&
    $pageError === null
) {
    $editSchedule = !$isLocked && sportsHubInput('edit_schedule') === '1';
    foreach (['t_name', 'des', 'image_path'] as $field) {
        $form[$field] = sportsHubInput($field);
    }
    if (!hash_equals($csrfToken, sportsHubInput('csrf_token'))) {
        $errors[] = 'Invalid form session. Refresh and try again.';
    }
    if (sportsHubLength($form['t_name']) < 1 || sportsHubLength($form['t_name']) > 100 || sportsHubLength($form['des']) > 255) {
        $errors[] = 'Enter a tournament name of up to 100 characters and a description of up to 255 characters.';
    }
    $form['image_path'] = sportsHubCoverPath($form['image_path']);
    if (!array_key_exists($form['image_path'], sportsHubCoverLibrary())) {
        $errors[] = 'Choose a cover from the image library.';
    }
    if (sportsHubInput('edit_schedule') === '1' && $isLocked) {
        $errors[] = 'The schedule is locked. Reload the page before editing.';
    }
    if ($editSchedule) {
        foreach (['venue', 'reg_open_at', 'reg_end_at', 'start_date', 'end_date'] as $field) {
            $form[$field] = sportsHubInput($field);
        }
        if (sportsHubLength($form['venue']) < 1 || sportsHubLength($form['venue']) > 100) {
            $errors[] = 'Venue must contain 1–100 characters.';
        }
        foreach (['reg_open_at', 'reg_end_at', 'start_date', 'end_date'] as $field) {
            if (!sportsHubValidDate($form[$field])) $errors[] = 'Invalid date: ' . str_replace('_', ' ', $field);
        }
        if ($form['reg_open_at'] > $form['reg_end_at'] || $form['reg_end_at'] > $form['start_date'] || $form['start_date'] > $form['end_date'] || $form['start_date'] <= $today) {
            $errors[] = 'Registration and tournament dates must be in order, and the tournament must start after today.';
        }
    }
    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $current = tournamentEditFind($pdo, $tournamentId, $userId, (string) $userRole, true);
            if ($current === null || ($editSchedule && tournamentEditIsLocked($pdo, $current, $today))) {
                $pdo->rollBack();
                http_response_code(409);
                $errors[] = 'Permissions or linked records changed. Reload the tournament.';
            } else {
                $fields = ['t_name' => $form['t_name'], 'des' => $form['des'], 'image_path' => $form['image_path'] ?: null];
                if ($editSchedule) {
                    foreach (['venue', 'reg_open_at', 'reg_end_at', 'start_date', 'end_date'] as $field) $fields[$field] = $form[$field];
                }
                $assignments = implode(', ', array_map(static fn($field) => $field . ' = :' . $field, array_keys($fields)));
                $statement = $pdo->prepare('UPDATE tournaments SET ' . $assignments . ' WHERE t_id = :tournament_id');
                $statement->execute($fields + ['tournament_id' => $tournamentId]);
                $pdo->commit();
                $_SESSION['tournament_edit_success'] = $tournamentId;
                header('Location: /tournaments/edit.php?id=' . $tournamentId, true, 303);
                exit;
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Tournament edit update: ' . $error->getMessage());
            http_response_code(500);
            $errors[] = 'Unable to save your changes. Please try again.';
        }
    }
}

$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';

?>

<main class="tournaments-page sports-hub-workflow">
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
                <p><?= sportsHubEscape($pageError) ?></p>
            </div>

        <?php elseif ($tournament !== null): ?>

            <div class="tournament-edit-context">
                <strong>
                    <?= sportsHubEscape(
                        $tournament['club_name']
                    ) ?>
                </strong>

                <span>
                    <?= sportsHubEscape(
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
                <p class="tournament-edit-context">Schedule and linked records are locked. You can still update the title, description and cover image.</p>
            <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="tournament-form-alert"
                        role="alert">
                        <strong>Please correct these issues:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li>
                                    <?= sportsHubEscape($error) ?>
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
                        value="<?= sportsHubEscape(
                                    $csrfToken
                                ) ?>">

                    <div class="tournament-form-grid">

                        <div class="tournament-field<?= $isLocked ? ' tournament-field-full' : '' ?>">
                            <label for="t_name">
                                Tournament name
                            </label>

                            <input
                                id="t_name"
                                name="t_name"
                                type="text"
                                maxlength="100"
                                required
                                value="<?= sportsHubEscape(
                                            $form['t_name']
                                        ) ?>">
                        </div>

                        <?php if (!$isLocked): ?>
                        <input type="hidden" name="edit_schedule" value="1">
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
                                value="<?= sportsHubEscape(
                                            $form['venue']
                                        ) ?>">
                        </div>

                        <?php endif; ?>
                        <div class="tournament-field tournament-field-full">
                            <label for="des">
                                Description
                            </label>

                            <textarea
                                id="des"
                                name="des"
                                maxlength="255"
                                rows="4"><?= sportsHubEscape(
                                                $form['des']
                                            ) ?></textarea>
                        </div>

                        <div class="tournament-field tournament-field-full">
                            <label for="image_path">Cover image</label>
                            <select id="image_path" name="image_path"><?= sportsHubCoverOptions($form['image_path']) ?></select>
                        </div>

                        <?php if (!$isLocked): ?>
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
                                    <?= sportsHubEscape($label) ?>
                                </label>

                                <input
                                    type="date"
                                    id="<?= $key ?>"
                                    name="<?= $key ?>"
                                    required
                                    value="<?= sportsHubEscape(
                                                $form[$key]
                                            ) ?>">
                            </div>

                        <?php endforeach; ?>
                        <?php endif; ?>

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

    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>