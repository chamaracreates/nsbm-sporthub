<?php
declare(strict_types=1);

// NSBM SportsHub - Admin Club Management
// Member 03 - Club Management

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Restrict access to administrators.
require_admin();

// Create a CSRF token for club administration forms.
if (
    empty($_SESSION['club_admin_csrf']) ||
    !is_string($_SESSION['club_admin_csrf'])
) {
    $_SESSION['club_admin_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['club_admin_csrf'];

function adminClubEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$clubs = [];
$loadError = false;

// Selected club for editing.
$editClub = null;
$editError = '';

// Messages displayed after club form submissions.
$successMessage = '';
$errorMessage = '';

// Preserve entered values if validation fails.
$formValues = [
    'club_name' => '',
    'sport' => '',
    'club_email' => '',
    'status' => 'active',
    'description' => ''
];

// Handle Create Club form submissions.
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

    if (!is_string($action) ||
        !in_array($action, ['create', 'update'], true)
    ) {
        http_response_code(400);
        exit('Invalid club action.');
    }
// Handle updates separately from club creation.
if ($action === 'update') {

    $clubId = filter_var(
        $_POST['club_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($clubId === false || $clubId === null) {
        http_response_code(400);
        exit('Invalid club ID.');
    }

    $updateValues = [];

    foreach (array_keys($formValues) as $field) {
        $value = $_POST[$field] ?? null;

        if (!is_string($value)) {
            $errorMessage = 'Invalid club details.';
            break;
        }

        $updateValues[$field] = trim($value);
    }

    if ($errorMessage === '') {
        $name = $updateValues['club_name'];
        $sport = $updateValues['sport'];
        $email = $updateValues['club_email'];
        $status = $updateValues['status'];
        $description = $updateValues['description'];

        if (
            $name === '' ||
            $sport === '' ||
            $email === '' ||
            $description === ''
        ) {
            $errorMessage = 'Please complete all required fields.';

        } elseif (
            mb_strlen($name, 'UTF-8') > 150 ||
            mb_strlen($sport, 'UTF-8') > 100 ||
            mb_strlen($description, 'UTF-8') > 255 ||
            strlen($email) > 255
        ) {
            $errorMessage = 'One or more fields are too long.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';

        } elseif (!in_array($status, ['active', 'archived'], true)) {
            $errorMessage = 'Invalid club status.';

        } else {
            try {
                $pdo = db();

                // Check that the club exists.
                $exists = $pdo->prepare(
                    "SELECT club_id
                     FROM sports_clubs
                     WHERE club_id = :club_id"
                );

                $exists->execute(['club_id' => $clubId]);

                if (!$exists->fetchColumn()) {
                    $errorMessage = 'Club not found.';
                } else {
                    // Exclude the current club from duplicate checks.
                    $duplicate = $pdo->prepare(
                        "SELECT club_id
                         FROM sports_clubs
                         WHERE sport = :sport
                           AND club_id <> :club_id
                         LIMIT 1"
                    );

                    $duplicate->execute([
                        'sport' => $sport,
                        'club_id' => $clubId
                    ]);

                    if ($duplicate->fetchColumn() !== false) {
                        $errorMessage =
                            'Another club already uses this sport.';
                    } else {
                        $update = $pdo->prepare(
                            "UPDATE sports_clubs
                             SET club_name = :club_name,
                                 sport = :sport,
                                 description = :description,
                                 club_email = :club_email,
                                 status = :status
                             WHERE club_id = :club_id"
                        );

                        $update->execute([
                            'club_name' => $name,
                            'sport' => $sport,
                            'description' => $description,
                            'club_email' => $email,
                            'status' => $status,
                            'club_id' => $clubId
                        ]);

                        $successMessage =
                            'Sports club updated successfully.';
                    }
                }

            } catch (PDOException $exception) {
                error_log(
                    'Club update error: ' .
                    $exception->getMessage()
                );

                $errorMessage =
                    $exception->getCode() === '23000'
                    ? 'Another club already uses this sport.'
                    : 'Unable to update the club.';
            }
        }
    }
}

if ($action === 'create') {

    // Read and validate submitted fields.
    foreach (array_keys($formValues) as $field) {
        $value = $_POST[$field] ?? '';

        if (!is_string($value)) {
            $errorMessage = 'Invalid form data.';
            break;
        }

        $formValues[$field] = trim($value);
    }

    if ($errorMessage === '') {
        $name = $formValues['club_name'];
        $sport = $formValues['sport'];
        $email = $formValues['club_email'];
        $status = $formValues['status'];
        $description = $formValues['description'];

        if (
            $name === '' ||
            $sport === '' ||
            $email === '' ||
            $description === ''
        ) {
            $errorMessage = 'Please complete all required fields.';

        } elseif (
            mb_strlen($name, 'UTF-8') > 150 ||
            mb_strlen($sport, 'UTF-8') > 100 ||
            mb_strlen($description, 'UTF-8') > 255 ||
            strlen($email) > 255
        ) {
            $errorMessage = 'One or more fields are too long.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';

        } elseif (!in_array($status, ['active', 'archived'], true)) {
            $errorMessage = 'Invalid club status.';

        } else {
            try {
                $pdo = db();

                // The sport column has a unique constraint.
                $check = $pdo->prepare(
                    "SELECT club_id
                     FROM sports_clubs
                     WHERE sport = :sport
                     LIMIT 1"
                );

                $check->execute(['sport' => $sport]);

                if ($check->fetchColumn() !== false) {
                    $errorMessage =
                        'A club for this sport already exists.';
                } else {
                    $insert = $pdo->prepare(
                        "INSERT INTO sports_clubs
                            (club_name, sport, description,
                             club_email, status)
                         VALUES
                            (:club_name, :sport, :description,
                             :club_email, :status)"
                    );

                    $insert->execute([
                        'club_name' => $name,
                        'sport' => $sport,
                        'description' => $description,
                        'club_email' => $email,
                        'status' => $status
                    ]);

                    $successMessage =
                        'Sports club created successfully.';

                    // Clear form values after successful creation.
                    $formValues = [
                        'club_name' => '',
                        'sport' => '',
                        'club_email' => '',
                        'status' => 'active',
                        'description' => ''
                    ];
                }

            } catch (PDOException $exception) {
                error_log(
                    'Club creation error: ' .
                    $exception->getMessage()
                );

                if ($exception->getCode() === '23000') {
                    $errorMessage =
                        'A club for this sport already exists.';
                } else {
                    $errorMessage =
                        'Unable to create the club. Please try again.';
                }
            }
        }
    }
} // End Create Club action.

} // End POST request handler.

// Keep the Edit form open after an update submission.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? null) === 'update' &&
    isset($clubId) &&
    is_int($clubId)
) {
    $_GET['edit'] = (string) $clubId;
}

// Load a selected club when an Edit link is clicked.
if (isset($_GET['edit'])) {

    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId === false) {
        $editError = 'Invalid club ID.';
    } else {
        try {
            $editStmt = db()->prepare(
                "SELECT
                    club_id,
                    club_name,
                    sport,
                    description,
                    club_email,
                    status
                 FROM sports_clubs
                 WHERE club_id = :club_id
                 LIMIT 1"
            );

            $editStmt->execute([
                'club_id' => $editId
            ]);

            $editClub = $editStmt->fetch(PDO::FETCH_ASSOC);

if (
    $editClub &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? null) === 'update' &&
    isset($clubId) &&
    is_int($clubId) &&
    (int) $editClub['club_id'] === $clubId &&
    $errorMessage !== '' &&
    isset($updateValues) &&
    count($updateValues) === count($formValues)
) {
    foreach ($formValues as $field => $defaultValue) {
        if (isset($updateValues[$field])) {
            $editClub[$field] = $updateValues[$field];
        }
    }
}

            if (!$editClub) {
                $editError = 'Club not found.';
            }

        } catch (Throwable $exception) {
            error_log(
                'Load club for editing error: ' .
                $exception->getMessage()
            );

            $editError = 'Unable to load club details.';
        }
    }
}

