<?php
// NSBM SportsHub - My Club Memberships
// Member 03 - Club Management

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Start authentication before displaying HTML.
$userId = require_login();

// Only students can access this page.
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

function myMembershipEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$memberships = [];
$loadError = false;

try {
    $stmt = db()->prepare(
        "SELECT
            cm.mem_id,
            cm.status,
            cm.joined_date,
            sc.club_id,
            sc.club_name,
            sc.sport,
            sc.status AS club_status
         FROM club_membership cm
         INNER JOIN sports_clubs sc
            ON cm.club_id = sc.club_id
         WHERE cm.user_id = :user_id
         ORDER BY cm.mem_id DESC"
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    $memberships = $stmt->fetchAll();

} catch (Throwable $exception) {
    error_log(
        'My memberships error: ' .
        $exception->getMessage()
    );

    $loadError = true;
}

require __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <section class="shell clubs-intro">

        <p class="clubs-eyebrow">
            NSBM SportsHub / My memberships
        </p>

        <h1>My Club Memberships</h1>

        <p>
            Track your sports club applications,
            membership approvals and club participation.
        </p>

    </section>

    <section class="shell my-memberships-section">

        <?php if ($loadError): ?>

            <div class="clubs-empty">
                <h2>Unable to load memberships</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif (empty($memberships)): ?>

            <div class="clubs-empty">

                <h2>No memberships yet</h2>

                <p>
                    You haven't applied to join any sports clubs.
                    Explore the available clubs to get started.
                </p>

                <a
                    href="/clubs/index.php"
                    class="clubs-view-link"
                >
                    Explore sports clubs
                </a>

            </div>

        <?php else: ?>

            <p class="clubs-result-count">
                <?= count($memberships) ?>
                <?= count($memberships) === 1
                    ? 'membership'
                    : 'memberships' ?>
            </p>

            <div class="my-memberships-grid">

                <?php foreach ($memberships as $membership): ?>

                    <article class="my-membership-card">

                        <span class="clubs-sport">
                            <?= myMembershipEscape($membership['sport']) ?>
                        </span>

                        <h2>
                            <?= myMembershipEscape($membership['club_name']) ?>
                        </h2>

                        <p>
                            Application status:
                            <strong class="my-membership-status">
                                <?= myMembershipEscape(
                                    ucfirst($membership['status'])
                                ) ?>
                            </strong>
                        </p>

                        <?php if ($membership['status'] === 'pending'): ?>

                            <p>
                                Your application is awaiting
                                administrator approval.
                            </p>

                        <?php elseif ($membership['status'] === 'approved'): ?>

                            <p>
                                You are an approved member
                                of this club.
                            </p>

                            <p>
                                Joined:
                                <?= myMembershipEscape(
                                    $membership['joined_date'] ?? 'Not recorded'
                                ) ?>
                            </p>

                        <?php else: ?>

                            <p>
                                Your application was not approved.
                            </p>

                        <?php endif; ?>

                        <?php if ($membership['club_status'] === 'active'): ?>

                            <a
                                href="/clubs/view.php?id=<?= (int) $membership['club_id'] ?>"
                                class="clubs-view-link"
                            >
                                View club
                            </a>

                        <?php else: ?>

                            <p>This club is currently archived.</p>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>