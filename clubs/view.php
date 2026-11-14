<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

auth_start();

function clubViewEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Validate the club ID from the URL.
$clubId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$clubId) {
    http_response_code(404);
    exit('Club not found.');
}

$club = null;
$loadError = false;

try {
    $stmt = db()->prepare(
        "SELECT club_id, club_name, sport,
                description, club_email
         FROM sports_clubs
         WHERE club_id = :club_id
           AND status = 'active'
         LIMIT 1"
    );

    $stmt->execute(['club_id' => $clubId]);
    $club = $stmt->fetch();

} catch (Throwable $exception) {
    error_log('Club profile error: ' . $exception->getMessage());
    http_response_code(500);
    $loadError = true;
}

if (!$loadError && !$club) {
    http_response_code(404);
}

// Existing project images.
$clubImages = [
    'cricket' => '/assets/images/cricket.jpg',
    'badminton' => '/assets/images/badminton.jpg',
    'rugby' => '/assets/images/rugby.jpg',
    'athletics' => '/assets/images/athletics.jpg',
    'chess' => '/assets/images/chess.jpg',
    'martial arts' => '/assets/images/martialarts.jpg',
    'netball' => '/assets/images/netball.jpg',
    'swimming' => '/assets/images/swimming.jpg',
    'table tennis' => '/assets/images/tabletennis.jpg',
    'tennis' => '/assets/images/tennis.jpg',
    'volleyball' => '/assets/images/volleyball.jpg'
];

$image = $club
    ? ($clubImages[strtolower(trim($club['sport']))]
        ?? '/assets/images/club-marks/general.svg')
    : '';

/* Load assigned MICs and coaches */

$clubStaff = [];
$staffLoadError = false;

if ($club && !$loadError) {
    try {
        $staffStmt = db()->prepare(
            "SELECT
                ss.staff_id,
                ss.staff_role,
                ss.specialization,
                u.name AS staff_name
             FROM sport_staff ss
             INNER JOIN users u
                 ON ss.user_id = u.user_id
             WHERE ss.club_id = :club_id
               AND ss.status = 'active'
             ORDER BY
                CASE
                    WHEN ss.staff_role = 'mic' THEN 0
                    ELSE 1
                END,
                u.name"
        );

        $staffStmt->execute([
            'club_id' => $clubId
        ]);

        $clubStaff = $staffStmt->fetchAll();

    } catch (Throwable $exception) {
        error_log(
            'Club staff loading error: ' .
            $exception->getMessage()
        );

        $staffLoadError = true;
    }
}

require __DIR__ . '/../includes/header.php';

$viewerId = current_user_id();
$isStudent = $viewerId !== null && current_user_role() === 'student';

$membershipStatus = null;
$membershipCheckFailed = false;
$csrfToken = '';

if ($club && $isStudent) {
    try {
        $stmt = db()->prepare(
            "SELECT status
             FROM club_membership
             WHERE user_id = :user_id
               AND club_id = :club_id
             LIMIT 1"
        );

        $stmt->execute([
            'user_id' => $viewerId,
            'club_id' => $clubId
        ]);

        $status = $stmt->fetchColumn();

        if ($status !== false) {
            $membershipStatus = (string) $status;
        }

        // Generate a secure token for new applications.
        if ($membershipStatus === null) {
            if (empty($_SESSION['club_join_csrf'])) {
                $_SESSION['club_join_csrf'] =
                    bin2hex(random_bytes(32));
            }

            $csrfToken = $_SESSION['club_join_csrf'];
        }

    } catch (Throwable $exception) {
        error_log(
            'Membership status error: ' .
            $exception->getMessage()
        );

        $membershipCheckFailed = true;
    }
}

