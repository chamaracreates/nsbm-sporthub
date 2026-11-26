<?php


// Club membership administration.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

$reviewerId = require_login();
$role = current_user_role();
if (!in_array($role, ['admin', 'mic', 'coach'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$scope = $role === 'admin' ? '' : " AND sc.status = 'active' AND EXISTS (SELECT 1 FROM sport_staff ss WHERE ss.club_id = sc.club_id AND ss.user_id = ? AND ss.staff_role = ? AND ss.status = 'active')";
$params = $role === 'admin' ? [] : [$reviewerId, $role];

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
        !in_array($decision, ['approve', 'reject', 'delete'], true) || $role === 'coach'
    ) {
        http_response_code(400);
        exit('Invalid membership action.');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT sc.club_id, sc.status FROM sports_clubs sc JOIN club_membership cm ON cm.club_id = sc.club_id WHERE cm.mem_id = ?' . $scope . ' FOR UPDATE');
        $stmt->execute(array_merge([$membershipId], $params));
        $club = $stmt->fetch();
        if (!$club || !sportsHubCanManageClub($pdo, (int)$club['club_id'], $reviewerId, $role)) throw new DomainException('Membership not found or access denied.');
        $clubId = (int)$club['club_id'];
        if ($decision === 'approve' && $club['status'] !== 'active') throw new DomainException('This club is not active.');
        $stmt = $pdo->prepare('SELECT * FROM club_membership WHERE mem_id = ? FOR UPDATE');
        $stmt->execute([$membershipId]);
        $membership = $stmt->fetch();
        if (!$membership) throw new DomainException('Membership no longer exists.');
        if ($decision === 'delete') {
            if (($_POST['confirmation'] ?? '') !== 'DELETE' || !sportsHubMembershipCanDelete($pdo, (int) $clubId, (int) $membership['user_id'])) {
                throw new DomainException('Remove linked leadership, team or attendance records where permitted before deleting this membership.');
            }
            $stmt = $pdo->prepare('DELETE FROM club_membership WHERE mem_id = ?');
            $stmt->execute([$membershipId]);
        } else {
            $stmt = $pdo->prepare("UPDATE club_membership SET status = ?, joined_date = CASE WHEN ? = 'approved' THEN CURDATE() ELSE NULL END WHERE mem_id = ? AND status = 'pending'");
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $stmt->execute([$status, $status, $membershipId]);
        }
        $result = $stmt->rowCount() === 1 ? 'updated' : 'unchanged';
        $pdo->commit();
        header('Location: /clubs/admin/members.php?result=' . $result, true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$exception instanceof DomainException) error_log('Membership review: ' . $exception->getMessage());
        $_SESSION['membership_review_error'] = $exception instanceof DomainException ? $exception->getMessage() : 'Unable to update membership.';
        header('Location: /clubs/admin/members.php', true, 303);
        exit;
    }
}

// Retrieve membership applications.
$applications = [];
$loadError = false;
$schemaUpgradeRequired = false;

try {

    $stmt = db()->prepare(
        "SELECT
            cm.mem_id,
            cm.status,
            cm.joined_date, cm.experience_level, cm.application_reason,
            sp.student_number, sp.study_program, sp.academic_year, sp.phone,
            u.name AS student_name,
            u.email AS student_email,
            sc.club_name
         FROM club_membership cm
         INNER JOIN users u
            ON cm.user_id = u.user_id
         INNER JOIN sports_clubs sc
            ON cm.club_id = sc.club_id
         LEFT JOIN student_profiles sp ON sp.user_id = cm.user_id
         WHERE 1=1 $scope
         " . ($role === 'coach' ? " AND cm.status = 'approved'" : '') . "
         ORDER BY
            CASE
                WHEN cm.status = 'pending' THEN 0
                ELSE 1
            END,
            cm.mem_id DESC
         LIMIT 200"
    );

    $stmt->execute($params);
    $applications = $stmt->fetchAll();

} catch (Throwable $exception) {

    error_log(
        'Membership listing error: ' .
        $exception->getMessage()
    );

    $loadError = true;
    $schemaUpgradeRequired = $exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1054;
    http_response_code(500);
}

$pageStylesheets = ['/assets/css/clubs.css'];

require __DIR__ . '/../../includes/header.php';
?>


<main class="clubs-page sports-hub-workflow">

    <section class="shell club-admin-page membership-admin-v15">

        <p class="clubs-eyebrow">
            NSBM SportsHub / Administration
        </p>

        <h1>Club Membership Management</h1>

        <p>
            <?= $role === 'coach' ? 'View approved student members of your assigned clubs.' : 'Review student applications, approve or reject requests, and manage memberships.' ?>
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

        <?php if (isset($_SESSION['membership_review_error'])): ?>
            <p role="alert"><?= sportsHubEscape($_SESSION['membership_review_error']) ?></p>
            <?php unset($_SESSION['membership_review_error']); ?>
        <?php endif; ?>
        <?php if ($loadError): ?>

            <div class="v15-empty-state" role="alert">
                <h2>Membership applications unavailable</h2>
                <p><?= $schemaUpgradeRequired ? 'Complete the database schema upgrade and try again.' : 'Unable to load membership applications. Please try again later.' ?></p>
            </div>

        <?php elseif (empty($applications)): ?>

            <div class="v15-empty-state" role="status">
    <h2><?= $role === 'coach' ? 'No approved members' : 'No membership requests' ?></h2>
    <p>New club applications will appear here for review and approval.</p>
</div>

        <?php else: ?>

            <div class="club-admin-table-wrap">

                <table class="club-admin-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Club</th>
                            <th>Application</th>
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
                                <?= sportsHubEscape($application['student_name']) ?>
                            </td>

                            <td>
                                <?= sportsHubEscape($application['student_email']) ?>
                            </td>

                            <td>
                                <?= sportsHubEscape($application['club_name']) ?>
                            </td>

                            <td>
                                <details><summary>View information</summary>
                                    <?php foreach (['student_number'=>'Student ID','study_program'=>'Degree','academic_year'=>'Year','phone'=>'Phone','experience_level'=>'Experience','application_reason'=>'Reason'] as $key=>$label): ?>
                                        <?php if ($role !== 'coach' || in_array($key, ['study_program','academic_year','experience_level'], true)): ?><p><strong><?= sportsHubEscape($label) ?>:</strong> <?= sportsHubEscape($application[$key] ?? 'Not provided') ?></p><?php endif; ?>
                                    <?php endforeach; ?>
                                </details>
                            </td>
                            <td>
                                <?= sportsHubEscape(ucfirst($application['status'])) ?>
                            </td>

                            <td>
                                <?= sportsHubEscape($application['joined_date'] ?? '—') ?>
                            </td>

                            <td>

                                <?php if ($role !== 'coach' && $application['status'] === 'pending'): ?>

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
                                            value="<?= sportsHubEscape($csrfToken) ?>"
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

                                    <span><?= $role === 'coach' ? 'Approved member' : 'Processed' ?></span>

                                <?php endif; ?>
                                <?php if ($role !== 'coach'): ?>
                                    <form method="post" class="club-admin-actions" onsubmit="return confirm('Permanently delete this membership request or record?');">
                                        <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                                        <input type="hidden" name="mem_id" value="<?= (int) $application['mem_id'] ?>">
                                        <input type="hidden" name="confirmation" value="DELETE">
                                        <button class="button button-danger-outline" name="decision" value="delete">Delete</button>
                                    </form>
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
