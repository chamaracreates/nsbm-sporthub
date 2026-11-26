<?php

// MIC and coach assignment administration.

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

// Only administrators can access this page.
// This starts the session before any HTML output.
require_admin();

// Escape text before displaying it in HTML.


if (
    empty($_SESSION['club_staff_csrf']) ||
    !is_string($_SESSION['club_staff_csrf'])
) {
    $_SESSION['club_staff_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['club_staff_csrf'];

// ------------------------------------------
// Handle administrator form submissions
// ------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    // Validate security token.
    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (!is_string($action)) {
        http_response_code(400);
        exit('Invalid action.');
    }

    try {

        $pdo = db();

        // ----------------------------------
        // Assign an MIC or coach
        // ----------------------------------

        if ($action === 'assign') {

            $userId = filter_input(
                INPUT_POST,
                'user_id',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $clubId = filter_input(
                INPUT_POST,
                'club_id',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $specialization = $_POST['specialization'] ?? '';

            if (
                !$userId ||
                !$clubId ||
                !is_string($specialization)
            ) {
                http_response_code(400);
                exit('Invalid assignment details.');
            }

            $specialization = trim($specialization);

            if (strlen($specialization) > 100) {
                http_response_code(400);
                exit('Specialization is too long.');
            }

            // Verify that the selected user
            // has an MIC or coach account.
            $stmt = $pdo->prepare(
                "SELECT role
                 FROM users
                 WHERE user_id = :user_id
                   AND role IN ('mic', 'coach')
                 LIMIT 1"
            );

            $stmt->execute([
                'user_id' => $userId
            ]);

            $staffRole = $stmt->fetchColumn();

            if ($staffRole === false) {
                http_response_code(400);
                exit('Select a valid MIC or coach account.');
            }

            // Verify that the club is active.
            $stmt = $pdo->prepare(
                "SELECT club_id
                 FROM sports_clubs
                 WHERE club_id = :club_id
                   AND status = 'active'
                 LIMIT 1"
            );

            $stmt->execute([
                'club_id' => $clubId
            ]);

            if (!$stmt->fetchColumn()) {
                http_response_code(400);
                exit('Select an active sports club.');
            }

            // Create or reactivate the assignment.
            // The database unique key prevents duplicates.
            $stmt = $pdo->prepare(
                "INSERT INTO sport_staff
                    (
                        user_id,
                        club_id,
                        staff_role,
                        specialization,
                        status
                    )
                 VALUES
                    (
                        :user_id,
                        :club_id,
                        :staff_role,
                        :specialization,
                        'active'
                    )
                 ON DUPLICATE KEY UPDATE
                    specialization = VALUES(specialization),
                    status = 'active'"
            );

            $stmt->execute([
                'user_id' => $userId,
                'club_id' => $clubId,
                'staff_role' => $staffRole,
                'specialization' => $specialization !== ''
                    ? $specialization
                    : null
            ]);

            $result = $stmt->rowCount() > 0
                ? 'saved'
                : 'unchanged';

        // ----------------------------------
        // Activate or deactivate staff
        // ----------------------------------

        } elseif (
            $action === 'activate' ||
            $action === 'deactivate'
        ) {

            $staffId = filter_input(
                INPUT_POST,
                'staff_id',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$staffId) {
                http_response_code(400);
                exit('Invalid staff assignment.');
            }

            // Verify the assignment.
            $stmt = $pdo->prepare(
                "SELECT
                    ss.staff_role,
                    u.role AS user_role,
                    sc.status AS club_status
                 FROM sport_staff ss
                 INNER JOIN users u
                    ON ss.user_id = u.user_id
                 INNER JOIN sports_clubs sc
                    ON ss.club_id = sc.club_id
                 WHERE ss.staff_id = :staff_id
                 LIMIT 1"
            );

            $stmt->execute([
                'staff_id' => $staffId
            ]);

            $assignment = $stmt->fetch();

            if (!$assignment) {
                http_response_code(404);
                exit('Staff assignment not found.');
            }

            // Prevent invalid assignments
            // from being activated.
            if ($action === 'activate') {

                if (
                    $assignment['user_role'] !==
                    $assignment['staff_role'] ||
                    $assignment['club_status'] !== 'active'
                ) {
                    http_response_code(409);
                    exit(
                        'This assignment cannot be activated.'
                    );
                }
            }

            $newStatus = $action === 'activate'
                ? 'active'
                : 'inactive';

            $stmt = $pdo->prepare(
                "UPDATE sport_staff
                 SET status = :status
                 WHERE staff_id = :staff_id
                   AND status <> :current_status"
            );

            $stmt->execute([
                'status' => $newStatus,
                'staff_id' => $staffId,
                'current_status' => $newStatus
            ]);

            $result = $stmt->rowCount() === 1
                ? 'saved'
                : 'unchanged';

        } else {

            http_response_code(400);
            exit('Unknown action.');

        }

        // Redirect after a successful POST.
        header(
            'Location: /clubs/admin/staff.php?result=' .
            $result,
            true,
            303
        );
        exit;

    } catch (Throwable $exception) {

        error_log(
            'Staff management error: ' .
            $exception->getMessage()
        );

        http_response_code(500);
        exit(
            'Unable to complete staff management action.'
        );

    }
}

// ------------------------------------------
// Load data for the administrator page
// ------------------------------------------

$clubs = [];
$staffUsers = [];
$assignments = [];
$loadError = false;

try {

    // Active clubs available for assignments.
    $clubs = db()->query(
        "SELECT
            club_id,
            club_name
         FROM sports_clubs
         WHERE status = 'active'
         ORDER BY club_name"
    )->fetchAll();

    // Accounts eligible for staff assignments.
    $staffUsers = db()->query(
        "SELECT
            user_id,
            name,
            role
         FROM users
         WHERE role IN ('mic', 'coach')
         ORDER BY role, name"
    )->fetchAll();

    // Existing staff assignments.
    $assignments = db()->query(
        "SELECT
            ss.staff_id, ss.user_id, ss.club_id,
            ss.staff_role,
            ss.specialization,
            ss.status,
            u.name AS staff_name,
            sc.club_name
         FROM sport_staff ss
         INNER JOIN users u
            ON ss.user_id = u.user_id
         INNER JOIN sports_clubs sc
            ON ss.club_id = sc.club_id
         ORDER BY
            sc.club_name,
            ss.staff_role,
            u.name"
    )->fetchAll();

} catch (Throwable $exception) {

    error_log(
        'Staff listing error: ' .
        $exception->getMessage()
    );

    $loadError = true;
    http_response_code(500);
}

// Load the shared website header.
$selectedUser = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$selectedClub = 0;
$selectedSpecialization = '';
$editAssignment = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editAssignment) {
    foreach ($assignments as $assignment) {
        if ((int)$assignment['staff_id'] === $editAssignment) {
            $selectedUser = (int)$assignment['user_id'];
            $selectedClub = (int)$assignment['club_id'];
            $selectedSpecialization = $assignment['specialization'] ?? '';
            break;
        }
    }
}

$pageStylesheets = ['/assets/css/clubs.css'];
require __DIR__ . '/../../includes/header.php';
?>


<main class="clubs-page sports-hub-workflow">

    <section class="shell club-admin-page staff-admin-v15">

        <p class="clubs-eyebrow">
            NSBM SportsHub / Administration
        </p>

        <div class="v15-page-heading">
            <div>
                <h1>MIC &amp; Coach Management</h1>
        <?php if (isset($_SESSION['admin_delete_feedback'])): ?>
            <p class="club-admin-success" role="status"><?= sportsHubEscape($_SESSION['admin_delete_feedback']) ?></p>
            <?php unset($_SESSION['admin_delete_feedback']); ?>
        <?php endif; ?>
                <p>Create confirmed staff accounts, assign MICs and coaches to clubs, and manage their appointments.</p>
            </div>
            <div class="v15-page-actions">
                <a class="v15-action v15-action-primary" href="/clubs/admin/create-staff.php">Create staff account</a>
                <a class="v15-action v15-action-secondary" href="/clubs/admin/leaders.php">Club leadership</a>
            </div>
        </div>

        <!-- Staff-account creation confirmation -->
        <?php if (($_GET['result'] ?? '') === 'account_created'): ?>
            <p class="club-admin-success" role="status">
                Staff account created. You can now assign this person to a sports club.
            </p>
        <?php endif; ?>

        <!-- Success messages -->

        <?php if (
            ($_GET['result'] ?? '') === 'saved'
        ): ?>

            <p class="club-admin-success" role="status">
                Staff assignment saved successfully.
            </p>

        <?php elseif (
            ($_GET['result'] ?? '') === 'unchanged'
        ): ?>

            <p role="status">
                No changes were needed.
            </p>

        <?php endif; ?>

        <?php if ($loadError): ?>

            <p role="alert">
                Unable to load staff management information.
            </p>

        <?php else: ?>

            <!-- Assign staff form -->

            <section class="club-staff-admin-panel">

                <h2>Assign MIC or Coach</h2>

                <?php if (empty($staffUsers)): ?>

                    <div class="v16-empty-state" role="status">
                        <h3>No staff accounts yet</h3>
                        <p>Create a coach or MIC account using the action above, then return here to assign them to a club.</p>
                    </div>

                <?php elseif (empty($clubs)): ?>

                    <div class="v16-empty-state" role="status">
                        <h3>No active clubs available</h3>
                        <p>Create an active sports club before assigning an MIC or coach.</p>
                    </div>

                <?php else: ?>

                    <form
                        method="post"
                        action="/clubs/admin/staff.php"
                        class="club-staff-admin-form"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="assign"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= sportsHubEscape($csrfToken) ?>"
                        >

                        <div class="club-staff-form-field">
                            <label for="staff-user">
                                MIC / Coach
                            </label>

                            <select
                                id="staff-user"
                                name="user_id"
                                required
                            >

                            <option value="">
                                Select staff member
                            </option>

                            <?php foreach (
                                $staffUsers as $person
                            ): ?>

                                <option
                                    value="<?= (int) $person['user_id'] ?>" <?= $selectedUser === (int)$person['user_id'] ? 'selected' : '' ?>
                                >
                                    <?= sportsHubEscape($person['name']) ?>
                                    (<?= sportsHubEscape(
                                        strtoupper($person['role'])
                                    ) ?>)
                                </option>

                            <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="club-staff-form-field">
                            <label for="staff-club">
                                Sports Club
                            </label>

                            <select
                                id="staff-club"
                                name="club_id"
                                required
                            >

                                <option value="">
                                    Select sports club
                                </option>

                                <?php foreach ($clubs as $club): ?>

                                    <option
                                        value="<?= (int) $club['club_id'] ?>" <?= $selectedClub === (int)$club['club_id'] ? 'selected' : '' ?>
                                    >
                                        <?= sportsHubEscape($club['club_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="club-staff-form-field">
                            <label for="staff-specialization">
                                Specialization
                            </label>

                            <input
                                id="staff-specialization"
                                name="specialization"
                                type="text"
                                maxlength="100"
                                placeholder="e.g. Track and Field" value="<?= sportsHubEscape($selectedSpecialization) ?>"
                            >
                        </div>

                        <div class="v16-form-actions">
<button
                            type="submit"
                            class="club-admin-approve"
                        >
                            Save Assignment
                        </button>
</div>

                    </form>

                <?php endif; ?>

            </section>

            <!-- Current assignments table -->

            <section class="club-staff-admin-list">

                <h2>Current Staff Assignments</h2>

                <?php if (empty($assignments)): ?>

                    <div class="v16-empty-state" role="status">
                        <h3>No staff assignments yet</h3>
                        <p>Once an MIC or coach is assigned to a club, their appointment and status will appear here.</p>
                    </div>

                <?php else: ?>

                    <div class="club-admin-table-wrap">

                        <table class="club-admin-table">

                            <thead>
                                <tr>
                                    <th>Staff Member</th>
                                    <th>Club</th>
                                    <th>Role</th>
                                    <th>Specialization</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach (
                                $assignments as $assignment
                            ): ?>

                                <tr>

                                    <td>
                                        <?= sportsHubEscape(
                                            $assignment['staff_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $assignment['club_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            strtoupper(
                                                $assignment['staff_role']
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            $assignment['specialization']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= sportsHubEscape(
                                            ucfirst(
                                                $assignment['status']
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <form
                                            method="post"
                                            action="/clubs/admin/staff.php"
                                            class="club-admin-actions"
                                        >

                                            <input
                                                type="hidden"
                                                name="staff_id"
                                                value="<?= (int) $assignment['staff_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= sportsHubEscape($csrfToken) ?>"
                                            >

                                            <?php if (
                                                $assignment['status'] ===
                                                'active'
                                            ): ?>

                                                <button
                                                    type="submit"
                                                    name="action"
                                                    value="deactivate"
                                                    class="club-admin-reject"
                                                >
                                                    Deactivate
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="submit"
                                                    name="action"
                                                    value="activate"
                                                    class="club-admin-approve"
                                                >
                                                    Activate
                                                </button>

                                            <?php endif; ?>

                                        </form>
                                        <a class="button button-outline" href="/clubs/admin/staff.php?edit=<?= (int)$assignment['staff_id'] ?>">Edit assignment</a>
                                        <a class="button button-danger-outline" href="/admin/delete.php?type=staff-assignment&amp;id=<?= (int) $assignment['staff_id'] ?>">Delete assignment</a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../../includes/footer.php'; ?>