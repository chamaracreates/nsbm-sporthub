<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

// Only administrators can view booking requests.
require_admin();

date_default_timezone_set('Asia/Colombo');

$bookings = [];
$loadError = false;

if (
    !isset($_SESSION['booking_review_csrf']) ||
    !is_string($_SESSION['booking_review_csrf'])
) {
    $_SESSION['booking_review_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$reviewCsrfToken = $_SESSION['booking_review_csrf'];


$feedback = $_SESSION['booking_admin_feedback'] ?? null;
unset($_SESSION['booking_admin_feedback']);

$reviewError = $_SESSION['booking_admin_error'] ?? null;
unset($_SESSION['booking_admin_error']);

try {
    $pdo = db();

    $statement = $pdo->query(
        "SELECT
            br.b_id,
            br.date,
            br.starts_at,
            br.ends_at,
            br.purpose,
            br.booking_status,
            br.reviewed_at,
            r.res_name,
            r.res_type,
            r.location,
            u.user_id,
            u.name AS student_name,
            u.email AS student_email
         FROM booking_request AS br
         INNER JOIN resources AS r
             ON br.res_id = r.res_id
         INNER JOIN users AS u
             ON br.user_id = u.user_id
         ORDER BY
             CASE
                 WHEN br.booking_status = 'pending' THEN 0
                 ELSE 1
             END,
             br.date DESC,
             br.starts_at DESC,
             br.b_id DESC"
    );

    $bookings = $statement->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $error) {
    error_log(
        'Admin booking requests error: ' .
        $error->getMessage()
    );

    $loadError = true;
}


$pageStylesheets = ['/assets/css/bookings.css'];

require __DIR__ . '/../../includes/header.php';

?>


<main class="booking-page booking-workflow-page booking-review-page sports-hub-workflow">

    <div class="booking-container">

        <section class="booking-introduction">

<h1>Booking Requests</h1>

            <p class="booking-intro-text">
                Review student facility booking requests,
                monitor booking statuses, and manage
                facility reservations.
            </p>

        </section>

        <section class="booking-section">
            <?php if (is_string($feedback)): ?>
    <p role="status" class="facility-admin-success">
        <?= sportsHubEscape($feedback) ?>
    </p>
<?php endif; ?>
<?php if (is_string($reviewError)): ?>
    <p role="alert" class="booking-review-error">
        <?= sportsHubEscape($reviewError) ?>
    </p>
<?php endif; ?>

            <div class="facility-admin-actions">
                <a
                    href="/bookings/admin/"
                    class="facility-admin-button facility-button-secondary"
                >
                    Facility Management
                </a>
            </div>

            <?php if ($loadError): ?>

                <p role="alert">
                    Unable to load booking requests.
                    Please try again later.
                </p>

            <?php elseif (empty($bookings)): ?>

                <p>
                    No facility booking requests have
                    been submitted yet.
                </p>

            <?php else: ?>

                <div class="facility-admin-table-wrapper">

                    <table class="facility-admin-table booking-requests-table">

                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Student</th>
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
                                        #<?= (int) $booking['b_id'] ?>
                                    </td>

                                    <td>
                                        <strong><?= sportsHubEscape($booking['student_name']) ?></strong>
                                        <small style="display:block"><?= sportsHubEscape($booking['student_email']) ?> (ID <?= (int) $booking['user_id'] ?>)</small>
                                    </td>

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
            action="/bookings/admin/review.php"
            style="display: flex; gap: 8px; flex-wrap: wrap;"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= sportsHubEscape($reviewCsrfToken) ?>"
            >

            <input
                type="hidden"
                name="b_id"
                value="<?= (int) $booking['b_id'] ?>"
            >

            <button
                type="submit"
                name="action"
                value="approve"
                class="facility-admin-button"
            >
                Approve
            </button>

            <button
                type="submit"
                name="action"
                value="reject"
                class="facility-admin-button"
                onclick="return confirm('Reject this booking request?');"
            >
                Reject
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

<?php
require __DIR__ . '/../../includes/footer.php';
?>
