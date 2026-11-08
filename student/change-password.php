<?php
// NSBM SportsHub - Change Student Password

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only authenticated students can change their password
$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

// Generate a security token
if (empty($_SESSION['password_csrf'])) {
    $_SESSION['password_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Step 1: Validate the CSRF token
    $token = $_POST['csrf_token'] ?? null;

    if (
        !is_string($token) ||
        !is_string($_SESSION['password_csrf'] ?? null) ||
        !hash_equals($_SESSION['password_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    // Step 2: Read the entered passwords
    $currentPassword = $_POST['current_password'] ?? null;
    $newPassword = $_POST['new_password'] ?? null;
    $confirmPassword = $_POST['confirm_password'] ?? null;

    // Step 3: Server-side validation
    if (
        !is_string($currentPassword) ||
        !is_string($newPassword) ||
        !is_string($confirmPassword) ||
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        $errors[] = 'Please complete all password fields.';

    } elseif (strlen($newPassword) < 10) {
        $errors[] = 'New password must contain at least 10 characters.';

    } elseif (strlen($newPassword) > 1024) {
        $errors[] = 'New password is too long.';

    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match.';
    }

    // Step 4: Verify and update the password
    if (empty($errors)) {
        try {
            $pdo = db();

            $stmt = $pdo->prepare(
                "SELECT password
                 FROM users
                 WHERE user_id = :user_id
                   AND role = 'student'
                 LIMIT 1"
            );

            $stmt->execute([
                ':user_id' => $userId
            ]);

            $storedHash = $stmt->fetchColumn();

            if (
                !is_string($storedHash) ||
                !password_verify($currentPassword, $storedHash)
            ) {
                $errors[] = 'Current password is incorrect.';

            } elseif (password_verify($newPassword, $storedHash)) {
                $errors[] = 'New password must be different from the current password.';

            } else {

                // Hash the new password
                $newHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                // Update only the authenticated student's password
                $update = $pdo->prepare(
                    "UPDATE users
                     SET password = :new_password
                     WHERE user_id = :user_id
                       AND role = 'student'
                       AND password = :old_hash"
                );

                $update->execute([
                    ':new_password' => $newHash,
                    ':user_id' => $userId,
                    ':old_hash' => $storedHash
                ]);

                if ($update->rowCount() === 1) {
                    $success = true;

                    // Refresh the current session ID
                    session_regenerate_id(true);

                    // Rotate the CSRF token
                    $_SESSION['password_csrf'] = bin2hex(
                        random_bytes(32)
                    );

                } else {
                    $errors[] = 'Password could not be updated. Please try again.';
                }
            }

        } catch (PDOException $e) {
            error_log('Password update error: ' . $e->getMessage());
            $errors[] = 'Unable to update password. Please try again.';
        }
    }
}

?>


<?php require __DIR__ . '/../includes/header.php'; ?>

<link rel="stylesheet" href="/assets/css/student-accounts.css">

<main class="accounts-page change-password-page">
    <section class="accounts-container shell"
             aria-labelledby="change-password-title">

        <h1 id="change-password-title">
            Change My Password
        </h1>


<?php if ($success): ?>
    <div class="password-success" role="status">
        Password changed successfully!
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="password-errors" role="alert">
        <p>Please correct the following errors:</p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li>
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>


<p>Update your student account password securely.</p>

<form action="change-password.php"
      method="POST"
      class="password-change-form">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars(
            $_SESSION['password_csrf'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >

    <p>
        <label for="current_password">
            Current Password
        </label>

<div class="password-field">
    <input
        type="password"
        id="current_password"
        name="current_password"
        autocomplete="current-password"
        required
    >

    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="current_password"
        aria-label="Show password"
        aria-pressed="false"
    >
        Show
    </button>
</div>

    </p>

    <p>
        <label for="new_password">
            New Password
        </label>

<div class="password-field">
    <input
        type="password"
        id="new_password"
        name="new_password"
        autocomplete="new-password"
        minlength="10"
        required
    >

    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="new_password"
        aria-label="Show password"
        aria-pressed="false"
    >
        Show
    </button>
</div>

    </p>

    <p>
        <label for="confirm_password">
            Confirm New Password
        </label>

<div class="password-field">
    <input
        type="password"
        id="confirm_password"
        name="confirm_password"
        autocomplete="new-password"
        minlength="10"
        required
    >

    <button
        type="button"
        class="toggle-password"
        data-password-toggle
        aria-controls="confirm_password"
        aria-label="Show password"
        aria-pressed="false"
    >
        Show
    </button>
</div>

    </p>

    <button type="submit" class="accounts-submit">
    Change Password
</button>

</form>

<p class="change-password-back">
    <a href="profile.php" class="change-password-back-link">
        &larr; Back to My Profile
    </a>
</p>


    </section>
</main>

<script src="/assets/js/student-accounts.js" defer></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
