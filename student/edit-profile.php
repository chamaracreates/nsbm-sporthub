<?php
// Update the authenticated student profile.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

try {
    $stmt = db()->prepare(
        "SELECT
            student_number,
            phone,
            study_program,
            academic_year,
            about,
            (profile_photo IS NOT NULL) AS has_photo
         FROM student_profiles
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        ':user_id' => $userId,
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

$studyProgramOptions = [
    'BSc (Hons) in Software Engineering',
    'BSc (Hons) in Computer Science',
    'BSc (Hons) in Data Science',
    'BSc (Hons) in Cyber Security',
    'BSc (Hons) in Information Systems',
    'BSc (Hons) in Network Engineering',
    'BBA (Hons)',
    'BA (Hons) in Interior Design',
    'BSc (Hons) in Quantity Surveying',
    'BSc (Hons) in Biomedical Science',
    'Other',
];

$currentStudyProgram = trim((string) ($profile['study_program'] ?? ''));
if ($currentStudyProgram !== '' && !in_array($currentStudyProgram, $studyProgramOptions, true)) {
    array_unshift($studyProgramOptions, $currentStudyProgram);
}

if (empty($_SESSION['edit_profile_csrf'])) {
    $_SESSION['edit_profile_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!is_string($token) || !hash_equals($_SESSION['edit_profile_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    $photoAction = $_POST['photo_action'] ?? null;

    if (($_POST['form_type'] ?? null) === 'photo' || $photoAction !== null) {
        // Validate uploaded bytes rather than trusting the filename or client MIME type.
        $photo = null;
        if ($photoAction === 'upload') {
            $file = $_FILES['profile_photo'] ?? null;
            if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK ||
                !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'Choose a JPEG, PNG or WebP image no larger than 2 MB.';
            } else {
                $size = filesize($file['tmp_name']);
                if ($size === false || $size < 1 || $size > 2 * 1024 * 1024) {
                    $errors[] = 'The profile photo must be no larger than 2 MB.';
                } else {
                    $photo = file_get_contents($file['tmp_name']);
                    $image = is_string($photo) ? @getimagesizefromstring($photo) : false;
                    $mime = is_string($photo) ? (new finfo(FILEINFO_MIME_TYPE))->buffer($photo) : false;
                    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                    if ($image === false || !in_array($mime, $allowed, true) || ($image['mime'] ?? null) !== $mime) {
                        $errors[] = 'Choose a valid JPEG, PNG or WebP image.';
                    } elseif ($image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096 || $image[0] * $image[1] > 12000000) {
                        $errors[] = 'Use an image up to 4096 pixels per side and 12 megapixels.';
                    }
                }
            }
        } elseif ($photoAction !== 'remove') {
            $errors[] = 'Invalid photo action.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare('UPDATE student_profiles SET profile_photo = :photo WHERE user_id = :user_id');
                $stmt->bindValue(':photo', $photo, $photo === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();
                header('Location: /student/profile.php', true, 303);
                exit;
            } catch (PDOException $e) {
                error_log('Student photo update failed: ' . $e->getMessage());
                $errors[] = 'Unable to save your profile photo. Please try again.';
            }
        }
    } else {
        $limits = [
            'phone' => 24,
            'about' => 300,
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

            $profile[$field] = $values[$field];
        }

        $studyProgram = trim((string) ($_POST['study_program'] ?? ''));
        $allowedPrograms = array_merge([''], $studyProgramOptions);

        if (!in_array($studyProgram, $allowedPrograms, true)) {
            $errors[] = 'Please select a valid study program.';
        } else {
            $profile['study_program'] = $studyProgram;
        }

        $academicYear = $_POST['academic_year'] ?? '';
        $allowedYears = ['', 'Year 1', 'Year 2', 'Year 3', 'Year 4', 'Other'];

        if (!is_string($academicYear) || !in_array($academicYear, $allowedYears, true)) {
            $errors[] = 'Please select a valid academic year.';
        } else {
            $profile['academic_year'] = $academicYear;
        }

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
                    ':study_program' => $studyProgram ?: null,
                    ':academic_year' => $academicYear ?: null,
                    ':about' => $values['about'] ?: null,
                    ':user_id' => $userId,
                ]);

                header('Location: /student/profile.php', true, 303);
                exit;
            } catch (PDOException $e) {
                error_log('Profile update failed: ' . $e->getMessage());
                $errors[] = 'Unable to save changes. Please try again.';
            }
        }
    }
}

$pageStylesheets = ['/assets/css/student-accounts.css'];
require __DIR__ . '/../includes/header.php';
?>

<main class="student-profile-page edit-profile-page">
    <div class="shell profile-layout">
        <header class="profile-heading">
            <h1>Edit profile</h1>
            <p>Update your personal and academic information.</p>
        </header>

        <?php if (!empty($errors)): ?>
            <div role="alert" class="form-errors">
                <p>Please correct the following errors:</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="profile-card student-photo-panel" id="profile-photo" aria-labelledby="student-photo-title">
            <h2 id="student-photo-title">Profile photo</h2>
            <div class="student-photo-settings">
                <div class="student-photo-preview" aria-hidden="true">
                    <?php if (!empty($profile['has_photo'])): ?>
                        <img src="/student/profile.php?photo=1" alt="" width="96" height="96">
                    <?php else: ?>
                        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="24" cy="15" r="7"/><path d="M10 39v-2a14 14 0 0 1 28 0v2"/></svg>
                    <?php endif; ?>
                </div>
                <form class="student-photo-form" action="edit-profile.php#profile-photo" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['edit_profile_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="form_type" value="photo">
                    <label for="profile_photo">Choose a photo</label>
                    <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="photo-help">
                    <p id="photo-help">JPEG, PNG or WebP, up to 2 MB. Maximum 4096 pixels per side and 12 megapixels.</p>
                    <div class="student-photo-actions">
                        <button class="student-profile-action student-profile-action-primary" name="photo_action" value="upload" type="submit"><?= !empty($profile['has_photo']) ? 'Replace photo' : 'Upload photo' ?></button>
                        <?php if (!empty($profile['has_photo'])): ?>
                            <button class="student-profile-action student-profile-action-secondary" name="photo_action" value="remove" type="submit">Remove photo</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <form action="edit-profile.php" method="POST" class="edit-profile-form profile-card" aria-labelledby="edit-details-title">
            <h2 id="edit-details-title">Personal and academic information</h2>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['edit_profile_csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <p>
                <label for="phone">Phone number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="24"
                    value="<?= htmlspecialchars((string) ($profile['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                >
            </p>

            <p>
                <label for="study_program">Study programme</label>
                <select id="study_program" name="study_program">
                    <option value="">Select study program</option>
                    <?php $selectedProgram = (string) ($profile['study_program'] ?? ''); ?>
                    <?php foreach ($studyProgramOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedProgram === $option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="academic_year">Academic year</label>
                <select id="academic_year" name="academic_year">
                    <?php $year = (string) ($profile['academic_year'] ?? ''); ?>
                    <option value="">Select academic year</option>
                    <?php foreach (['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Other'] as $option): ?>
                        <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $year === $option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="about">About me</label>
                <textarea id="about" name="about" rows="5" maxlength="300"><?= htmlspecialchars((string) ($profile['about'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </p>

            <div class="student-edit-actions">
                <a href="profile.php" class="student-profile-action student-profile-action-secondary">Cancel</a>
                <button type="submit" class="student-profile-action student-profile-action-primary">Save changes</button>
            </div>
        </form>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
