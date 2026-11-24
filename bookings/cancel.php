<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only authenticated students can cancel bookings.
$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

// Cancellation must use POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate CSRF protection.
$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['booking_cancel_csrf'] ?? null;

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid form submission.');
}


$bookingId = filter_var(
    $_POST['b_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($bookingId)) {
    http_response_code(400);
    exit('Invalid booking ID.');
}

try {
    $pdo = db();

    // Cancel only a pending booking owned by this student.
    $statement = $pdo->prepare(
        "UPDATE booking_request
         SET booking_status = 'cancelled'
         WHERE b_id = :b_id
           AND user_id = :user_id
           AND booking_status = 'pending'"
    );

    $statement->execute([
        'b_id' => $bookingId,
        'user_id' => $userId
    ]);

    if ($statement->rowCount() !== 1) {
        http_response_code(409);
        exit('Booking cannot be cancelled.');
    }

    $_SESSION['booking_history_feedback'] =
        'Booking cancelled successfully.';

    header(
        'Location: /bookings/history.php',
        true,
        303
    );

    exit;

} catch (Throwable $error) {
    error_log(
        'Booking cancellation error: ' .
        $error->getMessage()
    );

    http_response_code(500);
    exit('Unable to cancel booking.');
}
