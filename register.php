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


<?php require __DIR__ . '/includes/header.php'; ?>

<link rel="stylesheet" href="/assets/css/student-accounts.css">


<main class="accounts-page register-layout-page">

    <div class="shell register-layout">

        <!-- Left welcome section -->
        <div class="register-welcome">

            <h1>Start your<br>journey.</h1>

            <p>
                Create an account to apply for sports clubs
                and follow your participation.
            </p>

        </div>

        <!-- Right registration card -->
        <section class="accounts-container register-card"
                 aria-labelledby="register-title">

            <h2 id="register-title">Create account</h2>


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


<form action="register.php" method="POST" class="accounts-form">
<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars($_SESSION['registration_csrf'], ENT_QUOTES, 'UTF-8') ?>"
>


<label for="name">Full Name</label>
        <input type="text" id="name" name="name"
               maxlength="100" placeholder="Enter your full name" required>

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
               maxlength="255" placeholder="Enter your email address" required>

<label for="password">Password</label>

<div class="password-field">
    <input
        type="password"
        id="password"
        name="password"
        minlength="10"
        placeholder="Enter your password (at least 10 characters)"
        required
    >
    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="password"
        aria-label="Show password"
        aria-pressed="false"
    >Show</button>
</div>


<label for="confirm_password">Confirm Password</label>

<div class="password-field">
    <input
        type="password"
        id="confirm_password"
        name="confirm_password"
        minlength="10"
        placeholder="Re-enter your password"
        required
    >
    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="confirm_password"
        aria-label="Show password"
        aria-pressed="false"
    >Show</button>
</div>


        <button type="submit" class="accounts-submit">Create Account</button>

    </form>

<p>Already have an account?
        <a href="login.php">Login here</a>
    </p>


            </section>
    </div>
</main>
<script src="/assets/js/student-accounts.js" defer></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
