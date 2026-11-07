<?php
// NSBM SportsHub - Student Registration

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

auth_start();

// Generate the registration CSRF token
if (empty($_SESSION['registration_csrf'])) {
    $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}


$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify the registration form's CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['registration_csrf'], $submittedToken)
    ) {
        $errors[] = 'Invalid form submission. Please refresh the page and try again.';
    }


    // Collect submitted form values
    $name = trim($_POST['name'] ?? '');
    $studentNumber = trim($_POST['student_number'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Server-side validation
    if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Please enter a valid full name.';
    }

    if ($studentNumber === '' || strlen($studentNumber) > 32) {
        $errors[] = 'Please enter a valid NSBM Student ID.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 10) {
        $errors[] = 'Password must contain at least 10 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    // Create the student account only if validation passed
    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Securely hash the student's password
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Create the account
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role)
                 VALUES (:name, :email, :password, 'student')"
            );

            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => $hashedPassword
            ]);

            // Get the automatically generated internal user ID
            $userId = (int) $pdo->lastInsertId();

            // Create the linked student profile
            $stmt = $pdo->prepare(
                "INSERT INTO student_profiles (user_id, student_number)
                 VALUES (:user_id, :student_number)"
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':student_number' => $studentNumber
            ]);

            // Save both records together
            $pdo->commit();

            $success = true;

            // Rotate the CSRF token after successful registration
            $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {

            // Undo unfinished database changes
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                $errors[] = 'Email or student ID is already registered.';
            } else {
                $errors[] = 'Registration could not be completed. Please try again.';
            }
        }
    }

}
?>

<!DOCKTYPE html>
<html lang= "en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration | NSBM SportsHub</title>
</head>
<body>

<h1>NSBM SportsHub</h1>
    <h2>Create Student Account</h2>

<?php if ($success): ?>
    <div class="form-success" role="status">
        Your student account was created successfully!
        You can now log in.
    </div>
<?php endif; ?>


<?php if (!empty($errors)): ?>
    <div class="form-errors" role="alert">
        <p>Please correct the following errors:</p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li>
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


    <form action="register.php" method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars($_SESSION['registration_csrf'], ENT_QUOTES, 'UTF-8') ?>"
>


<label for="name">Full Name</label>
        <input type="text" id="name" name="name"
               maxlength="100" required>

<label for="student_number">NSBM Student ID</label>
<input
    type="text"
    id="student_number"
    name="student_number"
    maxlength="32"
    placeholder="Enter your official NSBM Student ID"
    required
>


<label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               maxlength="255" required>

<label for="password">Password</label>
        <input type="password" id="password" name="password"
               minlength="10" required>

<label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password"
               name="confirm_password" minlength="10" required>

        <button type="submit">Create Account</button>

    </form>

<p>Already have an account?
        <a href="login.php">Login here</a>
    </p>

</body>
</html>