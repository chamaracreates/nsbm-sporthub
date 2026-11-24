<?php


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

auth_start();

if (current_user_role() !== null) {
    header('Location: /index.php', true, 302);
    exit;
}


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


    // Validate submitted field types before string operations.
    $fields = [
        'name',
        'student_number',
        'email',
        'password',
        'confirm_password'
    ];

    foreach ($fields as $field) {
        if (
            isset($_POST[$field]) &&
            !is_string($_POST[$field])
        ) {
            $errors[] = 'Invalid registration form data.';
            break;
        }
    }


    $input = static function (string $field): string {
        $value = $_POST[$field] ?? '';
        return is_string($value) ? trim($value) : '';
    };

    $name = $input('name');
    $studentNumber = $input('student_number');
    $email = strtolower($input('email'));

    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';

    $confirmPassword = $_POST['confirm_password'] ?? '';
    $confirmPassword = is_string($confirmPassword)
        ? $confirmPassword
        : '';

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

    $passwordError = student_password_error($password);
    if ($passwordError !== null) {
        $errors[] = $passwordError;
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


            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role)
                 VALUES (:name, :email, :password, 'student')"
            );

            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => $hashedPassword
            ]);


            $userId = (int) $pdo->lastInsertId();


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

            // Log in the newly registered student.
            // Regenerate the session ID to prevent session fixation.
            if (session_regenerate_id(true)) {
                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = 'student';
                $_SESSION['name'] = $name;


                $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));


                unset($_SESSION['registration_csrf']);


                header('Location: /index.php', true, 303);
                exit;
            }

            // Fall back to manual login if session rotation fails.
            error_log('Registration succeeded but session rotation failed.');
            $success = true;
            $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {


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


<?php
$pageStylesheets = ['/assets/css/student-accounts.css'];
$pageTitle = 'Create account';
require __DIR__ . '/includes/header.php';
?>


<main class="accounts-page register-layout-page auth-page">
    <div class="shell register-layout auth-layout">
        <div class="register-heading auth-heading">
            <h1 id="register-title">Create your account</h1>
            <p>Join sports clubs and manage your participation in SportsHub.</p>
        </div>

        <section class="accounts-container register-card auth-card" aria-labelledby="register-title">
            <?php if ($success): ?>
                <div class="form-success" role="status">
                    Your student account was created successfully! You can now log in.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <p>Please correct the following errors:</p>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" class="accounts-form auth-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['registration_csrf'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="auth-field registration-field">
                    <label for="name">Full name</label>
                    <input
                        type="text" id="name" name="name" maxlength="100"
                        autocomplete="name" placeholder="Enter your full name"
                        value="<?= htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8') ?>" required
                    >
                </div>

                <div class="auth-field registration-field">
                    <label for="student_number">NSBM Student ID</label>
                    <input
                        type="text" id="student_number" name="student_number" maxlength="32"
                        placeholder="Enter your NSBM Student ID"
                        value="<?= htmlspecialchars($studentNumber ?? '', ENT_QUOTES, 'UTF-8') ?>" required
                    >
                </div>

                <div class="auth-field registration-field registration-field-wide">
                    <label for="email">Email address</label>
                    <input
                        type="email" id="email" name="email" maxlength="255"
                        autocomplete="email" placeholder="Enter your email address"
                        value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>" required
                    >
                </div>

                <div class="auth-field registration-field">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input
                            type="password" id="password" name="password"
                            minlength="8" maxlength="72" autocomplete="new-password"
                            pattern="(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{Nd})(?=.*[\p{P}\p{S}]).{8,}"
                            title="Use at least 8 characters, including uppercase and lowercase letters, a number and a special character."
                            aria-describedby="registration-password-requirements"
                            placeholder="Enter your password" required
                        >
                        <button type="button" class="toggle-password" data-password-toggle
                                aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <small id="registration-password-requirements">Use at least 8 characters, including uppercase and lowercase letters, a number and a special character.</small>
                </div>

                <div class="auth-field registration-field">
                    <label for="confirm_password">Confirm password</label>
                    <div class="password-field">
                        <input
                            type="password" id="confirm_password" name="confirm_password"
                            minlength="8" maxlength="72" autocomplete="new-password"
                            placeholder="Re-enter your password" required
                        >
                        <button type="button" class="toggle-password" data-password-toggle
                                aria-controls="confirm_password" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <div class="registration-actions auth-actions">
                    <p><span>Already have an account?</span><a href="/login.php">Log in</a></p>
                    <button type="submit" class="accounts-submit">Create account</button>
                </div>
            </form>
        </section>
    </div>
</main>
<script src="/assets/js/student-accounts.js" defer></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
