<?php
// NSBM SportsHub - Student Profile

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Redirect visitors who are not logged in
$userId = require_login();

// Only students can access this page
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

$studentName = $_SESSION['name'] ?? 'Student';

// Retrieve the logged-in student's official NSBM ID
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
        sp.about
     FROM users u
     LEFT JOIN student_profiles sp
        ON sp.user_id = u.user_id
     WHERE u.user_id = :user_id
       AND u.role = 'student'
     LIMIT 1"
);


    $stmt->execute([
        ':user_id' => $userId
    ]);

    $studentProfile = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Student profile error: ' . $e->getMessage());
}

?>


<?php require __DIR__ . '/../includes/header.php'; ?>

<link rel="stylesheet" href="/assets/css/student-accounts.css">

<main class="student-profile-page">
    <div class="shell profile-layout">

        <header class="profile-heading">
            <p class="profile-eyebrow">STUDENT ACCOUNT</p>
            <h1>My Student Profile</h1>
            <p>Manage your personal information and SportsHub activity.</p>
        </header>


    <p>
        Welcome,
        <?= htmlspecialchars(
            $studentName,
            ENT_QUOTES,
            'UTF-8'
        ) ?>!
    </p>

<section class="profile-card" aria-labelledby="identity-title">
    <h2 id="identity-title">Account Identity</h2>

    <div class="profile-card-grid">
<p>
    User ID (Internal):
    <?= (int) $userId ?>
</p>


<p>
    NSBM Student ID:
    <?= htmlspecialchars(
        (string) ($studentProfile['student_number'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>
    </div>
</section>
<section class="profile-card" aria-labelledby="personal-title">
    <h2 id="personal-title">Personal Information</h2>

    <div class="profile-card-grid">

<p>
    Full Name:
    <?= htmlspecialchars(
        (string) ($studentProfile['name'] ?? 'Not available'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

<p>
    Email Address:
    <?= htmlspecialchars(
        (string) ($studentProfile['email'] ?? 'Not available'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

<p>
    Phone Number:
    <?= htmlspecialchars(
        (string) ($studentProfile['phone'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>
    </div>
</section>
<section class="profile-card" aria-labelledby="academic-title">
    <h2 id="academic-title">Academic Information</h2>

    <div class="profile-card-grid">
<p>
    Study Program:
    <?= htmlspecialchars(
        (string) ($studentProfile['study_program'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

<p>
    Academic Year:
    <?= htmlspecialchars(
        (string) ($studentProfile['academic_year'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>
    </div>
</section>
<section class="profile-card" aria-labelledby="about-title">
    <h2 id="about-title">About Me</h2>

    <div class="profile-card-grid profile-card-grid-single">

<p>
    <?= htmlspecialchars(
        (string) ($studentProfile['about'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

    </div>
</section>

<nav class="profile-actions" aria-label="Student account actions">

    <a href="/student/edit-profile.php"
       class="profile-action profile-action-primary">
        Edit Profile
    </a>

    <a href="/student/change-password.php"
       class="profile-action profile-action-secondary">
        Change Password
    </a>

    <a href="/student/history.php"
       class="profile-action profile-action-secondary">
        Participation History
    </a>

</nav>


    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
