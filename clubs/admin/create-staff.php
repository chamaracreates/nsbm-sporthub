<?php

// MIC and coach accounts use the existing users table; no migration required.
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/db.php';

require_admin();


if (empty($_SESSION['staff_account_csrf']) || !is_string($_SESSION['staff_account_csrf'])) {
    $_SESSION['staff_account_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$form = ['name' => '', 'email' => '', 'role' => 'coach'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['staff_account_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    $name = $_POST['name'] ?? null;
    $email = $_POST['email'] ?? null;
    $role = $_POST['role'] ?? null;
    $password = $_POST['password'] ?? null;
    $confirm = $_POST['confirm_password'] ?? null;

    if (!is_string($name) || !is_string($email) || !is_string($role)
        || !is_string($password) || !is_string($confirm)) {
        $errors[] = 'Please complete all fields using valid text.';
    } else {
        $form['name'] = trim($name);
        $form['email'] = strtolower(trim($email));
        $form['role'] = $role;

        if ($form['name'] === '' || strlen($form['name']) > 100) {
            $errors[] = 'The full name must be between 1 and 100 characters.';
        }
        if (strlen($form['email']) > 255 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!in_array($role, ['mic', 'coach'], true)) {
            $errors[] = 'Select MIC or Coach as the account role.';
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            $errors[] = 'The initial password must contain 12 to 72 bytes.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The passwords do not match.';
        }
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $existing = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
            $existing->execute(['email' => $form['email']]);
            if ($existing->fetchColumn() !== false) {
                $errors[] = 'An account already uses this email address.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($hash)) {
                    throw new RuntimeException('Unable to hash password.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)'
                );
                $stmt->execute([
                    'name' => $form['name'],
                    'email' => $form['email'],
                    'password' => $hash,
                    'role' => $form['role'],
                ]);
                $_SESSION['staff_account_csrf'] = bin2hex(random_bytes(32));
                header('Location: /clubs/admin/staff.php?result=account_created&user_id=' . (int)$pdo->lastInsertId(), true, 303);
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Staff account creation failed: ' . $exception->getMessage());
            if ($exception->getCode() === '23000') {
                $errors[] = 'An account already uses this email address.';
            } else {
                $errors[] = 'The account could not be created. Please try again.';
            }
        } catch (Throwable $exception) {
            error_log('Staff account creation failed: ' . $exception->getMessage());
            $errors[] = 'The account could not be created. Please try again.';
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$pageStylesheets = ['/assets/css/clubs.css'];
require __DIR__ . '/../../includes/header.php';
?>
<main class="clubs-page sports-hub-workflow staff-create-v15">
    <section class="shell club-admin-page">
        <div class="v15-page-heading">
            <div>
                <h1>Create staff account</h1>
                <p>Set up a verified MIC or coach account before assigning the person to a sports club.</p>
            </div>
            <a class="v15-action v15-action-secondary" href="/clubs/admin/staff.php">Staff management</a>
        </div>

        <?php if ($errors !== []): ?>
            <div class="v15-form-message" role="alert">
                <strong>Check the following details</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= sportsHubEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="v15-create-account-panel" aria-labelledby="create-account-heading">
            <h2 id="create-account-heading">Account details</h2>
            <p>Only create accounts for confirmed staff members. The initial password must be shared privately with the account holder.</p>
            <form method="post" action="/clubs/admin/create-staff.php" class="v15-staff-account-form">
                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($_SESSION['staff_account_csrf']) ?>">
                <div class="v15-field">
                    <label for="staff-account-name">Full name</label>
                    <input id="staff-account-name" type="text" name="name" maxlength="100" required autocomplete="name" value="<?= sportsHubEscape($form['name']) ?>">
                </div>
                <div class="v15-field">
                    <label for="staff-account-email">Email address</label>
                    <input id="staff-account-email" type="email" name="email" maxlength="255" required autocomplete="off" value="<?= sportsHubEscape($form['email']) ?>">
                </div>
                <div class="v15-field">
                    <label for="staff-account-role">Staff role</label>
                    <select id="staff-account-role" name="role" required>
                        <option value="coach" <?= $form['role'] === 'coach' ? 'selected' : '' ?>>Coach</option>
                        <option value="mic" <?= $form['role'] === 'mic' ? 'selected' : '' ?>>Member in Charge (MIC)</option>
                    </select>
                </div>
                <div class="v15-field">
                    <label for="staff-account-password">Initial password</label>
                    <input id="staff-account-password" type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password">
                </div>
                <div class="v15-field v15-wide">
                    <label for="staff-account-confirm">Confirm password</label>
                    <input id="staff-account-confirm" type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password">
                </div>
                <div class="v15-create-account-actions v15-wide">
                    <a class="v15-action v15-action-secondary" href="/clubs/admin/staff.php">Cancel</a>
                    <button class="v15-action v15-action-primary" type="submit">Create staff account</button>
                </div>
            </form>
        </section>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
