<?php
// NSBM SportsHub - Club Leadership Management
// Member 03 - Club Management

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Only administrators can access this page.
require_admin();

// Escape text before displaying it in HTML.
function leaderEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Create a CSRF token for leadership forms.
if (
    empty($_SESSION['club_leaders_csrf']) ||
    !is_string($_SESSION['club_leaders_csrf'])
) {
    $_SESSION['club_leaders_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['club_leaders_csrf'];

// ------------------------------------------
// Handle leadership assignments
// ------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (!in_array($action, ['assign', 'remove'], true)) {
        http_response_code(400);
        exit('Unknown leadership action.');
    }

    $clubId = filter_input(
        INPUT_POST,
        'club_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    // ------------------------------------------
    // Remove an existing club leader
    // ------------------------------------------

    if ($action === 'remove') {

        $leaderId = filter_input(
            INPUT_POST,
            'leader_id',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$leaderId) {
            http_response_code(400);
            exit('Invalid leadership record.');
        }

        try {
            $pdo = db();

            $stmt = $pdo->prepare(
                "DELETE FROM club_leaders
                WHERE leader_id = :leader_id"
            );

            $stmt->execute([
                'leader_id' => $leaderId
            ]);

            if ($stmt->rowCount() !== 1) {
                http_response_code(404);
                exit('Leadership assignment not found.');
            }

            header(
                'Location: /clubs/admin/leaders.php?result=removed',
                true,
                303
            );
            exit;

        } catch (Throwable $exception) {

            error_log(
                'Club leadership removal error: ' .
                $exception->getMessage()
            );

            http_response_code(500);
            exit('Unable to remove club leader.');
        }
    }

    $userId = filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $leaderRole = $_POST['leader_role'] ?? '';

    if (
        !$clubId ||
        !$userId ||
        !is_string($leaderRole) ||
        !in_array(
            $leaderRole,
            ['captain', 'vice_captain'],
            true
        )
    ) {
        http_response_code(400);
        exit('Invalid leadership assignment.');
    }

    $pdo = null;

    try {
        $pdo = db();

        // Lock the club row so leadership changes
        // for the same club are processed sequentially.
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT club_id
             FROM sports_clubs
             WHERE club_id = :club_id
               AND status = 'active'
             FOR UPDATE"
        );

        $stmt->execute([
            'club_id' => $clubId
        ]);

        if (!$stmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(400);
            exit('Select an active sports club.');
        }

        // Confirm the student has an approved
        // membership in this particular club.
        $stmt = $pdo->prepare(
            "SELECT u.user_id
             FROM users u
             INNER JOIN club_membership cm
                ON cm.user_id = u.user_id
             WHERE u.user_id = :user_id
               AND u.role = 'student'
               AND cm.club_id = :club_id
               AND cm.status = 'approved'
             LIMIT 1"
        );

        $stmt->execute([
            'user_id' => $userId,
            'club_id' => $clubId
        ]);

        if (!$stmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(400);
            exit(
                'Select an approved student member of this club.'
            );
        }

        // Prevent one student from holding
        // both leadership positions.
        $stmt = $pdo->prepare(
            "SELECT leader_role
             FROM club_leaders
             WHERE club_id = :club_id
               AND user_id = :user_id
               AND leader_role <> :leader_role
             LIMIT 1"
        );

        $stmt->execute([
            'club_id' => $clubId,
            'user_id' => $userId,
            'leader_role' => $leaderRole
        ]);

        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            exit(
                'This student already holds another leadership role in this club.'
            );
        }

        // Insert the new leader or replace the
        // existing holder of the selected role.
        $stmt = $pdo->prepare(
            "INSERT INTO club_leaders
                (club_id, user_id, leader_role)
             VALUES
                (:club_id, :user_id, :leader_role)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                appointed_at = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            'club_id' => $clubId,
            'user_id' => $userId,
            'leader_role' => $leaderRole
        ]);

        $pdo->commit();

        header(
            'Location: /clubs/admin/leaders.php?result=saved',
            true,
            303
        );
        exit;

    } catch (Throwable $exception) {

        if (
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Club leadership assignment error: ' .
            $exception->getMessage()
        );

        http_response_code(500);
        exit('Unable to save leadership assignment.');
    }
}

// ------------------------------------------
// Retrieve clubs and leadership information
// ------------------------------------------

$errorMessage = '';

$clubs = [];
$eligibleStudents = [];
$currentLeaders = [];

