<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

auth_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $existingRole = current_user_role();
    if ($existingRole !== null) {
        header('Location: /index.php', true, 302);
        exit;
    }
}


if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

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
        $password === '' || strlen($password) > 72 || str_contains($password, "\0")
    ) {
        $errors[] = 'Invalid email or password.';

    } else {

        $email = strtolower(trim($email));

        try {
            if (!auth_login_rate_limit($email)) {
                http_response_code(429);
                header('Retry-After: 300');
                $errors[] = 'Too many login attempts. Please try again in five minutes.';
            } else {
                $stmt = db()->prepare(
                    "SELECT user_id, name, email, password, role
                     FROM users
                     WHERE email = :email
                     LIMIT 1"
                );

                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    !$user ||
                    !password_verify($password, $user['password'])
                ) {
                    $errors[] = 'Invalid email or password.';

                } else {

                    if (!session_regenerate_id(true)) {
                        $errors[] = 'Unable to start a secure session. Please try again.';
                    } else {
                        auth_login_rate_limit($email, true);
                        $_SESSION['user_id'] = (int) $user['user_id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
                        $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

                        header('Location: /index.php', true, 303);
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            http_response_code(503);
            error_log('Login service error: ' . $e->getMessage());

            $errors[] = 'Unable to log in right now. Please try again.';
        }
    }
}


if (
    !empty($_SESSION['user_id']) &&
    empty($_SESSION['logout_csrf'])
) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

?>


<?php
$pageStylesheets = ['/assets/css/student-accounts.css'];
$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>


<main class="accounts-page login-layout-page auth-page">
    <div class="shell login-layout auth-layout">
        <div class="auth-heading">
            <h1 id="login-title">Log in</h1>
            <p>Access your SportsHub account and sporting activities.</p>
        </div>

        <section class="accounts-container login-card auth-card" aria-labelledby="login-title">
            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="accounts-form auth-form">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="auth-field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" maxlength="255"
                           autocomplete="username" placeholder="Enter your email address"
                           value="<?= htmlspecialchars(is_string($email ?? null) ? $email : '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input type="password" id="password" name="password"
                               autocomplete="current-password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-password" data-password-toggle
                                aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <div class="auth-actions">
                    <p><span>Don't have an account?</span><a href="/register.php">Create account</a></p>
                    <button type="submit" class="accounts-submit">Log in</button>
                </div>
            </form>
        </section>
    </div>
</main>
<script src="/assets/js/student-accounts.js" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
