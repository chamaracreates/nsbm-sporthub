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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>My Profile | NSBM SportsHub</title>
</head>
<body>

    <h1>NSBM SportsHub</h1>
    <h2>My Student Profile</h2>

    <p>
        Welcome,
        <?= htmlspecialchars(
            $studentName,
            ENT_QUOTES,
            'UTF-8'
        ) ?>!
    </p>


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

<h3>Personal Information</h3>

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

<h3>Academic Information</h3>

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

<h3>About Me</h3>

<p>
    <?= htmlspecialchars(
        (string) ($studentProfile['about'] ?? 'Not provided'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>


    <p>This is your protected student profile page.</p>

</body>
</html>
