<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/* Restrict tournament creation to authorized staff. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$isAdmin = $userRole === 'admin';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

function tournamentCreateEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function tournamentCreateInput(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function tournamentCreateValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

function tournamentCreateLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/* Session-based CSRF protection. */
if (
    !isset($_SESSION['tournament_create_csrf']) ||
    !is_string($_SESSION['tournament_create_csrf'])
) {
    $_SESSION['tournament_create_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['tournament_create_csrf'];

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

$clubs = [];
$eligibleClubs = [];
$errors = [];
$loadError = false;

$form = [
    'club_id' => '',
    't_name' => '',
    'des' => '',
    'reg_open_at' => '',
    'reg_end_at' => '',
    'start_date' => '',
    'end_date' => '',
    'venue' => ''
];

/* Load only the clubs this account can manage. */
try {
    $pdo = db();

    if ($isAdmin) {
        $statement = $pdo->query(
            "SELECT club_id, club_name, sport
             FROM sports_clubs
             WHERE status = 'active'
             ORDER BY club_name"
        );
    } else {
        $statement = $pdo->prepare(
            "SELECT sc.club_id, sc.club_name, sc.sport
             FROM sports_clubs AS sc
             WHERE sc.status = 'active'
               AND EXISTS (
                   SELECT 1
                   FROM sport_staff AS ss
                   WHERE ss.club_id = sc.club_id
                     AND ss.user_id = :user_id
                     AND ss.staff_role = :staff_role
                     AND ss.status = 'active'
               )
             ORDER BY sc.club_name"
        );

        $statement->execute([
            'user_id' => $userId,
            'staff_role' => $userRole
        ]);
    }

    $clubs = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clubs as $club) {
        $eligibleClubs[(int) $club['club_id']] = $club;
    }
} catch (Throwable $error) {
    error_log(
        'Tournament creation club query: ' .
            $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

/* Validate and process submitted tournament data. */
if ($method === 'POST' && !$loadError) {

    foreach (array_keys($form) as $field) {
        $form[$field] = tournamentCreateInput($field);
    }

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = 'Invalid form session. Refresh and try again.';
    }

    $clubId = filter_var(
        $form['club_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_int($clubId) ||
        !isset($eligibleClubs[$clubId])
    ) {
        $errors[] = 'Select an authorized sports club.';
    }

    $limits = [
        't_name' => ['Tournament name', 100],
        'des' => ['Description', 255],
        'venue' => ['Venue', 100]
    ];

    foreach ($limits as $key => [$label, $maximum]) {
        $length = tournamentCreateLength($form[$key]);

        if ($length === 0 || $length > $maximum) {
            $errors[] = "$label must contain 1–$maximum characters.";
        }
    }

    $dateFields = [
        'reg_open_at' => 'Registration opening date',
        'reg_end_at' => 'Registration closing date',
        'start_date' => 'Tournament start date',
        'end_date' => 'Tournament end date'
    ];

    $validDates = true;

    foreach ($dateFields as $key => $label) {
        if (!tournamentCreateValidDate($form[$key])) {
            $errors[] = "$label is invalid.";
            $validDates = false;
        }
    }

    if ($validDates) {
        if ($form['reg_open_at'] > $form['reg_end_at']) {
            $errors[] = 'Registration opening must be on or before closing.';
        }

        if ($form['reg_end_at'] > $form['start_date']) {
            $errors[] = 'Registration must close by the tournament start date.';
        }

        if ($form['start_date'] > $form['end_date']) {
            $errors[] = 'Tournament end date must not precede its start date.';
        }

        if ($form['start_date'] <= $today) {
            $errors[] = 'New tournaments must begin after today.';
        }
    }

    if (empty($errors)) {
        try {
            /*
             * Recheck authorization immediately before insertion.
             * Do not trust the club selection from the browser.
             */
            if ($isAdmin) {
                $check = $pdo->prepare(
                    "SELECT sport
                     FROM sports_clubs
                     WHERE club_id = :club_id
                       AND status = 'active'"
                );

                $check->execute([
                    'club_id' => $clubId
                ]);
            } else {
                $check = $pdo->prepare(
                    "SELECT sc.sport
                     FROM sports_clubs AS sc
                     WHERE sc.club_id = :club_id
                       AND sc.status = 'active'
                       AND EXISTS (
                           SELECT 1
                           FROM sport_staff AS ss
                           WHERE ss.club_id = sc.club_id
                             AND ss.user_id = :user_id
                             AND ss.staff_role = :staff_role
                             AND ss.status = 'active'
                       )"
                );

                $check->execute([
                    'club_id' => $clubId,
                    'user_id' => $userId,
                    'staff_role' => $userRole
                ]);
            }

            $sport = $check->fetchColumn();

            if ($sport === false) {
                $errors[] = 'Your permission for this club is no longer valid.';
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO tournaments (
                        club_id,
                        t_name,
                        sport,
                        des,
                        reg_open_at,
                        reg_end_at,
                        start_date,
                        end_date,
                        venue,
                        status,
                        created_by
                    ) VALUES (
                        :club_id,
                        :t_name,
                        :sport,
                        :description,
                        :reg_open_at,
                        :reg_end_at,
                        :start_date,
                        :end_date,
                        :venue,
                        'upcoming',
                        :created_by
                    )"
                );

                $insert->execute([
                    'club_id' => $clubId,
                    't_name' => $form['t_name'],
                    'sport' => $sport,
                    'description' => $form['des'],
                    'reg_open_at' => $form['reg_open_at'],
                    'reg_end_at' => $form['reg_end_at'],
                    'start_date' => $form['start_date'],
                    'end_date' => $form['end_date'],
                    'venue' => $form['venue'],
                    'created_by' => $userId
                ]);

                $newId = (int) $pdo->lastInsertId();

                header(
                    'Location: /tournaments/view.php?id=' . $newId,
                    true,
                    303
                );
                exit;
            }
        } catch (Throwable $error) {
            error_log(
                'Tournament creation insert: ' .
                    $error->getMessage()
            );

            $errors[] = 'Unable to save the tournament. Please try again.';
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

            <h1>Create a tournament.</h1>

            <p class="section-description">
                Schedule an official sports tournament
                for a club you are authorized to manage.
            </p>
        </header>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load tournament settings</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif (empty($clubs)): ?>

            <div class="tournaments-notice">
                <h2>No authorized clubs available</h2>
                <p>
                    You need an active sports club or an
                    eligible staff assignment before creating
                    a tournament.
                </p>
            </div>

        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="tournament-form-alert" role="alert">
                    <strong>Please correct these issues:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li>
                                <?= tournamentCreateEscape($error) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="/tournaments/create.php"
                class="tournament-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= tournamentCreateEscape($csrfToken) ?>">

                <div class="tournament-form-grid">

                    <div class="tournament-field">
                        <label for="club_id">Sports club</label>

                        <select
                            id="club_id"
                            name="club_id"
                            required>

                            <option value="">
                                Select a sports club
                            </option>

                            <?php foreach ($clubs as $club): ?>
                                <option
                                    value="<?= (int) $club['club_id'] ?>"
                                    <?= $form['club_id'] === (string) $club['club_id']
                                        ? 'selected'
                                        : '' ?>>
                                    <?= tournamentCreateEscape(
                                        $club['club_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <small>
                            The tournament sport is determined
                            by the selected club.
                        </small>
                    </div>

                    <div class="tournament-field">
                        <label for="t_name">
                            Tournament name
                        </label>

                        <input
                            type="text"
                            id="t_name"
                            name="t_name"
                            maxlength="100"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['t_name']
                                    ) ?>">
                    </div>

                    <div class="tournament-field tournament-field-full">
                        <label for="des">
                            Tournament description
                        </label>

                        <textarea
                            id="des"
                            name="des"
                            maxlength="255"
                            rows="4"
                            required><?= tournamentCreateEscape(
                                            $form['des']
                                        ) ?></textarea>
                    </div>

                    <div class="tournament-field">
                        <label for="reg_open_at">
                            Registration opens
                        </label>

                        <input
                            type="date"
                            id="reg_open_at"
                            name="reg_open_at"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['reg_open_at']
                                    ) ?>">
                    </div>

                    <div class="tournament-field">
                        <label for="reg_end_at">
                            Registration closes
                        </label>

                        <input
                            type="date"
                            id="reg_end_at"
                            name="reg_end_at"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['reg_end_at']
                                    ) ?>">
                    </div>

                    <div class="tournament-field">
                        <label for="start_date">
                            Tournament start date
                        </label>

                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            min="<?= tournamentCreateEscape($today) ?>"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['start_date']
                                    ) ?>">
                    </div>

                    <div class="tournament-field">
                        <label for="end_date">
                            Tournament end date
                        </label>

                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['end_date']
                                    ) ?>">
                    </div>

                    <div class="tournament-field tournament-field-full">
                        <label for="venue">
                            Tournament venue
                        </label>

                        <input
                            type="text"
                            id="venue"
                            name="venue"
                            maxlength="100"
                            required
                            value="<?= tournamentCreateEscape(
                                        $form['venue']
                                    ) ?>">
                    </div>

                </div>

                <div class="tournament-form-actions">
                    <button
                        type="submit"
                        class="button button-accent">
                        Create Tournament
                    </button>

                    <a
                        href="/tournaments/index.php"
                        class="button button-outline">
                        Cancel
                    </a>
                </div>

            </form>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>