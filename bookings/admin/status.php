<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_admin();

// Only POST requests may change facility status.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate CSRF token.
$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['facility_status_csrf'] ?? null;

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid form submission.');
}

// Validate facility ID.
$facilityId = filter_var(
    $_POST['res_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($facilityId)) {
    http_response_code(400);
    exit('Invalid facility ID.');
}

// Validate requested status.
$newStatus = $_POST['res_status'] ?? null;

if (!in_array($newStatus, ['active', 'inactive'], true)) {
    http_response_code(400);
    exit('Invalid facility status.');
}

try {
    $pdo = db();

    $statement = $pdo->prepare(
        "UPDATE resources
         SET res_status = :res_status
         WHERE res_id = :res_id"
    );

    $statement->execute([
        'res_status' => $newStatus,
        'res_id' => $facilityId
    ]);

    if ($statement->rowCount() === 0) {
        // A matching facility may already have the requested status.
        $check = $pdo->prepare(
            "SELECT res_id FROM resources WHERE res_id = :res_id"
        );

        $check->execute(['res_id' => $facilityId]);

        if (!$check->fetch()) {
            http_response_code(404);
            exit('Facility not found.');
        }
    }

    $_SESSION['facility_admin_feedback'] =
        $newStatus === 'active'
            ? 'Facility activated successfully.'
            : 'Facility deactivated successfully.';

    header('Location: /bookings/admin/', true, 303);
    exit;

} catch (Throwable $error) {
    error_log('Facility status update error: ' . $error->getMessage());

    http_response_code(500);
    exit('Unable to update facility status.');
}
