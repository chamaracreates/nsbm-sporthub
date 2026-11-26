<?php
// Display the authenticated student profile.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

// Serve only the authenticated student's image; do not expose a user selector.
if (isset($_GET['photo'])) {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    if ($_GET['photo'] !== '1' || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(404);
        exit;
    }
    try {
        $photoStmt = db()->prepare('SELECT profile_photo FROM student_profiles WHERE user_id = :user_id');
        $photoStmt->execute(['user_id' => $userId]);
        $photo = $photoStmt->fetchColumn();
        $image = is_string($photo) && $photo !== '' ? @getimagesizefromstring($photo) : false;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if ($image === false || !in_array($image['mime'] ?? '', $allowed, true)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $image['mime']);
        header('Content-Length: ' . strlen($photo));
        echo $photo;
        exit;
    } catch (PDOException $e) {
        error_log('Student photo read failed: ' . $e->getMessage());
        http_response_code(500);
        exit;
    }
}

$studentName = $_SESSION['name'] ?? 'Student';
$studentProfile = null;

try {
    $stmt = db()->prepare(
        "SELECT
            u.name,
            u.email,
            sp.student_number,
            sp.phone,
            sp.study_program,
            sp.academic_year,
            sp.about,
            (sp.profile_photo IS NOT NULL) AS has_photo
         FROM users u
         LEFT JOIN student_profiles sp
            ON sp.user_id = u.user_id
         WHERE u.user_id = :user_id
           AND u.role = 'student'
         LIMIT 1"
    );

    $stmt->execute([
        ':user_id' => $userId,
    ]);

    $studentProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log('Student profile error: ' . $e->getMessage());
}

$hasPhoto = !empty($studentProfile['has_photo']);
$displayName = (string) ($studentProfile['name'] ?? $studentName ?? 'Student');
$displayEmail = (string) ($studentProfile['email'] ?? 'Not available');
$studentNumber = (string) ($studentProfile['student_number'] ?? 'Not provided');
$phoneNumber = (string) ($studentProfile['phone'] ?? 'Not provided');
$studyProgram = (string) ($studentProfile['study_program'] ?? 'Not provided');
$academicYear = (string) ($studentProfile['academic_year'] ?? 'Not provided');
$aboutText = trim((string) ($studentProfile['about'] ?? ''));
$aboutText = $aboutText !== ''
    ? $aboutText
    : 'No profile summary provided.';

$pageStylesheets = ['/assets/css/student-accounts.css'];
require __DIR__ . '/../includes/header.php';
?>

<main class="student-profile-page student-identity-page">
    <div class="shell student-profile-shell">
        <header class="student-identity-header" aria-labelledby="student-profile-title">
            <a class="student-identity-avatar" href="/student/edit-profile.php#profile-photo" aria-label="Edit profile photo">
                <?php if ($hasPhoto): ?>
                    <img src="/student/profile.php?photo=1" alt="" width="96" height="96">
                <?php else: ?>
                    <svg aria-hidden="true" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="24" cy="15" r="7"/><path d="M10 39v-2a14 14 0 0 1 28 0v2"/></svg>
                <?php endif; ?>
                <span class="student-avatar-edit" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h4l2-3h4l2 3h4v13H4z"/><circle cx="12" cy="13" r="3"/></svg></span>
            </a>
            <div class="student-identity-copy">
                <h1 id="student-profile-title"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="student-identity-program"><?= htmlspecialchars($studyProgram, ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <nav class="student-identity-nav" aria-label="Student profile actions">
                <a href="/student/edit-profile.php" class="student-profile-action student-profile-action-primary">Edit profile</a>
                <a href="/student/history.php" class="student-profile-action student-profile-action-secondary">Participation history</a>
                <a href="/clubs/my-memberships.php" class="student-profile-action student-profile-action-secondary">My memberships</a>
                <a href="/student/registrations.php" class="student-profile-action student-profile-action-secondary">My registrations</a>
                <a href="/bookings/history.php" class="student-profile-action student-profile-action-secondary">My bookings</a>
            </nav>
        </header>
        <div class="student-identity-details">
            <section class="student-identity-section" aria-labelledby="student-about-title">
                <h2 id="student-about-title">About</h2>
                <div>
                    <p class="student-identity-about"><?= nl2br(htmlspecialchars($aboutText, ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
            </section>
            <section class="student-identity-section" aria-labelledby="student-personal-title">
                <h2 id="student-personal-title">Personal information</h2>
                <dl class="student-identity-facts">
                    <div><dt>Full name</dt><dd><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt>Email address</dt><dd><?= htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt>Phone number</dt><dd><?= htmlspecialchars($phoneNumber, ENT_QUOTES, 'UTF-8') ?></dd></div>
                </dl>
            </section>
            <section class="student-identity-section" aria-labelledby="student-academic-title">
                <h2 id="student-academic-title">Study details</h2>
                <dl class="student-identity-facts">
                    <div><dt>NSBM Student ID</dt><dd><?= htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt>Study programme</dt><dd><?= htmlspecialchars($studyProgram, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    <div><dt>Academic year</dt><dd><?= htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8') ?></dd></div>
                </dl>
            </section>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
