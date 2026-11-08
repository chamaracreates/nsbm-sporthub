<?php
// NSBM SportsHub - Edit Student Profile

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only logged-in users can access this page
$userId = require_login();

// Only students can edit student profiles
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

// Retrieve the student's existing profile
try {
    $stmt = db()->prepare(
        "SELECT
            student_number,
            phone,
            study_program,
            academic_year,
            about
         FROM student_profiles
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        http_response_code(404);
        exit('Student profile not found.');
    }

} catch (PDOException $e) {
    error_log('Edit profile error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your profile.');
}

if (empty($_SESSION['edit_profile_csrf'])) {
    $_SESSION['edit_profile_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check the form's security token
    $token = $_POST['csrf_token'] ?? null;

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['edit_profile_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    // Validate text fields
    $limits = [
        'phone' => 24,
        'study_program' => 120,
        'about' => 300
    ];

    $values = [];

    foreach ($limits as $field => $limit) {
        $input = $_POST[$field] ?? '';

        if (!is_string($input)) {
            $errors[] = 'Invalid value for ' . $field;
            continue;
        }

        $values[$field] = trim($input);

        if (strlen($values[$field]) > $limit) {
            $errors[] = $field . ' is too long.';
        }

        // Keep entered values visible if validation fails
        $profile[$field] = $values[$field];
    }

    // Validate academic year
    $academicYear = $_POST['academic_year'] ?? '';

    $allowedYears = [
        '', 'Year 1', 'Year 2',
        'Year 3', 'Year 4', 'Other'
    ];

    if (
        !is_string($academicYear) ||
        !in_array($academicYear, $allowedYears, true)
    ) {
        $errors[] = 'Please select a valid academic year.';
    } else {
        $profile['academic_year'] = $academicYear;
    }

    // Save only when validation succeeds
    if (empty($errors)) {
        try {
            $stmt = db()->prepare(
                "UPDATE student_profiles
                 SET phone = :phone,
                     study_program = :study_program,
                     academic_year = :academic_year,
                     about = :about
                 WHERE user_id = :user_id"
            );

            $stmt->execute([
                ':phone' => $values['phone'] ?: null,
                ':study_program' => $values['study_program'] ?: null,
                ':academic_year' => $academicYear ?: null,
                ':about' => $values['about'] ?: null,
                ':user_id' => $userId
            ]);

            header('Location: /student/profile.php', true, 303);
            exit;

        } catch (PDOException $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            $errors[] = 'Unable to save changes. Please try again.';
        }
    }
}


?>


<?php require __DIR__ . '/../includes/header.php'; ?>

<link rel="stylesheet" href="/assets/css/student-accounts.css">

<main class="student-profile-page edit-profile-page">
    <div class="shell profile-layout">

        <header class="profile-heading">
            <p class="profile-eyebrow">STUDENT ACCOUNT</p>

            <h1>Edit My Student Profile</h1>

            <p>
                Update your personal and academic information.
            </p>
        </header>



<?php if (!empty($errors)): ?>
    <div role="alert">
        <p>Please correct the following errors:</p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li>
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="profile-card edit-profile-identity"
         aria-labelledby="edit-identity-title">

    <h2 id="edit-identity-title">Account Identity</h2>

    <div class="profile-card-grid">
<p>User ID (Internal): <?= (int) $userId ?></p>

<p>
    NSBM Student ID:
    <?= htmlspecialchars(
        (string) ($profile['student_number'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>
    </div>

<center><p class="identity-note">
    Your account IDs cannot be changed here.
</p></center>
</section>
<form action="edit-profile.php"
      method="POST"
      class="edit-profile-form">
    <input type="hidden" name="csrf_token"
        value="<?= htmlspecialchars(
            $_SESSION['edit_profile_csrf'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>">

    <p>
        <label for="phone">Phone Number</label>
        <input
            type="tel"
            id="phone"
            name="phone"
            maxlength="24"
            value="<?= htmlspecialchars(
                (string) ($profile['phone'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >
    </p>

    <p>
        <label for="study_program">Study Program</label>
        <input
            type="text"
            id="study_program"
            name="study_program"
            maxlength="120"
            value="<?= htmlspecialchars(
                (string) ($profile['study_program'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >
    </p>

    <p>
        <label for="academic_year">Academic Year</label>

        <select id="academic_year" name="academic_year">
            <?php
            $year = (string) ($profile['academic_year'] ?? '');
            ?>

            <option value="">Select academic year</option>

            <?php foreach (
                ['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Other']
                as $option
            ): ?>
                <option
                    value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $year === $option ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label for="about">About Me</label>
        <textarea
            id="about"
            name="about"
            rows="5"
            maxlength="300"
        ><?= htmlspecialchars(
            (string) ($profile['about'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>
    </p>

   <button type="submit" class="accounts-submit">
    Save Changes
</button>

</form>


<p class="edit-profile-back">
    <a href="profile.php" class="edit-profile-back-link">
        ← Back to My Profile
    </a>
</p>


    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
