<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

$visitorRole = current_user_role();


date_default_timezone_set('Asia/Colombo');


$facilities = [];
$loadError = false;

// Show existing facilities independently of booking availability.
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
        ORDER BY CASE WHEN res_status = 'active' THEN 0 ELSE 1 END,
                 res_name ASC
    ";

    $statement = $pdo->query($sql);

    $facilities = $statement->fetchAll();

} catch (PDOException $exception) {
    error_log('Facility listing error: ' . $exception->getMessage());
    $loadError = true;
}


$pageStylesheets = ['/assets/css/bookings.css'];
$pageTitle = 'Facilities';
require __DIR__ . '/../includes/header.php';
?>

<main class="booking-page booking-directory-page">

    <div class="booking-container">

        <!-- Page introduction -->
        <section class="booking-introduction">

            <h1>Sports Facilities</h1>

            <p class="booking-intro-text">
                Explore NSBM campus sports facilities and submit booking requests.
                Requests are reviewed by the university sports administration.
            </p>

            <div class="booking-directory-actions">
                <?php if ($visitorRole === 'student'): ?>
                    <a class="booking-request-link" href="/bookings/request.php">Request a Booking</a>
                    <a class="booking-directory-secondary" href="/bookings/history.php">My Bookings</a>
                <?php elseif ($visitorRole === 'admin'): ?>
                    <a class="booking-request-link" href="/bookings/admin/index.php">Manage Facilities</a>
                    <a class="booking-directory-secondary" href="/bookings/admin/requests.php">Review Bookings</a>
                <?php elseif ($visitorRole === null): ?>
                    <a class="booking-request-link" href="/login.php">Log in to Book</a>
                <?php endif; ?>
            </div>

        </section>

        <!-- Facility listing -->
        <section class="booking-section">

            <div class="booking-section-heading">

                <h2>Campus Sports Facilities</h2>


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
                    <h3>No facilities listed</h3>

                    <p>Facility information has not been added yet.</p>
                </div>

            <?php else: ?>

                <div class="booking-grid">

                    <?php foreach ($facilities as $facility): ?>
                        <?php
                        $isBookable = strtolower((string) $facility['res_status']) === 'active';
                        // Facility artwork serves as a fallback when no photograph is available.
                        // These artworks are not presented as photographs of the actual campus buildings.
                        $facilityKey = strtolower(trim((string) ($facility['res_name'] ?? '')));
                        $facilityCovers = [
                            'recreational centre' => ['recreational-centre', 'Multi-purpose sports and recreational spaces for the NSBM community.'],
                            'playgrounds' => ['playgrounds', 'Outdoor grounds supporting field sports, running and university activities.'],
                            'indoor stadium' => ['indoor-stadium', 'Covered sports space for indoor team games and recreational activities.'],
                            'swimming pool' => ['swimming-pool', 'Six-lane pool supporting swimming and aquatic sports.'],
                            'indoor gymnasium' => ['indoor-gymnasium', 'Indoor fitness and conditioning space.'],
                            'outdoor gymnasium' => ['outdoor-gymnasium', 'Open-air space for physical fitness and recreational activity.'],
                            'sports pavilion' => ['sports-pavilion', 'Spectator and event support facility beside the university sports grounds.'],
                        ];
                        [$facilityCover, $facilityInformation] = $facilityCovers[$facilityKey]
                            ?? ['recreational-centre', 'NSBM campus sports facility. Booking requests are subject to availability.'];
                        $facilityPicture = '/assets/images/facilities/' . $facilityCover . '.svg';
                        // Local official venue photos can replace illustrations if supplied.
                        if (is_file(__DIR__ . '/../assets/images/facilities/' . $facilityCover . '.jpg')) {
                            $facilityPicture = '/assets/images/facilities/' . $facilityCover . '.jpg';
                        }
                        // Use verified facility photographs when available.
                        // Local verified images supplied as assets/images/facilities/<slug>.jpg take priority.
                        $facilityPhotos = [
                            'recreational centre' => 'https://bravoslive.wordpress.com/wp-content/uploads/2017/08/indoor-stadium.jpg?w=1040',
                            'playgrounds' => 'https://www.nsbm.ac.lk/wp-content/uploads/2023/09/nsbm-global-youth-camp47.jpg',
                            'indoor stadium' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/11/Badminton-4.jpg',
                            'swimming pool' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2023/08/swimming-club2.jpeg',
                            'indoor gymnasium' => 'https://bravoslive.wordpress.com/wp-content/uploads/2017/08/gym.jpg?w=1040',
                            'outdoor gymnasium' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2024/03/Calisthenics-Club5-1079x600.jpg',
                            'sports pavilion' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2023/12/Opening-Ceremony-of-NSBM-Phase-2-Playground-and-Pavilion-768x511.jpg',
                        ];
                        if (!is_file(__DIR__ . '/../assets/images/facilities/' . $facilityCover . '.jpg')) {
                            $facilityPicture = $facilityPhotos[$facilityKey] ?? $facilityPicture;
                        }
                        ?>

                        <article class="booking-card<?= $isBookable ? '' : ' booking-card-unavailable' ?>">
                            <div class="facility-card-cover">
                                <img src="<?= sportsHubEscape($facilityPicture) ?>"
                                     alt="Photograph representing <?= sportsHubEscape($facility['res_name']) ?>"
                                     loading="lazy" decoding="async">
                                <span class="facility-cover-status" title="<?= $isBookable ? 'Booking requests enabled' : 'Booking requests disabled' ?>" aria-label="<?= $isBookable ? 'Booking requests open' : 'Booking requests closed' ?>">
                                    <?= $isBookable ? 'Open' : 'Closed' ?>
                                </span>
                            </div>
                            <div class="facility-card-body">
                                <h3 class="booking-card-title"><?= sportsHubEscape($facility['res_name']) ?></h3>
                                <p class="booking-description"><?= sportsHubEscape($facilityInformation) ?></p>
                                <!-- Facility booking actions. -->
                                <div class="facility-card-footer">
                                    <div class="facility-card-actions">
                                        <?php if ($visitorRole === 'admin'): ?>
                                            <a class="booking-request-link facility-edit-link"
                                               href="/bookings/admin/edit.php?res_id=<?= (int) $facility['res_id'] ?>">Edit facility</a>
                                        <?php elseif (!$isBookable): ?>
                                            <span class="booking-request-unavailable">Currently unavailable</span>
                                        <?php elseif ($visitorRole === 'student'): ?>
                                            <a class="booking-request-link"
                                               href="/bookings/request.php?res_id=<?= (int) $facility['res_id'] ?>">Book Now</a>
                                        <?php elseif ($visitorRole === null): ?>
                                            <a class="booking-request-link" href="/login.php">Book Now</a>
                                        <?php else: ?>
                                            <span class="booking-request-unavailable">Student bookings only</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>