<?php

declare(strict_types=1);


require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

// Only administrators can access facility management.
require_admin();


date_default_timezone_set('Asia/Colombo');


$facilities = [];
$loadError = false;


if (
    !isset($_SESSION['facility_status_csrf']) ||
    !is_string($_SESSION['facility_status_csrf'])
) {
    $_SESSION['facility_status_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$statusCsrfToken = $_SESSION['facility_status_csrf'];


$feedback = $_SESSION['facility_admin_feedback'] ?? null;
unset($_SESSION['facility_admin_feedback']);

// Retrieve all facilities, including inactive ones.
try {
    $pdo = db();

    $sql = "
        SELECT
            res_id,
            res_name,
            res_type,
            location,
            res_status
        FROM resources
        ORDER BY res_name ASC
    ";

    $statement = $pdo->query($sql);
    $facilities = $statement->fetchAll();

} catch (PDOException $exception) {
    error_log(
        'Facility administration error: ' .
        $exception->getMessage()
    );

    $loadError = true;
}


$pageStylesheets = ['/assets/css/bookings.css'];
require __DIR__ . '/../../includes/header.php';

?>


<main class="booking-page booking-workflow-page booking-resource-admin-page sports-hub-workflow">

    <div class="booking-container">

        <section class="booking-introduction">

<h1>Facility Management</h1>

            <p class="booking-intro-text">
                Manage sports facilities and equipment.
                Add new resources, update facility details,
                and control their availability.
            </p>

        </section>

        <section class="booking-section">

            <div class="booking-section-heading">

                <h2>All Facilities</h2>

                <span class="booking-count">
                    <?= count($facilities) ?>
                    <?= count($facilities) === 1
                        ? 'facility'
                        : 'facilities' ?>
                </span>

            </div>

            <?php if (is_string($feedback)): ?>

                <div
                    class="facility-admin-success"
                    role="status"
                >
                    <?= sportsHubEscape($feedback) ?>
                </div>

            <?php endif; ?>

            <div class="facility-admin-actions">

                <a
                    href="/bookings/admin/create.php"
                    class="facility-admin-button"
                >
                    + Add New Facility
                </a>

            </div>

            <?php if ($loadError): ?>

                <div class="booking-notice">

                    <h3>Unable to load facilities</h3>

                    <p>Please try again later.</p>

                </div>

            <?php elseif (empty($facilities)): ?>

                <div class="booking-notice">

                    <h3>No facilities found</h3>

                    <p>
                        No facilities have been added yet.
                        Use the Add New Facility option to
                        create your first facility.
                    </p>

                </div>

            <?php else: ?>

                <div class="facility-admin-table-wrapper">

                    <table class="facility-admin-table">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Facility Name</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($facilities as $facility): ?>

                                <tr>

                                    <td>
                                        <?= (int) $facility['res_id'] ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $facility['res_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $facility['res_type']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            preg_replace('/^NSBM Green University\s*,.*$/i', 'NSBM Green University', (string)($facility['location'] ?: 'Not specified'))
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $facility['res_status']
                                        ) ?>
                                    </td>

                                    <td>

                                        <div class="facility-admin-actions">

                                            <!-- Edit facility -->

                                            <a
                                                href="/bookings/admin/edit.php?res_id=<?= (int) $facility['res_id'] ?>"
                                                class="facility-admin-button facility-button-secondary"
                                            >
                                                Edit
                                            </a>

                                            <!-- Removal is separate from status changes and requires confirmation. -->
                                            <a
                                                href="/admin/delete.php?type=facility&amp;id=<?= (int) $facility['res_id'] ?>"
                                                class="facility-admin-button button-danger-outline">
                                                Delete
                                            </a>

                                            <!-- Activate or deactivate facility -->

                                            <form
                                                method="POST"
                                                action="/bookings/admin/status.php"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= sportsHubEscape($statusCsrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="res_id"
                                                    value="<?= (int) $facility['res_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="res_status"
                                                    value="<?= $facility['res_status'] === 'active' ? 'inactive' : 'active' ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="facility-admin-button <?= $facility['res_status'] === 'active' ? 'facility-danger-button' : '' ?>"
                                                >
                                                    <?= $facility['res_status'] === 'active'
                                                        ? 'Deactivate'
                                                        : 'Activate' ?>
                                                </button>

                                            </form>

                                        </div>

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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
