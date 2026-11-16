<?php

// NSBM SportsHub - Admin Membership Management
// Member 03 - Club Management

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Only administrators can access this page.
// This also starts the session before HTML output.
require_admin();

function membershipEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Generate a CSRF token for administrator actions.
if (
    !isset($_SESSION['club_admin_csrf']) ||
    !is_string($_SESSION['club_admin_csrf'])
) {
    $_SESSION['club_admin_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['club_admin_csrf'];

// Process approval and rejection requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $decision = $_POST['decision'] ?? '';

    $membershipId = filter_input(
        INPUT_POST,
        'mem_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (
        !$membershipId ||
        !in_array($decision, ['approve', 'reject'], true)
    ) {
        http_response_code(400);
        exit('Invalid membership action.');
    }

    try {
        if ($decision === 'approve') {

            $sql = "
                UPDATE club_membership
                SET status = 'approved',
                    joined_date = CURDATE()
                WHERE mem_id = :mem_id
                  AND status = 'pending'
            ";

        } else {

            $sql = "
                UPDATE club_membership
                SET status = 'rejected',
                    joined_date = NULL
                WHERE mem_id = :mem_id
                  AND status = 'pending'
            ";
        }

        $stmt = db()->prepare($sql);

        $stmt->execute([
            'mem_id' => $membershipId
        ]);

        $result = $stmt->rowCount() === 1
            ? 'updated'
            : 'unchanged';

        header(
            'Location: /clubs/admin/members.php?result=' .
            $result,
            true,
            303
        );
        exit;

    } catch (Throwable $exception) {

        error_log(
            'Membership approval error: ' .
            $exception->getMessage()
        );

        http_response_code(500);
        exit('Unable to update membership.');
    }
}

// Retrieve membership applications.
$applications = [];
$loadError = false;

try {

    $stmt = db()->query(
        "SELECT
            cm.mem_id,
            cm.status,
            cm.joined_date,
            u.name AS student_name,
            u.email AS student_email,
            sc.club_name
         FROM club_membership cm
         INNER JOIN users u
            ON cm.user_id = u.user_id
         INNER JOIN sports_clubs sc
            ON cm.club_id = sc.club_id
         ORDER BY
            CASE
                WHEN cm.status = 'pending' THEN 0
                ELSE 1
            END,
            cm.mem_id DESC
         LIMIT 200"
    );

    $applications = $stmt->fetchAll();

} catch (Throwable $exception) {

    error_log(
        'Membership listing error: ' .
        $exception->getMessage()
    );

    $loadError = true;
    http_response_code(500);
}

require __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <section class="shell club-admin-page">

        <p class="clubs-eyebrow">
            NSBM SportsHub / Administration
        </p>

        <h1>Club Membership Management</h1>

        <p>
            Review student membership applications
            and approve or reject pending requests.
        </p>

        <?php if (($_GET['result'] ?? '') === 'updated'): ?>
            <p class="club-admin-success" role="status">
                Membership status updated successfully.
            </p>
        <?php elseif (($_GET['result'] ?? '') === 'unchanged'): ?>
            <p role="status">
                Application was already processed or not found.
            </p>
        <?php endif; ?>

        <?php if ($loadError): ?>

            <p role="alert">
                Unable to load membership applications.
            </p>

        <?php elseif (empty($applications)): ?>

            <p>No membership applications found.</p>

        <?php else: ?>

            <div class="club-admin-table-wrap">

                <table class="club-admin-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Club</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($applications as $application): ?>

                        <tr>

                            <td>
                                <?= (int) $application['mem_id'] ?>
                            </td>

                            <td>
                                <?= membershipEscape($application['student_name']) ?>
                            </td>

                            <td>
                                <?= membershipEscape($application['student_email']) ?>
                            </td>

                            <td>
                                <?= membershipEscape($application['club_name']) ?>
                            </td>

                            <td>
                                <?= membershipEscape(ucfirst($application['status'])) ?>
                            </td>

                            <td>
                                <?= membershipEscape($application['joined_date'] ?? '—') ?>
                            </td>

                            <td>

                                <?php if ($application['status'] === 'pending'): ?>

                                    <form
                                        method="post"
                                        action="/clubs/admin/members.php"
                                        class="club-admin-actions"
                                    >

                                        <input
                                            type="hidden"
                                            name="mem_id"
                                            value="<?= (int) $application['mem_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= membershipEscape($csrfToken) ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="decision"
                                            value="approve"
                                            class="club-admin-approve"
                                        >
                                            Approve
                                        </button>

                                        <button
                                            type="submit"
                                            name="decision"
                                            value="reject"
                                            class="club-admin-reject"
                                        >
                                            Reject
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span>Processed</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
