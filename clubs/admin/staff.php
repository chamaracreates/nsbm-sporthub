<?php
// NSBM SportsHub - MIC and Coach Management
// Member 03 - Club Management

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Only administrators can access this page.
// This starts the session before any HTML output.
require_admin();

// Escape text before displaying it in HTML.
function staffEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Create a CSRF token for administrator forms.
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
            ss.staff_id,
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
require __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <section class="shell club-admin-page">

        <p class="clubs-eyebrow">
            NSBM SportsHub / Administration
        </p>

        <h1>MIC &amp; Coach Management</h1>

        <p>
            Assign Members in Charge and coaches to sports clubs,
            manage specializations, and control active assignments.
        </p>

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

                    <p>
                        No MIC or coach accounts exist yet.
                        An administrator must create staff accounts
                        before assigning them to clubs.
                    </p>

                <?php elseif (empty($clubs)): ?>

                    <p>
                        No active sports clubs are available.
                    </p>

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
                            value="<?= staffEscape($csrfToken) ?>"
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
                                    value="<?= (int) $person['user_id'] ?>"
                                >
                                    <?= staffEscape($person['name']) ?>
                                    (<?= staffEscape(
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
                                        value="<?= (int) $club['club_id'] ?>"
                                    >
                                        <?= staffEscape($club['club_name']) ?>
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
                                placeholder="e.g. Track and Field"
                            >
                        </div>

                        <button
                            type="submit"
                            class="club-admin-approve"
                        >
                            Save Assignment
                        </button>

                    </form>

                <?php endif; ?>

            </section>

            <!-- Current assignments table -->

            <section class="club-staff-admin-list">

                <h2>Current Staff Assignments</h2>

                <?php if (empty($assignments)): ?>

                    <p>
                        No staff assignments found.
                    </p>

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
                                        <?= staffEscape(
                                            $assignment['staff_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= staffEscape(
                                            $assignment['club_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= staffEscape(
                                            strtoupper(
                                                $assignment['staff_role']
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= staffEscape(
                                            $assignment['specialization']
                                            ?: '—'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= staffEscape(
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
                                                value="<?= staffEscape($csrfToken) ?>"
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