try {
    $pdo = db();

    // Retrieve all active sports clubs.
    $stmt = $pdo->query(
        "SELECT club_id, club_name, sport
         FROM sports_clubs
         WHERE status = 'active'
         ORDER BY club_name ASC"
    );

    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve students with approved club memberships.
    $stmt = $pdo->query(
        "SELECT
            cm.club_id,
            u.user_id,
            u.name,
            u.email
         FROM club_membership cm
         INNER JOIN users u
            ON u.user_id = cm.user_id
         INNER JOIN sports_clubs sc
            ON sc.club_id = cm.club_id
         WHERE cm.status = 'approved'
           AND u.role = 'student'
           AND sc.status = 'active'
         ORDER BY u.name ASC"
    );

    $eligibleStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve existing captain and vice-captain assignments.
    $stmt = $pdo->query(
        "SELECT
            cl.leader_id,
            cl.club_id,
            cl.user_id,
            cl.leader_role,
            cl.appointed_at,
            u.name AS student_name,
            sc.club_name
         FROM club_leaders cl
         INNER JOIN users u
            ON u.user_id = cl.user_id
         INNER JOIN sports_clubs sc
            ON sc.club_id = cl.club_id
         ORDER BY sc.club_name, cl.leader_role"
    );

    $currentLeaders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $errorMessage = 'Unable to load club leadership information.';
}

    require __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <section class="shell club-admin-page">

        <p class="clubs-eyebrow">
            NSBM SportsHub / Administration
        </p>

        <h1>Club Leadership Management</h1>

    <p>
        Assign captains and vice-captains
        to NSBM sports clubs.
    </p>

    <?php if ($errorMessage !== ''): ?>
        <p role="alert">
            <?= leaderEscape($errorMessage) ?>
        </p>
    <?php endif; ?>

    <section class="club-staff-admin-panel">
        <h2>Assign Club Leader</h2>

        <form
            method="POST"
            action="/clubs/admin/leaders.php"
            class="club-staff-admin-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= leaderEscape($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="assign"
            >

            <div class="club-staff-form-field">
                <label for="club_id">
                    Sports Club
                </label>

                <select
                    name="club_id"
                    id="club_id"
                    required
                >
                    <option value="">
                        Select a club
                    </option>

                    <?php foreach ($clubs as $club): ?>
                        <option value="<?= (int) $club['club_id'] ?>">
                            <?= leaderEscape($club['club_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="club-staff-form-field">
                <label for="user_id">
                    Eligible Student
                </label>

                <select
                    name="user_id"
                    id="user_id"
                    required
                >
                    <option value="">
                        Select a student
                    </option>

                    <?php foreach ($eligibleStudents as $student): ?>
                        <option
                            value="<?= (int) $student['user_id'] ?>"
                            data-club-id="<?= (int) $student['club_id'] ?>"
                        >
                            <?= leaderEscape($student['name']) ?>
                            -
                            <?= leaderEscape($student['email']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="club-staff-form-field">
                <label for="leader_role">
                    Leadership Role
                </label>

                <select
                    name="leader_role"
                    id="leader_role"
                    required
                >
                    <option value="">
                        Select a role
                    </option>
                    <option value="captain">
                        Captain
                    </option>
                    <option value="vice_captain">
                        Vice-Captain
                    </option>
                </select>
            </div>

            <button
                type="submit"
                class="club-admin-approve"
            >
                Assign Leader
            </button>

        </form>
    </section>

    <section class="club-leaders-table-section">
        <h2>Current Club Leaders</h2>

        <div class="club-leaders-table-wrapper">
            <table class="club-admin-table">
            <thead>
                <tr>
                    <th>Club</th>
                    <th>Student</th>
                    <th>Role</th>
                    <th>Appointed At</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($currentLeaders as $leader): ?>
                    <tr>
                        <td>
                            <?= leaderEscape($leader['club_name']) ?>
                        </td>
                        <td>
                            <?= leaderEscape($leader['student_name']) ?>
                        </td>
                        <td>
                            <?= leaderEscape(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $leader['leader_role']
                                    )
                                )
                            ) ?>
                        </td>
                        <td>
                            <?= leaderEscape($leader['appointed_at']) ?>
                        </td>
                        <td>
                            <form
                                method="POST"
                                onsubmit="return confirm('Remove this club leader?');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= leaderEscape($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove"
                                >

                                <input
                                    type="hidden"
                                    name="leader_id"
                                    value="<?= (int) $leader['leader_id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="club-admin-reject"
                                >
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($currentLeaders)): ?>
                    <tr>
                        <td colspan="5">
                            No club leaders assigned yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            </table>
        </div>
    </section>
    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const clubSelect = document.getElementById('club_id');
    const studentSelect = document.getElementById('user_id');

    if (!clubSelect || !studentSelect) {
        return;
    }

    // Save all eligible student options.
    const allStudents = Array.from(studentSelect.options)
        .filter(option => option.value !== '')
        .map(option => option.cloneNode(true));

    function updateEligibleStudents() {

        const selectedClubId = clubSelect.value;

        // Remove previous student options.
        studentSelect.replaceChildren();

        // Add the default option.
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select a student';

        studentSelect.appendChild(placeholder);

        // Add only students belonging to the selected club.
        allStudents.forEach(function (option) {

            if (
                selectedClubId !== '' &&
                option.dataset.clubId === selectedClubId
            ) {
                studentSelect.appendChild(option.cloneNode(true));
            }

        });

        // Reset the student selection.
        studentSelect.value = '';
    }

    clubSelect.addEventListener(
        'change',
        updateEligibleStudents
    );

    updateEligibleStudents();

});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>