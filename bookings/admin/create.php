<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

require_admin();

date_default_timezone_set('Asia/Colombo');


$facilityName = '';
$facilityType = '';
$facilityLocation = '';

$errors = [];
$success = false;


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string) $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {

        $facilityName = trim((string) ($_POST['res_name'] ?? ''));
        $facilityType = trim((string) ($_POST['res_type'] ?? ''));
        $facilityLocation = trim((string) ($_POST['location'] ?? ''));


        if ($facilityName === '') {
            $errors[] = 'Facility name is required.';
        } elseif (strlen($facilityName) > 150) {
            $errors[] = 'Facility name cannot exceed 150 characters.';
        }


        if ($facilityType === '') {
            $errors[] = 'Facility type is required.';
        } elseif (strlen($facilityType) > 100) {
            $errors[] = 'Facility type cannot exceed 100 characters.';
        }


        if (strlen($facilityLocation) > 150) {
            $errors[] = 'Location cannot exceed 150 characters.';
        }


        if (empty($errors)) {
            try {
                $pdo = db();

                $stmt = $pdo->prepare(
                    "INSERT INTO resources
                    (res_name, res_type, location, res_status)
                    VALUES
                    (:res_name, :res_type, :location, 'active')"
                );

                $stmt->execute([
                    ':res_name' => $facilityName,
                    ':res_type' => $facilityType,
                    ':location' => $facilityLocation !== ''
                        ? $facilityLocation
                        : null,
                ]);

                $_SESSION['facility_flash'] =
                    'Facility added successfully.';

                header('Location: /bookings/admin/');
                exit;

            } catch (PDOException $e) {
                error_log('Facility creation error: ' . $e->getMessage());
                $errors[] = 'Unable to add the facility. Please try again.';
            }
        }
    }
}?>

<?php
$pageStylesheets = ['/assets/css/bookings.css'];
require_once __DIR__ . '/../../includes/header.php';
?>


<main class="facility-admin-page booking-workflow-page booking-resource-form-page sports-hub-workflow">

    <h1>Add New Facility</h1>

    <p>Enter the details below to register a new sports facility.</p>

    <?php if (!empty($errors)): ?>
        <div class="facility-admin-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= sportsHubEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" class="facility-admin-form">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= sportsHubEscape($csrfToken) ?>"
        >

        <div class="facility-admin-field">
            <label for="res_name">Facility Name</label>

            <input
                type="text"
                id="res_name"
                name="res_name"
                maxlength="150"
                value="<?= sportsHubEscape($facilityName) ?>"
                placeholder="Example: Indoor Basketball Court"
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
                value="<?= sportsHubEscape($facilityType) ?>"
                placeholder="Example: Basketball Court"
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
                value="<?= sportsHubEscape($facilityLocation) ?>"
                placeholder="Example: Main Sports Complex"
            >
        </div>

        <div class="facility-admin-actions">

            <button type="submit" class="facility-admin-button">
                Add Facility
            </button>

            <a href="/bookings/admin/" class="facility-admin-button facility-button-secondary">
                Cancel
            </a>

        </div>

    </form>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>