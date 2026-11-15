<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_admin();

date_default_timezone_set('Asia/Colombo');

// Escape output safely.
function facilityEditEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Allow only GET and POST requests.
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Retrieve and validate facility ID.
$rawFacilityId = $requestMethod === 'POST'
    ? ($_POST['res_id'] ?? null)
    : ($_GET['res_id'] ?? null);

$facilityId = filter_var(
    $rawFacilityId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($facilityId)) {
    http_response_code(400);
    exit('Invalid facility ID.');
}

// Generate CSRF token.
if (
    !isset($_SESSION['facility_edit_csrf']) ||
    !is_string($_SESSION['facility_edit_csrf'])
) {
    $_SESSION['facility_edit_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['facility_edit_csrf'];

$errors = [];
$loadError = false;
$facility = null;

// Initialize form values.
$form = [
    'res_name' => '',
    'res_type' => '',
    'location' => ''
];

// Retrieve any feedback message.
$feedback = $_SESSION['facility_edit_feedback'] ?? null;
unset($_SESSION['facility_edit_feedback']);

try {
    $pdo = db();

    // Load existing facility.
    $statement = $pdo->prepare(
        "SELECT res_id, res_name, res_type, location, res_status
         FROM resources
         WHERE res_id = :res_id
         LIMIT 1"
    );

    $statement->execute([
        'res_id' => $facilityId
    ]);

    $facility = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$facility) {
        http_response_code(404);
        exit('Facility not found.');
    }

    // Populate the form.
    $form = [
        'res_name' => (string) $facility['res_name'],
        'res_type' => (string) $facility['res_type'],
        'location' => (string) ($facility['location'] ?? '')
    ];

} catch (Throwable $error) {
    error_log(
        'Facility edit loading error: ' .
        $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

// Process submitted changes.
if ($requestMethod === 'POST' && !$loadError) {

    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission.');
    }

    // Read submitted values.
    foreach (array_keys($form) as $field) {
        $value = $_POST[$field] ?? '';

        $form[$field] = is_string($value)
            ? trim($value)
            : '';
    }

    // Validate facility name.
    if ($form['res_name'] === '') {
        $errors[] = 'Facility name is required.';
    } elseif (strlen($form['res_name']) > 150) {
        $errors[] = 'Facility name cannot exceed 150 characters.';
    }

    // Validate facility type.
    if ($form['res_type'] === '') {
        $errors[] = 'Facility type is required.';
    } elseif (strlen($form['res_type']) > 100) {
        $errors[] = 'Facility type cannot exceed 100 characters.';
    }

    // Validate location.
    if (strlen($form['location']) > 150) {
        $errors[] = 'Location cannot exceed 150 characters.';
    }

    // Update facility.
    if (empty($errors)) {
        try {
            $statement = $pdo->prepare(
                "UPDATE resources
                 SET
                    res_name = :res_name,
                    res_type = :res_type,
                    location = :location
                 WHERE res_id = :res_id"
            );

            $statement->execute([
                'res_name' => $form['res_name'],
                'res_type' => $form['res_type'],
                'location' => $form['location'] !== ''
                    ? $form['location']
                    : null,
                'res_id' => $facilityId
            ]);

            // Store a success message for the admin page.
            $_SESSION['facility_admin_feedback'] =
                'Facility updated successfully.';

            // Redirect to Facility Administration.
            header(
                'Location: /bookings/admin/',
                true,
                303
            );

            exit;

        } catch (Throwable $error) {
            error_log(
                'Facility update error: ' .
                $error->getMessage()
            );

            http_response_code(500);

            $errors[] =
                'Unable to update the facility. Please try again.';
        }
    }
}

// Load shared page layout.
require_once __DIR__ . '/../../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/bookings.css">

<main class="facility-admin-page">

    <h1>Edit Facility</h1>

    <p>Update the details of this sports facility.</p>

    <?php if ($loadError): ?>

        <div class="facility-admin-error" role="alert">
            Unable to load facility information. Please try again later.
        </div>

    <?php else: ?>

        <?php if (is_string($feedback)): ?>

            <div class="facility-admin-success" role="status">
                <?= facilityEditEscape($feedback) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="facility-admin-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= facilityEditEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form
            method="POST"
            action="/bookings/admin/edit.php?res_id=<?= $facilityId ?>"
            class="facility-admin-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= facilityEditEscape($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="res_id"
                value="<?= $facilityId ?>"
            >

            <div class="facility-admin-field">

                <label for="res_name">Facility Name</label>

                <input
                    type="text"
                    id="res_name"
                    name="res_name"
                    maxlength="150"
                    value="<?= facilityEditEscape($form['res_name']) ?>"
                    required
                >

            </div>

            <div class="facility-admin-field">

                <label for="res_type">Facility Type</label>

                <input
                    type="text"
                    id="res_type"
                    name="res_type"
                    maxlength="100"
                    value="<?= facilityEditEscape($form['res_type']) ?>"
                    required
                >

            </div>

            <div class="facility-admin-field">

                <label for="location">Location</label>

                <input
                    type="text"
                    id="location"
                    name="location"
                    maxlength="150"
                    value="<?= facilityEditEscape($form['location']) ?>"
                >

            </div>

            <div class="facility-admin-field">

                <label>Current Status</label>

                <p>
                    <?= facilityEditEscape(
                        (string) $facility['res_status']
                    ) ?>
                </p>

                <small>
                    Facility status is managed separately.
                </small>

            </div>

            <div class="facility-admin-actions">

                <button
                    type="submit"
                    class="facility-admin-button"
                >
                    Save Changes
                </button>

                <a
                    href="/bookings/admin/"
                    class="facility-admin-button"
                >
                    Cancel
                </a>

            </div>

        </form>

    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