try {
    $stmt = db()->query(
        "SELECT
            club_id,
            club_name,
            sport,
            description,
            club_email,
            status
         FROM sports_clubs
         ORDER BY club_name ASC"
    );

    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {
    error_log(
        'Admin club management error: ' .
        $exception->getMessage()
    );

    $loadError = true;
}

require __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">
    <section class="shell clubs-directory">

        <h1>Manage Sports Clubs</h1>

        <p>
            View and manage sports clubs registered
            with NSBM SportsHub.
        </p>

<section class="club-create-section">

    <h2>Create New Club</h2>

    <p>
        Enter the details below to register
        a new sports club.
    </p>

<?php if (
    ($_POST['action'] ?? '') === 'create' &&
    $successMessage !== ''
): ?>
    <p class="club-create-success" role="status">
        <?= adminClubEscape($successMessage) ?>
    </p>
<?php endif; ?>

<?php if (
    ($_POST['action'] ?? '') === 'create' &&
    $errorMessage !== ''
): ?>
    <p class="club-create-error" role="alert">
        <?= adminClubEscape($errorMessage) ?>
    </p>
<?php endif; ?>

    <form
        method="post"
        action="/clubs/admin/clubs.php"
        class="club-create-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= adminClubEscape($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="create"
        >

        <div class="club-create-field">
            <label for="club_name">Club Name</label>

            <input
                type="text"
                id="club_name"
                name="club_name"
                maxlength="150"
                required
                value="<?= adminClubEscape($formValues['club_name']) ?>"
                placeholder="e.g. Basketball Club"
            >
        </div>

        <div class="club-create-field">
            <label for="sport">Sport</label>

            <input
                type="text"
                id="sport"
                name="sport"
                maxlength="100"
                required
                value="<?= adminClubEscape($formValues['sport']) ?>"
                placeholder="e.g. Basketball"
            >
        </div>

        <div class="club-create-field">
            <label for="club_email">Club Email</label>

            <input
                type="email"
                id="club_email"
                name="club_email"
                maxlength="255"
                required
                value="<?= adminClubEscape($formValues['club_email']) ?>"
                placeholder="e.g. basketball@nsbm.ac.lk"
            >
        </div>

        <div class="club-create-field">
            <label for="status">Status</label>

            <select id="status" name="status" required>
                <option
                    value="active"
                    <?= $formValues['status'] === 'active' ? 'selected' : '' ?>
                >
                    Active
                </option>
                <option
                    value="archived"
                    <?= $formValues['status'] === 'archived' ? 'selected' : '' ?>
                >
                    Archived
                </option>
            </select>
        </div>

        <div class="club-create-field club-create-full">
            <label for="description">
                Club Description
            </label>

            <textarea
                id="description"
                name="description"
                maxlength="255"
                rows="4"
                required
                placeholder="Describe the sports club..."
            ><?= adminClubEscape($formValues['description']) ?></textarea>
        </div>

        <div class="club-create-actions">
            <button type="submit" class="club-create-button">
                Create Club
            </button>
        </div>

    </form>

</section>

<?php if ($editError !== ''): ?>
    <p class="club-create-error" role="alert">
        <?= adminClubEscape($editError) ?>
    </p>
<?php endif; ?>

<?php if ($editClub): ?>

<section class="club-create-section" id="edit-club">

    <h2>Edit Sports Club</h2>

    <p>
        Update the details of
        <?= adminClubEscape($editClub['club_name']) ?>.
    </p>

<?php if (
    ($_POST['action'] ?? '') === 'update' &&
    $successMessage !== ''
): ?>
    <p class="club-create-success" role="status">
        <?= adminClubEscape($successMessage) ?>
    </p>
<?php endif; ?>

<?php if (
    ($_POST['action'] ?? '') === 'update' &&
    $errorMessage !== ''
): ?>
    <p class="club-create-error" role="alert">
        <?= adminClubEscape($errorMessage) ?>
    </p>
<?php endif; ?>

    <form
        method="post"
        action="/clubs/admin/clubs.php?edit=<?= (int) $editClub['club_id'] ?>#edit-club"
        class="club-create-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= adminClubEscape($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="update"
        >

        <input
            type="hidden"
            name="club_id"
            value="<?= (int) $editClub['club_id'] ?>"
        >

        <div class="club-create-field">
            <label for="edit_club_name">Club Name</label>
            <input
                type="text"
                id="edit_club_name"
                name="club_name"
                maxlength="150"
                required
                value="<?= adminClubEscape($editClub['club_name']) ?>"
            >
        </div>

        <div class="club-create-field">
            <label for="edit_sport">Sport</label>
            <input
                type="text"
                id="edit_sport"
                name="sport"
                maxlength="100"
                required
                value="<?= adminClubEscape($editClub['sport']) ?>"
            >
        </div>

        <div class="club-create-field">
            <label for="edit_club_email">Club Email</label>
            <input
                type="email"
                id="edit_club_email"
                name="club_email"
                maxlength="255"
                required
                value="<?= adminClubEscape($editClub['club_email']) ?>"
            >
        </div>

        <div class="club-create-field">
            <label for="edit_status">Status</label>

            <select id="edit_status" name="status" required>
                <option
                    value="active"
                    <?= $editClub['status'] === 'active' ? 'selected' : '' ?>
                >
                    Active
                </option>
                <option
                    value="archived"
                    <?= $editClub['status'] === 'archived' ? 'selected' : '' ?>
                >
                    Archived
                </option>
            </select>
        </div>

        <div class="club-create-field club-create-full">
            <label for="edit_description">
                Club Description
            </label>

            <textarea
                id="edit_description"
                name="description"
                maxlength="255"
                rows="4"
                required
            ><?= adminClubEscape($editClub['description']) ?></textarea>
        </div>

        <div class="club-create-actions">
            <a
                href="/clubs/admin/clubs.php"
                class="club-admin-edit-button"
            >
                Cancel
            </a>

            <button
                type="submit"
                class="club-create-button"
            >
                Save Changes
            </button>
        </div>

    </form>

</section>

<?php endif; ?>

        <?php if ($loadError): ?>

            <p role="alert">
                Unable to load sports clubs.
                Please try again later.
            </p>

        <?php elseif (empty($clubs)): ?>

            <p>No sports clubs found.</p>

        <?php else: ?>

            <table class="club-admin-table">
                <thead>
                    <tr>
                        <th>Club</th>
                        <th>Sport</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($clubs as $club): ?>
                        <tr>
                            <td>
                                <?= adminClubEscape(
                                    $club['club_name']
                                ) ?>
                            </td>

                            <td>
                                <?= adminClubEscape(
                                    $club['sport']
                                ) ?>
                            </td>

                            <td>
                                <?= adminClubEscape(
                                    $club['club_email']
                                ) ?>
                            </td>

                            <td>
                                <?= adminClubEscape(
                                    ucfirst($club['status'])
                                ) ?>
                            </td>

                            <td>
                                <a
                                    href="/clubs/admin/clubs.php?edit=<?= (int) $club['club_id'] ?>"
                                    class="club-admin-edit-button"
                                >
                                    Edit
                                </a>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </section>
</main>

<?php require __DIR__ . '/../../includes/footer.php'; ?>