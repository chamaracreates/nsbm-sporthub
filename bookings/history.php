<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

// Only logged-in students may view their bookings.
$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

date_default_timezone_set('Asia/Colombo');


$bookings = [];
$loadError = false;


if (
    !isset($_SESSION['booking_cancel_csrf']) ||
    !is_string($_SESSION['booking_cancel_csrf'])
) {
    $_SESSION['booking_cancel_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$cancelCsrfToken = $_SESSION['booking_cancel_csrf'];


$feedback = $_SESSION['booking_history_feedback'] ?? null;
unset($_SESSION['booking_history_feedback']);

try {
    $pdo = db();

    // Retrieve only the logged-in student's bookings.
    $statement = $pdo->prepare(
        "SELECT
            br.b_id,
            br.date,
            br.starts_at,
            br.ends_at,
            br.purpose,
            br.booking_status,
            r.res_name,
            r.res_type,
            r.location
         FROM booking_request AS br
         INNER JOIN resources AS r
             ON br.res_id = r.res_id
         WHERE br.user_id = :user_id
         ORDER BY br.date DESC, br.starts_at DESC, br.b_id DESC"
    );

    $statement->execute([
        'user_id' => $userId
    ]);

    $bookings = $statement->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $error) {
    error_log(
        'Booking history loading error: ' .
        $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

$pageStylesheets = ['/assets/css/bookings.css'];

require_once __DIR__ . '/../includes/header.php';

?>


<main class="booking-page booking-workflow-page booking-history-page">

    <div class="booking-container">

        <section class="booking-introduction">

<h1>My Booking History</h1>

            <p class="booking-intro-text">
                View your facility booking requests
                and track their approval status.
            </p>

        </section>

        <section class="booking-section">
            <?php if (is_string($feedback)): ?>

    <div class="facility-admin-success" role="status">
        <?= sportsHubEscape($feedback) ?>
    </div>

<?php endif; ?>

            <div class="booking-section-heading">

                <h2>My Requests</h2>

            </div>

            <div class="facility-admin-actions">

                <a
                    href="/bookings/request.php"
                    class="facility-admin-button"
                >
                    Request New Booking
                </a>

                <a
                    href="/bookings/"
                    class="facility-admin-button facility-button-secondary"
                >
                    View Facilities
                </a>

            </div>

            <?php if ($loadError): ?>

                <div class="booking-notice" role="alert">

                    <h3>Unable to load booking history</h3>

                    <p>Please try again later.</p>

                </div>

            <?php elseif (empty($bookings)): ?>

                <div class="booking-notice">

                    <h3>No bookings yet</h3>

                    <p>
                        You have not submitted any facility
                        booking requests.
                    </p>

                </div>

            <?php else: ?>

                <div class="facility-admin-table-wrapper">

                    <table class="facility-admin-table">

                        <thead>
                            <tr>
                                                                <th>Facility</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($bookings as $booking): ?>

                                <tr>

                                    <td>
                                        <?= sportsHubEscape(
                                            $booking['res_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $booking['date']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            substr(
                                                (string) $booking['starts_at'],
                                                0,
                                                5
                                            )
                                        ) ?>
                                        –
                                        <?= sportsHubEscape(
                                            substr(
                                                (string) $booking['ends_at'],
                                                0,
                                                5
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $booking['purpose']
                                                ?: 'Not specified'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            ucfirst(
                                                (string) $booking['booking_status']
                                            )
                                        ) ?>
                                    </td>

<td>
    <?php if ($booking['booking_status'] === 'pending'): ?>

        <form
            method="POST"
            action="/bookings/cancel.php"
            onsubmit="return confirm('Cancel this booking request?');"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= sportsHubEscape($cancelCsrfToken) ?>"
            >

            <input
                type="hidden"
                name="b_id"
                value="<?= (int) $booking['b_id'] ?>"
            >

            <button
                type="submit"
                class="facility-admin-button"
            >
                Cancel Booking
            </button>
        </form>

    <?php else: ?>

        <span>—</span>

    <?php endif; ?>
</td>


                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
