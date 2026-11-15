<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$adminId = require_admin();

date_default_timezone_set('Asia/Colombo');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['booking_review_csrf'] ?? null;

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

$action = $_POST['action'] ?? null;

if (
    !is_int($bookingId) ||
    !is_string($action) ||
    !in_array($action, ['approve', 'reject'], true)
) {
    http_response_code(400);
    exit('Invalid booking review request.');
}

$pdo = null;

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Identify the facility associated with this booking.
    $lookup = $pdo->prepare(
        "SELECT res_id
         FROM booking_request
         WHERE b_id = :b_id"
    );

    $lookup->execute(['b_id' => $bookingId]);
    $bookingResource = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$bookingResource) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Booking not found.');
    }

    // Lock the facility first. All approval requests for the
    // same facility must acquire this lock before proceeding.
    $resourceStatement = $pdo->prepare(
        "SELECT res_id, res_status
         FROM resources
         WHERE res_id = :res_id
         FOR UPDATE"
    );

    $resourceStatement->execute([
        'res_id' => $bookingResource['res_id']
    ]);

    $resource = $resourceStatement->fetch(PDO::FETCH_ASSOC);

    if (!$resource) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Facility not found.');
    }

    // Lock and recheck the booking after obtaining the
    // facility lock, so concurrent reviews are serialized.
    $bookingStatement = $pdo->prepare(
        "SELECT
            b_id,
            res_id,
            date,
            starts_at,
            ends_at,
            booking_status
         FROM booking_request
         WHERE b_id = :b_id
         FOR UPDATE"
    );

    $bookingStatement->execute(['b_id' => $bookingId]);
    $booking = $bookingStatement->fetch(PDO::FETCH_ASSOC);

    if (
        !$booking ||
        (int) $booking['res_id'] !== (int) $resource['res_id'] ||
        $booking['booking_status'] !== 'pending'
    ) {
        $pdo->rollBack();
        http_response_code(409);
        exit('This booking is no longer pending.');
    }

    if ($action === 'approve') {
        if ($resource['res_status'] !== 'active') {
            $pdo->rollBack();
            http_response_code(409);
            exit('Cannot approve a booking for an inactive facility.');
        }

        // Detect overlapping approved bookings.
        $conflictStatement = $pdo->prepare(
            "SELECT b_id
             FROM booking_request
             WHERE res_id = :res_id
               AND date = :booking_date
               AND booking_status = 'approved'
               AND starts_at < :requested_end
               AND ends_at > :requested_start
               AND b_id <> :b_id
             LIMIT 1"
        );

        $conflictStatement->execute([
            'res_id' => $booking['res_id'],
            'booking_date' => $booking['date'],
            'requested_end' => $booking['ends_at'],
            'requested_start' => $booking['starts_at'],
            'b_id' => $bookingId
        ]);

        if ($conflictStatement->fetch()) {
    $pdo->rollBack();

    $_SESSION['booking_admin_error'] =
        'Cannot approve: another approved booking overlaps this time.';

    header(
        'Location: /bookings/admin/requests.php',
        true,
        303
    );
    exit;
}
    }

    $newStatus = $action === 'approve'
        ? 'approved'
        : 'rejected';

    $updateStatement = $pdo->prepare(
        "UPDATE booking_request
         SET booking_status = :booking_status,
             reviewed_by = :reviewed_by,
             reviewed_at = NOW()
         WHERE b_id = :b_id
           AND booking_status = 'pending'"
    );

    $updateStatement->execute([
        'booking_status' => $newStatus,
        'reviewed_by' => $adminId,
        'b_id' => $bookingId
    ]);

    if ($updateStatement->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(409);
        exit('Booking status changed. Please refresh the page.');
    }

    $pdo->commit();

    $_SESSION['booking_admin_feedback'] =
        $action === 'approve'
            ? 'Booking approved successfully.'
            : 'Booking rejected successfully.';

    header('Location: /bookings/admin/requests.php', true, 303);
    exit;

} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Admin booking review error: ' . $error->getMessage()
    );

    http_response_code(500);
    exit('Unable to review booking. Please try again.');
}
