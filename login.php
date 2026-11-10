<?php
// NSBM SportsHub - User Login


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

auth_start();


// Generate a security token for the login form
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect the submitted values
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    // Validate the CSRF token
    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['login_csrf'], $submittedToken)
    ) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif (
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 255 ||
        !is_string($password) ||
        $password === ''
    ) {
        $errors[] = 'Invalid email or password.';

    } else {

        $email = strtolower(trim($email));

        try {
            // Find the account by email
            $stmt = db()->prepare(
                "SELECT user_id, name, email, password, role
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );

            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check the hashed password
            if (
                !$user ||
                !password_verify($password, $user['password'])
            ) {
                $errors[] = 'Invalid email or password.';

            } else {

                // Prevent session fixation
                session_regenerate_id(true);

                // Store authenticated user details
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];

                // Rotate the CSRF token
                $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
            }

        } catch (PDOException $e) {
            error_log('Login database error: ' . $e->getMessage());

            $errors[] = 'Unable to log in right now. Please try again.';
        }
    }
}

 // Generate a logout CSRF token for logged-in users
if (
    !empty($_SESSION['user_id']) &&
    empty($_SESSION['logout_csrf'])
) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

?>


<?php require __DIR__ . '/includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/student-accounts.css">


<main class="accounts-page login-layout-page">

    <div class="shell login-layout">

        <?php if (empty($_SESSION['user_id'])): ?>
            <div class="login-welcome">
                <h1>Welcome <br>back.</h1>
                <p>
                    Sign in to follow your sports activities
                    and club memberships.
                </p>
            </div>
        <?php endif; ?>

        <section class="accounts-container login-card"
                 aria-labelledby="login-title">

            <?php if (!empty($_SESSION['user_id'])): ?>
                <h1 id="login-title">Your Account</h1>
            <?php else: ?>
                <h2 id="login-title">Log in</h2>
            <?php endif; ?>



<?php if (!empty($_SESSION['user_id'])): ?>
    <div role="status">
        <p>
            Login successful! Welcome,
            <?= htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8') ?>.
        </p>
        <p>
            Account role:
            <?= htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8') ?>
        </p>

<?php if (current_user_role() === 'student'): ?>
    <a href="/student/profile.php" class="accounts-profile-button">
        Go to My Profile
    </a>
<?php endif; ?>

<form action="/logout.php" method="POST">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>"
    >

<button type="submit" class="accounts-logout-button">
    Log Out
</button>
</form>

 </div>
<?php else: ?>


    <?php if (!empty($errors)): ?>
        <div role="alert">
            <?php foreach ($errors as $error): ?>
                <p>
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <label for="email">Email Address</label>
        <input
            type="email"
            id="email"
            name="email"
            maxlength="255"
            required
        >

        <label for="password">Password</label>

<div class="password-field">
    <input
        type="password"
        id="password"
        name="password"
        required
    >

    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="password"
        aria-label="Show password"
        aria-pressed="false"
    >
        Show
    </button>
</div>


        <button type="submit">Log in</button>
    </form>

    <p>
        Don't have an account?
        <a href="register.php">Create Student Account</a>
    </p>
    <?php endif; ?>


        </section>
    </div>
</main>

<script src="/assets/js/student-accounts.js" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
