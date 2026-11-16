<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// Use Sri Lanka local time.
date_default_timezone_set('Asia/Colombo');

// Prepare facility data.
$facilities = [];
$loadError = false;

// Retrieve active facilities from the database.
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
        WHERE res_status = 'active'
        ORDER BY res_name ASC
    ";

    $statement = $pdo->query($sql);

    $facilities = $statement->fetchAll();

} catch (PDOException $exception) {
    error_log('Facility listing error: ' . $exception->getMessage());
    $loadError = true;
}

// Safely display database values.
function bookingEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Facilities | NSBM SportsHub</title>

    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/bookings.css">
</head>

<body>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<main class="booking-page">

    <div class="booking-container">

        <!-- Page introduction -->
        <section class="booking-introduction">

            <p class="booking-eyebrow">NSBM SPORTSHUB</p>

            <h1>Sports Facilities</h1>

            <p class="booking-intro-text">
                Explore the sports facilities available at NSBM.
                Find a suitable space for your sporting activities
                and submit a booking request.
            </p>

        </section>

        <!-- Facility listing -->
        <section class="booking-section">

            <div class="booking-section-heading">

                <h2>Available Facilities</h2>

                <span class="booking-count">
                    <?= count($facilities) ?>
                    <?= count($facilities) === 1 ? 'facility' : 'facilities' ?>
                </span>

            </div>

            <?php if ($loadError): ?>

                <div class="booking-notice">
                    <h3>Unable to load facilities</h3>

                    <p>
                        We're currently unable to retrieve the
                        facility information. Please try again later.
                    </p>
                </div>

            <?php elseif (empty($facilities)): ?>

                <div class="booking-notice">
                    <h3>No facilities available</h3>

                    <p>
                        There are currently no active facilities
                        available for booking.
                    </p>
                </div>

            <?php else: ?>

                <div class="booking-grid">

                    <?php foreach ($facilities as $facility): ?>

                        <article class="booking-card">

                            <div class="booking-card-header">

                                <span class="booking-type">
                                    <?= bookingEscape($facility['res_type']) ?>
                                </span>

                                <span class="booking-status">
                                    Available
                                </span>

                            </div>

                            <h3 class="booking-card-title">
                                <?= bookingEscape($facility['res_name']) ?>
                            </h3>

                            <div class="booking-card-details">

                                <div class="booking-detail">
                                    <span>Location</span>

                                    <strong>
                                        <?= bookingEscape(
                                            $facility['location'] ?? 'Not specified'
                                        ) ?>
                                    </strong>
                                </div>

                            </div>

                            <p class="booking-description">
                                This facility is available for
                                booking requests.
                            </p>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

</body>
</html>