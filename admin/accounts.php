<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_admin();
$_SESSION['accounts_csrf'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['accounts_csrf'];
$error = '';
$accounts = [];
$edit = null;
$filter = is_string($_GET['role'] ?? null) ? $_GET['role'] : '';
if (!in_array($filter, ['student','mic','coach'], true)) $filter = '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($token, $_POST['csrf_token'])) { http_response_code(403); exit('Invalid form session.'); }
        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $name = sportsHubInput('name');
        $email = strtolower(sportsHubInput('email'));
        if (!$id || $name === '' || sportsHubLength($name) > 100 || strlen($email) > 255 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid name and email address.');
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ? AND role IN ('student','mic','coach')");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new DomainException('Account not found or protected.');
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE user_id = ? AND role IN ('student','mic','coach')");
        $stmt->execute([$name,$email,$id]);
        header('Location: /admin/accounts.php?saved=1', true, 303);
        exit;
    }
    if (isset($_GET['edit'])) {
        $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $stmt = $pdo->prepare("SELECT user_id,name,email,role FROM users WHERE user_id = ? AND role IN ('student','mic','coach')");
        $stmt->execute([$id]);
        $edit = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    if (!$e instanceof DomainException) error_log('Account administration: ' . $e->getMessage());
    $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to save account. The email may already be in use.';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($id)) {
        $edit = ['user_id' => $id, 'name' => $name ?? '', 'email' => $email ?? ''];
    }
}
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT user_id,name,email,role FROM users WHERE role IN ('student','mic','coach')" . ($filter === '' ? '' : ' AND role = ?') . ($search === '' ? '' : ' AND (name LIKE ? OR email LIKE ?)') . ' ORDER BY name,user_id LIMIT 200');
    $params = $filter === '' ? [] : [$filter];
    if ($search !== '') { $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();
} catch (Throwable $e) { error_log('Account listing: ' . $e->getMessage()); $error = 'Unable to load accounts.'; }
$pageTitle = 'Student and staff accounts';
$pageStylesheets = ['/assets/css/tournaments.css','/assets/css/clubs.css'];
require __DIR__ . '/../includes/header.php';
?>
<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading"><h1>Student and staff accounts.</h1><p class="section-description">Manage student and staff account information.</p></header>
        <div class="tournament-view-navigation"><a class="button button-outline" href="/admin/manage.php">Back to management</a><a class="button button-accent" href="/clubs/admin/create-staff.php">Create staff account</a></div>
        <?php if ($error !== ''): ?><p class="tournament-form-alert" role="alert"><?= sportsHubEscape($error) ?></p><?php elseif (isset($_GET['saved'])): ?><p class="tournament-form-success" role="status">Account updated.</p><?php endif; ?>
        <?php if (isset($_SESSION['admin_delete_feedback'])): ?><p class="tournament-form-success" role="status"><?= sportsHubEscape($_SESSION['admin_delete_feedback']) ?></p><?php unset($_SESSION['admin_delete_feedback']); endif; ?>
        <?php if ($edit): ?>
            <form method="post" class="tournament-form">
                <h2>Edit account</h2>
                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>"><input type="hidden" name="user_id" value="<?= (int)$edit['user_id'] ?>">
                <div class="tournament-form-grid"><div class="tournament-field"><label for="account-name">Name</label><input id="account-name" name="name" maxlength="100" required value="<?= sportsHubEscape($edit['name']) ?>"></div><div class="tournament-field"><label for="account-email">Email</label><input id="account-email" name="email" type="email" maxlength="255" required value="<?= sportsHubEscape($edit['email']) ?>"></div></div>
                <div class="tournament-form-actions"><a href="/admin/accounts.php" class="button button-outline">Cancel</a><button class="button button-accent">Save changes</button></div>
            </form>
        <?php endif; ?>
        <form method="get" class="tournament-form"><div class="tournament-form-grid"><div class="tournament-field"><label for="account-search">Search by name or email</label><input id="account-search" name="q" maxlength="255" value="<?= sportsHubEscape($search) ?>"></div><div class="tournament-field"><label for="account-role">Role</label><select id="account-role" name="role"><option value="">All roles</option><?php foreach (['student','mic','coach'] as $role): ?><option value="<?= $role ?>" <?= $filter === $role ? 'selected' : '' ?>><?= strtoupper($role) ?></option><?php endforeach; ?></select></div></div><div class="tournament-form-actions"><button class="button button-accent">Filter</button><a href="/admin/accounts.php" class="button button-outline">Clear</a></div></form>
        <div class="club-admin-table-wrap"><table class="club-admin-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($accounts as $account): ?><tr><td><?= sportsHubEscape($account['name']) ?></td><td><?= sportsHubEscape($account['email']) ?></td><td><?= sportsHubEscape(strtoupper($account['role'])) ?></td><td><div class="club-admin-actions"><a href="/admin/accounts.php?edit=<?= (int)$account['user_id'] ?>" class="button button-outline">Edit</a><a href="/admin/delete.php?type=account&amp;id=<?= (int)$account['user_id'] ?>" class="button button-danger-outline">Delete</a></div></td></tr><?php endforeach; ?>
            <?php if (!$accounts): ?><tr><td colspan="4">No accounts match your filters.</td></tr><?php endif; ?>
        </tbody></table></div>
        <p class="tournament-view-muted">Showing <?= count($accounts) ?> of up to 200 matching accounts. Administrator accounts are protected.</p>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
