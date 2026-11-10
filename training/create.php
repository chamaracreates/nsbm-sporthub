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

$isAdmin = $userRole === 'admin';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Escape dynamic content for HTML output. */
function trainingCreateEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* Read a string value from a submitted form. */
function trainingCreateInput(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

/* Recognize physical facilities from their configured resource type. */
function trainingIsPhysicalVenue(string $type): bool
{
    $type = strtolower(str_replace(['_', '-'], ' ', $type));

    if (
        preg_match(
            '/\b(equipment|gear|kit|ball|bat|racket|racquet)\b/',
            $type
        )
    ) {
        return false;
    }

    return (bool) preg_match(
        '/\b(facility|facilities|court|ground|grounds|field|gym|' .
            'gymnasium|hall|stadium|pool|track|arena|pitch|venue|' .
            'complex|indoor|outdoor)\b/',
        $type
    );
}

/* Initialize CSRF protection. */
if (
    !isset($_SESSION['training_create_csrf']) ||
    !is_string($_SESSION['training_create_csrf'])
) {
    $_SESSION['training_create_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['training_create_csrf'];

$clubs = [];
$venues = [];
$errors = [];
$loadError = false;

$form = [
    'club_id' => '',
    'location' => '',
    'title' => '',
    'date' => '',
    'start_time' => '',
    'end_time' => '',
    'description' => ''
];

/* Retrieve confirmation from a completed submission. */
$successMessage = $_SESSION['training_create_flash'] ?? null;
unset($_SESSION['training_create_flash']);

try {
    $pdo = db();

    /* Retrieve clubs that the current staff member may manage. */
    if ($isAdmin) {
        $clubStatement = $pdo->query(
            "SELECT club_id, club_name
             FROM sports_clubs
             WHERE status = 'active'
             ORDER BY club_name"
        );
    } else {
        $clubStatement = $pdo->prepare(
            "SELECT sc.club_id, sc.club_name
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

        $clubStatement->execute([
            'user_id' => $userId,
            'staff_role' => $userRole
        ]);
    }

    $clubs = $clubStatement->fetchAll(PDO::FETCH_ASSOC);

    /* Retrieve active resources with verified venue information. */
    $venueStatement = $pdo->query(
        "SELECT res_id, res_name, res_type, location
         FROM resources
         WHERE LOWER(TRIM(res_status)) = 'active'
         ORDER BY res_name"
    );

    foreach ($venueStatement->fetchAll(PDO::FETCH_ASSOC) as $resource) {
        if (
            trainingIsPhysicalVenue((string) $resource['res_type']) &&
            trim((string) ($resource['location'] ?? '')) !== ''
        ) {
            $venues[] = $resource;
        }
    }
} catch (Throwable $error) {
    error_log(
        'Training creation data error: ' . $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

/* Validate and process a new training session. */
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
    !$loadError
) {
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission.');
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = trainingCreateInput($field);
    }

    $clubId = filter_var(
        $form['club_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $venueId = filter_var(
        $form['location'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $validClubIds = array_map(
        static fn(array $club): int => (int) $club['club_id'],
        $clubs
    );

    $validVenueIds = array_map(
        static fn(array $venue): int => (int) $venue['res_id'],
        $venues
    );

    /* Validate the assigned club and selected facility. */
    if (
        !is_int($clubId) ||
        !in_array($clubId, $validClubIds, true)
    ) {
        $errors[] = 'Select an authorized sports club.';
    }

    if (
        !is_int($venueId) ||
        !in_array($venueId, $validVenueIds, true)
    ) {
        $errors[] = 'Select an available physical facility.';
    }

    /* Validate training session information. */
    if (
        $form['title'] === '' ||
        strlen($form['title']) > 50
    ) {
        $errors[] = 'The title must contain 1 to 50 characters.';
    }

    if (
        $form['description'] === '' ||
        strlen($form['description']) > 255
    ) {
        $errors[] = 'The description must contain 1 to 255 characters.';
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $form['date']
    );

    if (
        $date === false ||
        $date->format('Y-m-d') !== $form['date']
    ) {
        $errors[] = 'Enter a valid training date.';
    }

    $timePattern = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/';

    $startValid = (bool) preg_match(
        $timePattern,
        $form['start_time']
    );

    $endValid = (bool) preg_match(
        $timePattern,
        $form['end_time']
    );

    if (!$startValid || !$endValid) {
        $errors[] = 'Enter valid start and end times.';
    } elseif ($form['end_time'] <= $form['start_time']) {
        $errors[] = 'The end time must be later than the start time.';
    }

    if (
        empty($clubs) ||
        empty($venues)
    ) {
        $errors[] = 'A managed club and configured venue are required.';
    }

    /* Save only when all submitted values are valid. */
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /* Recheck club authorization before insertion. */
            $authorizationSql = "
                SELECT sc.club_id
                FROM sports_clubs AS sc
                WHERE sc.club_id = :club_id
                  AND sc.status = 'active'
            ";

            $authorizationParams = [
                'club_id' => $clubId
            ];

            if (!$isAdmin) {
                $authorizationSql .= "
                    AND EXISTS (
                        SELECT 1
                        FROM sport_staff AS ss
                        WHERE ss.club_id = sc.club_id
                          AND ss.user_id = :user_id
                          AND ss.staff_role = :staff_role
                          AND ss.status = 'active'
                    )
                ";

                $authorizationParams['user_id'] = $userId;
                $authorizationParams['staff_role'] = $userRole;
            }

            $authorizationStatement = $pdo->prepare(
                $authorizationSql
            );

            $authorizationStatement->execute(
                $authorizationParams
            );

            if (!$authorizationStatement->fetchColumn()) {
                throw new DomainException(
                    'You are not authorized to schedule training for this club.'
                );
            }

            /* Lock the selected resource while checking availability. */
            $venueStatement = $pdo->prepare(
                "SELECT res_id, res_type, location
                 FROM resources
                 WHERE res_id = :venue_id
                   AND LOWER(TRIM(res_status)) = 'active'
                 FOR UPDATE"
            );

            $venueStatement->execute([
                'venue_id' => $venueId
            ]);

            $venue = $venueStatement->fetch(PDO::FETCH_ASSOC);

            if (
                !$venue ||
                !trainingIsPhysicalVenue((string) $venue['res_type']) ||
                trim((string) ($venue['location'] ?? '')) === ''
            ) {
                throw new DomainException(
                    'The selected facility is no longer available.'
                );
            }

            /* Use the database clock to validate future scheduling. */
            $futureStatement = $pdo->prepare(
                "SELECT
                    CAST(:starts_at AS DATETIME) > NOW()"
            );

            $futureStatement->execute([
                'starts_at' => $form['date'] . ' ' .
                    $form['start_time'] . ':00'
            ]);

            if ((int) $futureStatement->fetchColumn() !== 1) {
                throw new DomainException(
                    'Training sessions must start in the future.'
                );
            }

            /* Prevent overlapping training sessions at the same venue. */
            $trainingConflict = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM training_sessions
                 WHERE location = :venue_id
                   AND date = :session_date
                   AND start_time < :end_time
                   AND end_time > :start_time"
            );

            $trainingConflict->execute([
                'venue_id' => $venueId,
                'session_date' => $form['date'],
                'end_time' => $form['end_time'],
                'start_time' => $form['start_time']
            ]);

            if ((int) $trainingConflict->fetchColumn() > 0) {
                throw new DomainException(
                    'Another training session already uses this facility during the selected time.'
                );
            }

            /* Respect existing facility booking requests. */
            $bookingConflict = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM booking_request
                 WHERE res_id = :venue_id
                   AND date = :booking_date
                   AND starts_at < :end_time
                   AND ends_at > :start_time
                   AND LOWER(TRIM(booking_status)) NOT IN (
                       'rejected',
                       'declined',
                       'denied',
                       'cancelled',
                       'canceled'
                   )"
            );

            $bookingConflict->execute([
                'venue_id' => $venueId,
                'booking_date' => $form['date'],
                'end_time' => $form['end_time'],
                'start_time' => $form['start_time']
            ]);

            if ((int) $bookingConflict->fetchColumn() > 0) {
                throw new DomainException(
                    'This facility has an existing booking request during the selected time.'
                );
            }

            /* Insert the validated training session. */
            $insertStatement = $pdo->prepare(
                "INSERT INTO training_sessions (
                    club_id,
                    title,
                    date,
                    start_time,
                    end_time,
                    location,
                    description
                ) VALUES (
                    :club_id,
                    :title,
                    :session_date,
                    :start_time,
                    :end_time,
                    :location,
                    :description
                )"
            );

            $insertStatement->execute([
                'club_id' => $clubId,
                'title' => $form['title'],
                'session_date' => $form['date'],
                'start_time' => $form['start_time'],
                'end_time' => $form['end_time'],
                'location' => $venueId,
                'description' => $form['description']
            ]);

            $pdo->commit();

            $_SESSION['training_create_flash'] =
                'Training session created successfully.';

            header(
                'Location: /training/create.php',
                true,
                303
            );

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

            error_log(
                'Training creation error: ' . $error->getMessage()
            );

            http_response_code(500);

            $errors[] =
                'Unable to save the training session. Please try again.';
        }
    }
}

/* Load the existing SportsHub layout. */
require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/training.css">

<main class="training-page">
    <div class="training-container">

        <section class="training-introduction">
            <p class="training-eyebrow">TRAINING MANAGEMENT</p>

            <h1>Create Training Session</h1>

            <p class="training-intro-text">
                Schedule training sessions for authorized sports
                clubs using configured NSBM facilities.
            </p>
        </section>

        <?php if (is_string($successMessage)): ?>
            <div class="training-form-success" role="status">
                <?= trainingCreateEscape($successMessage) ?>

                <p>
                    <a href="/training/manage.php">
                        View Training Management
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($loadError): ?>

            <div class="training-notice" role="alert">
                <h3>Unable to load scheduling information</h3>
                <p>Please try again later.</p>
            </div>

        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="training-form-alert" role="alert">
                    <strong>Please review the following:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= trainingCreateEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($clubs)): ?>
                <div class="training-notice">
                    <h3>No authorized sports clubs available</h3>
                    <p>
                        An active sports club and an authorized staff
                        assignment are required before scheduling
                        training sessions.
                    </p>
                </div>
            <?php endif; ?>

            <?php if (empty($venues)): ?>
                <div class="training-notice training-venue-notice">
                    <h3>No configured training facilities available</h3>
                    <p>
                        Active physical facilities with recorded
                        locations must be configured before
                        training sessions can be scheduled.
                    </p>
                </div>
            <?php endif; ?>

            <?php if (!empty($clubs) && !empty($venues)): ?>

                <section aria-labelledby="training-form-title">

                    <div class="training-section-heading">
                        <div>
                            <p class="training-eyebrow">
                                SESSION DETAILS
                            </p>

                            <h2 id="training-form-title">
                                Training Schedule
                            </h2>
                        </div>
                    </div>

                    <form
                        action="/training/create.php"
                        method="POST"
                        class="training-form">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= trainingCreateEscape($csrfToken) ?>">

                        <div class="training-form-grid">

                            <div class="training-field">
                                <label for="training-club">
                                    Sports Club
                                </label>

                                <select
                                    id="training-club"
                                    name="club_id"
                                    required>
                                    <option value="">
                                        Select a sports club
                                    </option>

                                    <?php foreach ($clubs as $club): ?>
                                        <?php
                                        $id = (int) $club['club_id'];
                                        ?>

                                        <option
                                            value="<?= $id ?>"
                                            <?= (string) $id === $form['club_id']
                                                ? 'selected'
                                                : '' ?>>
                                            <?= trainingCreateEscape(
                                                $club['club_name']
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="training-field">
                                <label for="training-venue">
                                    Training Facility
                                </label>

                                <select
                                    id="training-venue"
                                    name="location"
                                    required>
                                    <option value="">
                                        Select a facility
                                    </option>

                                    <?php foreach ($venues as $venue): ?>
                                        <?php
                                        $id = (int) $venue['res_id'];
                                        ?>

                                        <option
                                            value="<?= $id ?>"
                                            <?= (string) $id === $form['location']
                                                ? 'selected'
                                                : '' ?>>
                                            <?= trainingCreateEscape(
                                                $venue['res_name'] .
                                                    ' — ' .
                                                    $venue['location']
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="training-field training-field-full">
                                <label for="training-title">
                                    Session Title
                                </label>

                                <input
                                    type="text"
                                    id="training-title"
                                    name="title"
                                    maxlength="50"
                                    required
                                    placeholder="Enter training session title"
                                    value="<?= trainingCreateEscape(
                                                $form['title']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="training-date">
                                    Training Date
                                </label>

                                <input
                                    type="date"
                                    id="training-date"
                                    name="date"
                                    min="<?= date('Y-m-d') ?>"
                                    required
                                    value="<?= trainingCreateEscape(
                                                $form['date']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="training-start">
                                    Start Time
                                </label>

                                <input
                                    type="time"
                                    id="training-start"
                                    name="start_time"
                                    required
                                    value="<?= trainingCreateEscape(
                                                $form['start_time']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="training-end">
                                    End Time
                                </label>

                                <input
                                    type="time"
                                    id="training-end"
                                    name="end_time"
                                    required
                                    value="<?= trainingCreateEscape(
                                                $form['end_time']
                                            ) ?>">
                            </div>

                            <div class="training-field training-field-full">
                                <label for="training-description">
                                    Description
                                </label>

                                <textarea
                                    id="training-description"
                                    name="description"
                                    rows="5"
                                    maxlength="255"
                                    required
                                    placeholder="Enter training details and instructions"><?= trainingCreateEscape(
                                                                                                $form['description']
                                                                                            ) ?></textarea>
                            </div>

                        </div>

                        <div class="training-form-actions">
                            <button
                                type="submit"
                                class="training-primary-button">
                                Create Training Session
                            </button>

                            <a
                                href="/training/manage.php"
                                class="training-secondary-link">
                                Back to Training Management
                            </a>
                        </div>

                    </form>
                </section>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</main>

<?php

/* Load the existing SportsHub footer. */
require __DIR__ . '/../includes/footer.php';