?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <div class="shell club-profile">

        <a href="/clubs/index.php" class="club-back">
            &larr; Back to sports clubs
        </a>

        <?php if ($loadError): ?>

            <section class="clubs-empty">
                <h1>Club information unavailable</h1>
                <p>Please try again later.</p>
            </section>

        <?php elseif (!$club): ?>

            <section class="clubs-empty">
                <h1>Club not found</h1>
                <p>
                    This club does not exist or is no longer active.
                </p>
            </section>

        <?php else: ?>

            <section class="club-profile-header">

                <div class="club-profile-heading">
                    <span class="clubs-sport">
                        <?= clubViewEscape($club['sport']) ?>
                    </span>

                    <h1>
                        <?= clubViewEscape($club['club_name']) ?>
                    </h1>

                    <p>
                        <?= clubViewEscape($club['description']) ?>
                    </p>
                </div>

                <div class="club-profile-image">
                    <img
                        src="<?= clubViewEscape($image) ?>"
                        alt="<?= clubViewEscape($club['club_name']) ?>"
                    >
                </div>

            </section>

            <section class="club-profile-info">

                <div class="club-info-card">
                    <h2>About the club</h2>

                    <p>
                        <?= clubViewEscape($club['description']) ?>
                    </p>
                </div>

                <div class="club-info-card">
                    <h2>Club information</h2>

                    <p>
                        <strong>Sport:</strong>
                        <?= clubViewEscape($club['sport']) ?>
                    </p>

                    <p>
                        <strong>Email:</strong>
                        <a href="mailto:<?= clubViewEscape($club['club_email']) ?>">
                            <?= clubViewEscape($club['club_email']) ?>
                        </a>
                    </p>

                    <p>
                        <strong>Status:</strong>
                        Active
                    </p>
                </div>

            </section>

<section class="club-membership">

    <h2>Join this club</h2>

    <p>
        Become part of the NSBM SportsHub community.
        Apply for membership and participate in club activities.
    </p>

    <?php if (!is_logged_in()): ?>

        <p>Please log in to submit a membership application.</p>

        <a href="/login.php" class="club-apply-button">
            Log in to apply
        </a>

    <?php elseif (!$isStudent): ?>

        <p>Membership applications are available to students only.</p>

    <?php elseif ($membershipCheckFailed): ?>

        <p role="alert">
            Unable to check your membership status.
            Please try again later.
        </p>

    <?php elseif ($membershipStatus !== null): ?>

        <p>
            Your membership status:
            <strong class="club-membership-status">
                <?= clubViewEscape(ucfirst($membershipStatus)) ?>
            </strong>
        </p>

        <?php if ($membershipStatus === 'pending'): ?>
            <p>Your application is awaiting administrator approval.</p>
        <?php elseif ($membershipStatus === 'approved'): ?>
            <p>You are an approved member of this club.</p>
        <?php elseif ($membershipStatus === 'rejected'): ?>
            <p>Your application was not approved.</p>
        <?php endif; ?>

    <?php else: ?>

        <form action="/clubs/join.php" method="post">

            <input
                type="hidden"
                name="club_id"
                value="<?= (int) $club['club_id'] ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= clubViewEscape($csrfToken) ?>"
            >

            <button type="submit" class="club-apply-button">
                Apply to join
            </button>

        </form>

    <?php endif; ?>

</section>

<section class="club-staff-section">

    <div class="club-staff-heading">
        <span class="clubs-sport">CLUB MANAGEMENT</span>
        <h2>MICs &amp; Coaches</h2>
        <p>
            Meet the staff supporting training,
            development and club activities.
        </p>
    </div>

    <?php if ($staffLoadError): ?>

        <p role="alert">
            Staff information is temporarily unavailable.
        </p>

    <?php elseif (empty($clubStaff)): ?>

        <div class="club-staff-empty">
            <p>
                No MICs or coaches have been assigned
                to this club yet.
            </p>
        </div>

    <?php else: ?>

        <div class="club-staff-grid">

            <?php foreach ($clubStaff as $staff): ?>

                <article class="club-staff-card">

                    <span class="club-staff-role">
                        <?= $staff['staff_role'] === 'mic'
                            ? 'Member in Charge (MIC)'
                            : 'Coach' ?>
                    </span>

                    <h3>
                        <?= clubViewEscape($staff['staff_name']) ?>
                    </h3>

                    <?php if (!empty($staff['specialization'])): ?>

                        <p>
                            Specialization:
                            <?= clubViewEscape(
                                $staff['specialization']
                            ) ?>
                        </p>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


        <?php endif; ?>

    </div>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>