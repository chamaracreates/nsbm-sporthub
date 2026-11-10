<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/timezone.php';

/* Restrict session editing to authorized staff. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$isAdmin = $userRole === 'admin';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Escape dynamic output. */
function trainingEditEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* Retrieve a submitted text value. */
function trainingEditInput(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

/* Validate the selected session identifier. */
$rawSessionId = $requestMethod === 'POST'
    ? ($_POST['session_id'] ?? null)
    : ($_GET['session_id'] ?? null);

$sessionId = filter_var(
    $rawSessionId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($sessionId)) {
    http_response_code(400);
    exit('Invalid training session.');
}

/* Initialize CSRF protection. */
if (
    !isset($_SESSION['training_edit_csrf']) ||
    !is_string($_SESSION['training_edit_csrf'])
) {
    $_SESSION['training_edit_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['training_edit_csrf'];

$session = null;
$errors = [];
$loadError = false;
$canEdit = false;

$feedback = $_SESSION['training_edit_feedback'] ?? null;
unset($_SESSION['training_edit_feedback']);

try {
    $pdo = trainingDb();

    /* Retrieve the session within the authorized club scope. */
    $statement = $pdo->prepare(
        "SELECT
            ts.session_id,
            ts.club_id,
            ts.title,
            ts.date,
            ts.start_time,
            ts.end_time,
            ts.location,
            ts.description,
            sc.club_name,
            r.res_name,
            r.location AS venue_address,
            (
                TIMESTAMP(ts.date, ts.start_time) > NOW()
            ) AS is_future,
            EXISTS (
                SELECT 1
                FROM training_attendance AS ta
                WHERE ta.session_id = ts.session_id
            ) AS has_attendance
        FROM training_sessions AS ts
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = ts.club_id
        LEFT JOIN resources AS r
            ON r.res_id = ts.location
        WHERE ts.session_id = :session_id
            AND sc.status = 'active'
            AND (
                :is_admin = 1
                OR EXISTS (
                    SELECT 1
                    FROM sport_staff AS ss
                    WHERE ss.club_id = ts.club_id
                        AND ss.user_id = :user_id
                        AND ss.staff_role = :staff_role
                        AND ss.status = 'active'
                )
            )
        LIMIT 1"
    );

    $statement->execute([
        'session_id' => $sessionId,
        'is_admin' => $isAdmin ? 1 : 0,
        'user_id' => $userId,
        'staff_role' => $userRole
    ]);

    $session = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(404);
        exit('Training session not found or access denied.');
    }

    /* Protect sessions that have started or have attendance records. */
    $canEdit = (
        (int) $session['is_future'] === 1 &&
        (int) $session['has_attendance'] === 0
    );
} catch (Throwable $error) {
    error_log(
        'Training session edit loading error: ' .
            $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

$form = [
    'title' => (string) ($session['title'] ?? ''),
    'date' => (string) ($session['date'] ?? ''),
    'start_time' => substr(
        (string) ($session['start_time'] ?? ''),
        0,
        5
    ),
    'end_time' => substr(
        (string) ($session['end_time'] ?? ''),
        0,
        5
    ),
    'description' => (string) ($session['description'] ?? '')
];

/* Process authorized updates. */
if ($requestMethod === 'POST' && !$loadError) {

    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission.');
    }

    if (!$canEdit) {
        http_response_code(403);
        exit('This training session can no longer be edited.');
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = trainingEditInput($field);
    }

    /* Validate session title and description. */
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

    /* Validate the submitted date. */
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

    /* Validate the session time range. */
    $timePattern = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/';

    if (
        !preg_match($timePattern, $form['start_time']) ||
        !preg_match($timePattern, $form['end_time'])
    ) {
        $errors[] = 'Enter valid start and end times.';
    } elseif ($form['end_time'] <= $form['start_time']) {
        $errors[] = 'End time must be later than start time.';
    }

    if (empty($errors)) {
        try {
            /* Read the latest committed scheduling information. */
            $pdo->exec(
                'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'
            );

            $pdo->beginTransaction();

            /* Identify the facility before locking the session. */
            $venueLookup = $pdo->prepare(
                "SELECT location
                 FROM training_sessions
                 WHERE session_id = :session_id
                 LIMIT 1"
            );

            $venueLookup->execute([
                'session_id' => $sessionId
            ]);

            $initialVenueId = $venueLookup->fetchColumn();

            if ($initialVenueId === false) {
                throw new DomainException(
                    'This training session is no longer available.'
                );
            }

            $venueId = (int) $initialVenueId;

            /* Lock the facility before locking the session. */
            $venueStatement = $pdo->prepare(
                "SELECT res_id
                 FROM resources
                 WHERE res_id = :venue_id
                   AND LOWER(TRIM(res_status)) = 'active'
                 FOR UPDATE"
            );

            $venueStatement->execute([
                'venue_id' => $venueId
            ]);

            if (!$venueStatement->fetchColumn()) {
                throw new DomainException(
                    'The assigned facility is no longer available.'
                );
            }

            /* Lock the existing training session. */
            $statement = $pdo->prepare(
                "SELECT
                    ts.club_id,
                    ts.location,
                    ts.date,
                    ts.start_time,
                    sc.status AS club_status
                FROM training_sessions AS ts
                INNER JOIN sports_clubs AS sc
                    ON sc.club_id = ts.club_id
                WHERE ts.session_id = :session_id
                FOR UPDATE"
            );

            $statement->execute([
                'session_id' => $sessionId
            ]);

            $existing = $statement->fetch(PDO::FETCH_ASSOC);

            if (
                !$existing ||
                $existing['club_status'] !== 'active'
            ) {
                throw new DomainException(
                    'This training session is no longer available.'
                );
            }

            /* Recheck club-level authorization. */
            if (!$isAdmin) {
                $statement = $pdo->prepare(
                    "SELECT 1
                    FROM sport_staff
                    WHERE club_id = :club_id
                        AND user_id = :user_id
                        AND staff_role = :staff_role
                        AND status = 'active'
                    LIMIT 1"
                );

                $statement->execute([
                    'club_id' => (int) $existing['club_id'],
                    'user_id' => $userId,
                    'staff_role' => $userRole
                ]);

                if (!$statement->fetchColumn()) {
                    throw new DomainException(
                        'You are no longer authorized to edit this session.'
                    );
                }
            }

            /* Prevent changes after the original session starts. */
            $statement = $pdo->prepare(
                "SELECT TIMESTAMP(
                    :session_date,
                    :start_time
                ) > NOW()"
            );

            $statement->execute([
                'session_date' => $existing['date'],
                'start_time' => $existing['start_time']
            ]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new DomainException(
                    'A session cannot be edited after it starts.'
                );
            }

            /* Prevent editing a session with attendance records. */
            $statement = $pdo->prepare(
                "SELECT COUNT(*)
                FROM training_attendance
                WHERE session_id = :session_id"
            );

            $statement->execute([
                'session_id' => $sessionId
            ]);

            if ((int) $statement->fetchColumn() > 0) {
                throw new DomainException(
                    'A session with attendance records cannot be edited.'
                );
            }

            /* Verify that the session still uses the locked facility. */
            if ((int) $existing['location'] !== $venueId) {
                throw new DomainException(
                    'The assigned facility changed. Reload and try again.'
                );
            }

            /* Require the revised schedule to start in the future. */
            $statement = $pdo->prepare(
                "SELECT TIMESTAMP(
                    :session_date,
                    :start_time
                ) > NOW()"
            );

            $statement->execute([
                'session_date' => $form['date'],
                'start_time' => $form['start_time'] . ':00'
            ]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new DomainException(
                    'Training sessions must start in the future.'
                );
            }

            /* Check overlapping training sessions at the same venue. */
            $statement = $pdo->prepare(
                "SELECT COUNT(*)
                FROM training_sessions
                WHERE location = :venue_id
                    AND date = :session_date
                    AND start_time < :end_time
                    AND end_time > :start_time
                    AND session_id <> :session_id"
            );

            $statement->execute([
                'venue_id' => $venueId,
                'session_date' => $form['date'],
                'end_time' => $form['end_time'],
                'start_time' => $form['start_time'],
                'session_id' => $sessionId
            ]);

            if ((int) $statement->fetchColumn() > 0) {
                throw new DomainException(
                    'Another training session is scheduled at this facility during the selected time.'
                );
            }

            /* Check overlapping facility booking requests. */
            $statement = $pdo->prepare(
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

            $statement->execute([
                'venue_id' => $venueId,
                'booking_date' => $form['date'],
                'end_time' => $form['end_time'],
                'start_time' => $form['start_time']
            ]);

            if ((int) $statement->fetchColumn() > 0) {
                throw new DomainException(
                    'An existing facility booking conflicts with the revised schedule.'
                );
            }

            /* Update only the permitted session fields. */
            $statement = $pdo->prepare(
                "UPDATE training_sessions
                SET
                    title = :title,
                    date = :session_date,
                    start_time = :start_time,
                    end_time = :end_time,
                    description = :description
                WHERE session_id = :session_id"
            );

            $statement->execute([
                'title' => $form['title'],
                'session_date' => $form['date'],
                'start_time' => $form['start_time'],
                'end_time' => $form['end_time'],
                'description' => $form['description'],
                'session_id' => $sessionId
            ]);

            $pdo->commit();

            $_SESSION['training_edit_feedback'] =
                'Training session updated successfully.';

            header(
                'Location: /training/edit.php?session_id=' . $sessionId,
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
                'Training session update error: ' .
                    $error->getMessage()
            );

            http_response_code(500);

            $errors[] =
                'Unable to update the training session. Please try again.';
        }
    }
}

/* Load the shared SportsHub layout. */
require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/training.css">

<main class="training-page">
    <div class="training-container">

        <section class="training-introduction">
            <p class="training-eyebrow">
                TRAINING MANAGEMENT
            </p>

            <h1>Edit Training Session</h1>

            <p class="training-intro-text">
                Update the schedule and details of an authorized
                training session before it begins.
            </p>
        </section>

        <?php if ($loadError): ?>

            <div class="training-notice" role="alert">
                <h3>Unable to load training information</h3>
                <p>Please try again later.</p>
            </div>

        <?php else: ?>

            <?php if (is_string($feedback)): ?>

                <div class="training-form-success" role="status">
                    <?= trainingEditEscape($feedback) ?>
                </div>

            <?php endif; ?>

            <?php if (!empty($errors)): ?>

                <div class="training-form-alert" role="alert">
                    <strong>Please review the following:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li>
                                <?= trainingEditEscape($error) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            <?php endif; ?>

            <?php if (!$canEdit): ?>

                <div class="training-notice">
                    <h3>Editing is unavailable</h3>

                    <p>
                        This session has already started or
                        has recorded attendance. Its schedule
                        cannot be changed.
                    </p>
                </div>

            <?php else: ?>

                <section aria-labelledby="training-edit-heading">

                    <div class="training-section-heading">
                        <div>
                            <p class="training-eyebrow">
                                SESSION DETAILS
                            </p>

                            <h2 id="training-edit-heading">
                                Update Training Schedule
                            </h2>
                        </div>
                    </div>

                    <form
                        class="training-form"
                        action="/training/edit.php"
                        method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= trainingEditEscape($csrfToken) ?>">

                        <input
                            type="hidden"
                            name="session_id"
                            value="<?= $sessionId ?>">

                        <div class="training-form-grid">

                            <div class="training-field">
                                <label>Sports Club</label>

                                <p class="training-attendance-context">
                                    <?= trainingEditEscape(
                                        $session['club_name']
                                    ) ?>
                                </p>
                            </div>

                            <div class="training-field">
                                <label>Training Facility</label>

                                <p class="training-attendance-context">
                                    <?= trainingEditEscape(
                                        $session['res_name'] ??
                                            'Facility information unavailable'
                                    ) ?>
                                </p>
                            </div>

                            <div class="training-field training-field-full">
                                <label for="edit-training-title">
                                    Session Title
                                </label>

                                <input
                                    id="edit-training-title"
                                    type="text"
                                    name="title"
                                    maxlength="50"
                                    required
                                    value="<?= trainingEditEscape(
                                                $form['title']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="edit-training-date">
                                    Training Date
                                </label>

                                <input
                                    id="edit-training-date"
                                    type="date"
                                    name="date"
                                    required
                                    value="<?= trainingEditEscape(
                                                $form['date']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="edit-training-start">
                                    Start Time
                                </label>

                                <input
                                    id="edit-training-start"
                                    type="time"
                                    name="start_time"
                                    required
                                    value="<?= trainingEditEscape(
                                                $form['start_time']
                                            ) ?>">
                            </div>

                            <div class="training-field">
                                <label for="edit-training-end">
                                    End Time
                                </label>

                                <input
                                    id="edit-training-end"
                                    type="time"
                                    name="end_time"
                                    required
                                    value="<?= trainingEditEscape(
                                                $form['end_time']
                                            ) ?>">
                            </div>

                            <div class="training-field training-field-full">
                                <label for="edit-training-description">
                                    Description
                                </label>

                                <textarea
                                    id="edit-training-description"
                                    name="description"
                                    rows="5"
                                    maxlength="255"
                                    required><?= trainingEditEscape(
                                                    $form['description']
                                                ) ?></textarea>
                            </div>

                        </div>

                        <div class="training-form-actions">

                            <button
                                class="training-primary-button"
                                type="submit">
                                Save Changes
                            </button>

                            <a
                                class="training-secondary-link"
                                href="/training/manage.php">
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

/* Load the shared SportsHub footer. */
require __DIR__ . '/../includes/footer.php';
