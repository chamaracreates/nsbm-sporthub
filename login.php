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

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | NSBM SportsHub</title>
</head>

<body>

    <h1>NSBM SportsHub</h1>
    <h2>Login to Your Account</h2>

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

<form action="/logout.php" method="POST">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>"
    >

    <button type="submit">Log Out</button>
</form>

    </div>
<?php endif; ?>


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
        <input
            type="password"
            id="password"
            name="password"
            required
        >

        <button type="submit">Login</button>

    </form>

    <p>
        Don't have an account?
        <a href="register.php">Create Student Account</a>
    </p>

</body>
</html>
