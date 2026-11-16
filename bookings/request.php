<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

date_default_timezone_set('Asia/Colombo');

function bookingRequestEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (
    !isset($_SESSION['booking_request_csrf']) ||
    !is_string($_SESSION['booking_request_csrf'])
) {
    $_SESSION['booking_request_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['booking_request_csrf'];

$errors = [];
$facilities = [];
$loadError = false;

$form = [
    'res_id' => '',
    'date' => '',
    'starts_at' => '',
    'ends_at' => '',
    'purpose' => ''
];

$today = date('Y-m-d');

try {
    $pdo = db();

    $statement = $pdo->query(
        "SELECT res_id, res_name, res_type, location
         FROM resources
         WHERE res_status = 'active'
         ORDER BY res_name ASC"
    );

    $facilities = $statement->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $error) {
    error_log(
        'Booking facility loading error: ' .
        $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

if ($requestMethod === 'POST' && !$loadError) {

    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission.');
    }

    foreach (array_keys($form) as $field) {
        $value = $_POST[$field] ?? '';

        $form[$field] = is_string($value)
            ? trim($value)
            : '';
    }

    $facilityId = filter_var(
        $form['res_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($facilityId)) {
        $errors[] = 'Please select a valid facility.';
    }

    $selectedDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $form['date']
    );

    if (
        !$selectedDate ||
        $selectedDate->format('Y-m-d') !== $form['date']
    ) {
        $errors[] = 'Please select a valid booking date.';
    } elseif ($form['date'] < $today) {
        $errors[] = 'Booking dates cannot be in the past.';
    }

    $startTime = DateTimeImmutable::createFromFormat(
        '!H:i',
        $form['starts_at']
    );

    $endTime = DateTimeImmutable::createFromFormat(
        '!H:i',
        $form['ends_at']
    );

    if (
        !$startTime ||
        $startTime->format('H:i') !== $form['starts_at'] ||
        !$endTime ||
        $endTime->format('H:i') !== $form['ends_at']
    ) {
        $errors[] = 'Please enter valid start and end times.';
    } elseif ($form['starts_at'] >= $form['ends_at']) {
        $errors[] = 'End time must be later than start time.';
    }

    if (strlen($form['purpose']) > 255) {
        $errors[] = 'Purpose cannot exceed 255 characters.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock the facility row while checking availability.
            $facilityCheck = $pdo->prepare(
                "SELECT res_id
                 FROM resources
                 WHERE res_id = :res_id
                   AND res_status = 'active'
                 FOR UPDATE"
            );

            $facilityCheck->execute([
                'res_id' => $facilityId
            ]);

            if (!$facilityCheck->fetch()) {
                $errors[] = 'The selected facility is unavailable.';
            }

            // Check overlapping approved bookings.
            if (empty($errors)) {
                $overlapCheck = $pdo->prepare(
                    "SELECT b_id
                     FROM booking_request
                     WHERE res_id = :res_id
                       AND date = :booking_date
                       AND booking_status = 'approved'
                       AND starts_at < :end_time
                       AND ends_at > :start_time
                     LIMIT 1"
                );

                $overlapCheck->execute([
                    'res_id' => $facilityId,
                    'booking_date' => $form['date'],
                    'end_time' => $form['ends_at'],
                    'start_time' => $form['starts_at']
                ]);

                if ($overlapCheck->fetch()) {
                    $errors[] =
                        'This facility is already booked for that time.';
                }
            }

            if (empty($errors)) {
                $insert = $pdo->prepare(
                    "INSERT INTO booking_request
                        (
                            res_id,
                            user_id,
                            date,
                            starts_at,
                            ends_at,
                            purpose,
                            booking_status
                        )
                     VALUES
                        (
                            :res_id,
                            :user_id,
                            :booking_date,
                            :starts_at,
                            :ends_at,
                            :purpose,
                            'pending'
                        )"
                );

                $insert->execute([
                    'res_id' => $facilityId,
                    'user_id' => $userId,
                    'booking_date' => $form['date'],
                    'starts_at' => $form['starts_at'],
                    'ends_at' => $form['ends_at'],
                    'purpose' => $form['purpose'] !== ''
                        ? $form['purpose']
                        : null
                ]);

                $pdo->commit();

                $_SESSION['booking_request_feedback'] =
                    'Your booking request was submitted successfully.';

                header(
                    'Location: /bookings/request.php',
                    true,
                    303
                );
                exit;
            }

            $pdo->rollBack();

        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Booking submission error: ' .
                $error->getMessage()
            );

            http_response_code(500);
            $errors[] =
                'Unable to submit your booking. Please try again.';
        }
    }
}

$feedback = $_SESSION['booking_request_feedback'] ?? null;
unset($_SESSION['booking_request_feedback']);

require_once __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/bookings.css">

<main class="facility-admin-page">

    <h1>Request a Facility Booking</h1>

    <p>
        Select an available facility and submit your preferred
        date and time for administrator approval.
    </p>

    <?php if (is_string($feedback)): ?>

        <div class="facility-admin-success" role="status">
            <?= bookingRequestEscape($feedback) ?>
        </div>

    <?php endif; ?>

    <?php if ($loadError): ?>

        <div class="facility-admin-error" role="alert">
            Unable to load available facilities.
        </div>

    <?php elseif (empty($facilities)): ?>

        <div class="booking-notice">
            <h3>No available facilities</h3>
            <p>Please check again later.</p>
        </div>

    <?php else: ?>

        <?php if (!empty($errors)): ?>

            <div class="facility-admin-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= bookingRequestEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form
            method="POST"
            action="/bookings/request.php"
            class="facility-admin-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= bookingRequestEscape($csrfToken) ?>"
            >

            <div class="facility-admin-field">

                <label for="res_id">Facility</label>

                <select id="res_id" name="res_id" required>
                    <option value="">Select a facility</option>

                    <?php foreach ($facilities as $facility): ?>
                        <option
                            value="<?= (int) $facility['res_id'] ?>"
                            <?= (string) $facility['res_id'] === $form['res_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= bookingRequestEscape(
                                $facility['res_name']
                            ) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

            <div class="facility-admin-field">

                <label for="date">Booking Date</label>

                <input
                    type="date"
                    id="date"
                    name="date"
                    min="<?= bookingRequestEscape($today) ?>"
                    value="<?= bookingRequestEscape($form['date']) ?>"
                    required
                >

            </div>

            <div class="facility-admin-field">

                <label for="starts_at">Start Time</label>

                <input
                    type="time"
                    id="starts_at"
                    name="starts_at"
                    value="<?= bookingRequestEscape($form['starts_at']) ?>"
                    required
                >

            </div>

            <div class="facility-admin-field">

                <label for="ends_at">End Time</label>

                <input
                    type="time"
                    id="ends_at"
                    name="ends_at"
                    value="<?= bookingRequestEscape($form['ends_at']) ?>"
                    required
                >

            </div>

            <div class="facility-admin-field">

                <label for="purpose">Booking Purpose</label>

                <textarea
                    id="purpose"
                    name="purpose"
                    maxlength="255"
                    rows="4"
                ><?= bookingRequestEscape($form['purpose']) ?></textarea>

            </div>

            <div class="facility-admin-actions">

                <button
                    type="submit"
                    class="facility-admin-button"
                >
                    Submit Booking Request
                </button>

                <a
                    href="/bookings/"
                    class="facility-admin-button"
                >
                    Cancel
                </a>

            </div>

        </form>

    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
