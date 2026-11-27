<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$userId = require_login();
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}
$clubId = filter_var($_GET['id'] ?? $_POST['club_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clubId) {
    http_response_code(400);
    exit('Invalid club.');
}
$_SESSION['club_join_csrf'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['club_join_csrf'];
$error = '';
$experience = '';
$reason = '';
$club = $profile = $membership = null;
$pdo = null;
try {
    $pdo = db();
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post) {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($token, $_POST['csrf_token'])) {
            http_response_code(403);
            exit('Invalid form session.');
        }
        $pdo->beginTransaction();
    }
    $stmt = $pdo->prepare("SELECT club_id, club_name, status FROM sports_clubs WHERE club_id = ?" . ($post ? ' FOR UPDATE' : ''));
    $stmt->execute([$clubId]);
    $club = $stmt->fetch();
    if (!$club) {
        http_response_code(404);
        throw new DomainException('Club not found.');
    }
    $stmt = $pdo->prepare('SELECT u.name, u.email, sp.student_number, sp.study_program, sp.academic_year, sp.phone
        FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.user_id WHERE u.user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT * FROM club_membership WHERE club_id = ? AND user_id = ?' . ($post ? ' FOR UPDATE' : ''));
    $stmt->execute([$clubId, $userId]);
    $membership = $stmt->fetch() ?: null;
    $experience = $membership['experience_level'] ?? '';
    $reason = $membership['application_reason'] ?? '';
    if ($post) {
        $action = $_POST['action'] ?? '';
        if ($action === 'withdraw' || $action === 'leave') {
            $expected = $action === 'withdraw' ? 'pending' : 'approved';
            if (($membership['status'] ?? '') !== $expected || !sportsHubMembershipCanDelete($pdo, $clubId, $userId)) {
                throw new DomainException('This membership has linked activity or is no longer eligible for removal. Contact club staff.');
            }
            $stmt = $pdo->prepare('DELETE FROM club_membership WHERE mem_id = ? AND user_id = ? AND status = ?');
            $stmt->execute([$membership['mem_id'], $userId, $expected]);
        } elseif ($action === 'apply') {
            $experience = sportsHubInput('experience');
            $reason = sportsHubInput('reason');
            if ($club['status'] !== 'active' || ($membership['status'] ?? '') === 'approved') {
                throw new DomainException('Applications are not available for this membership.');
            }
            if (!in_array($experience, ['Beginner', 'Intermediate', 'Advanced'], true) || $reason === '' || sportsHubLength($reason) > 500) {
                throw new DomainException('Select your experience and enter a reason of up to 500 characters.');
            }
            if ($membership) {
                $stmt = $pdo->prepare("UPDATE club_membership SET experience_level = ?, application_reason = ?, status = 'pending', joined_date = NULL WHERE mem_id = ? AND user_id = ?");
                $stmt->execute([$experience, $reason, $membership['mem_id'], $userId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO club_membership (club_id, user_id, status, experience_level, application_reason) VALUES (?, ?, 'pending', ?, ?)");
                $stmt->execute([$clubId, $userId, $experience, $reason]);
            }
        } else {
            throw new DomainException('Invalid membership action.');
        }
        $pdo->commit();
        header('Location: /clubs/join.php?id=' . $clubId . '&saved=1', true, 303);
        exit;
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!$e instanceof DomainException) error_log('Club membership: ' . $e->getMessage());
    $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to process membership. Please try again.';
}
$pageTitle = 'Club membership';
$pageStylesheets = ['/assets/css/tournaments.css?v=20261127-membership3'];
require __DIR__ . '/../includes/header.php';
?>
<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading">
            <h1>Club membership.</h1>
            <p class="section-description">Apply, update or manage your club membership.</p>
        </header>
        <div class="tournament-view-navigation"><a class="button button-accent" href="/clubs/my-memberships.php">My memberships</a><a class="button button-outline" href="/clubs/view.php?id=<?= (int) $clubId ?>">View club</a></div>
        <?php if ($error !== ''): ?><p class="tournament-form-alert" role="alert"><?= sportsHubEscape($error) ?></p><?php elseif (isset($_GET['saved'])): ?><p class="tournament-form-success" role="status">Membership updated.</p><?php endif; ?>
        <?php if ($club && $profile): ?>
            <div class="membership-workflow">
                <article class="tournament-form">
                    <div class="membership-card-heading">
                        <h2><?= sportsHubEscape($club['club_name']) ?></h2>
                        <?php if ($membership): ?>
                            <span class="tournament-status registration-status-<?= sportsHubEscape($membership['status']) ?>"><?= sportsHubEscape(ucfirst($membership['status'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <dl class="tournament-form-grid membership-profile-facts">
                        <?php foreach (['name'=>'Name','student_number'=>'Student ID','email'=>'Email','study_program'=>'Degree programme','academic_year'=>'Academic year','phone'=>'Contact number'] as $key=>$label): ?>
                            <div<?= in_array($key, ['email', 'study_program'], true) ? ' class="tournament-field-full"' : '' ?>><dt><?= sportsHubEscape($label) ?></dt><dd><?= sportsHubEscape($profile[$key] ?: 'Not provided') ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <div class="membership-card-actions">
                        <a class="button button-accent" href="/student/edit-profile.php">Edit profile</a>
                    </div>
                </article>
                <?php if ($club['status'] === 'active' && ($membership['status'] ?? '') !== 'approved'): ?>
                    <form method="post" class="tournament-form">
                        <div class="membership-card-heading"><h2>Membership application</h2></div>
                        <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>">
                        <input type="hidden" name="action" value="apply">
                        <div class="tournament-form-grid">
                            <div class="tournament-field tournament-field-full"><label for="experience">Experience level</label><select id="experience" name="experience" required><option value="">Select experience</option><?php foreach (['Beginner','Intermediate','Advanced'] as $level): ?><option <?= $experience === $level ? 'selected' : '' ?>><?= sportsHubEscape($level) ?></option><?php endforeach; ?></select></div>
                            <div class="tournament-field tournament-field-full"><label for="reason">Why would you like to join?</label><textarea id="reason" name="reason" maxlength="500" required><?= sportsHubEscape($reason) ?></textarea><small>Maximum 500 characters.</small></div>
                        </div>
                        <div class="membership-card-actions"><button class="button button-accent" type="submit"><?= ($membership['status'] ?? '') === 'pending' ? 'Update application' : 'Submit application' ?></button></div>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (in_array($membership['status'] ?? '', ['pending','approved'], true)): ?>
                <form method="post" class="tournament-form-actions" onsubmit="return confirm('Permanently remove this membership or request?');">
                    <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>">
                    <button class="button button-danger-outline" name="action" value="<?= $membership['status'] === 'pending' ? 'withdraw' : 'leave' ?>"><?= $membership['status'] === 'pending' ? 'Withdraw application' : 'Leave club' ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
