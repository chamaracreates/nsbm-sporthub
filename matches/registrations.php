<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$userId = require_login();
$role = current_user_role();
if (!in_array($role, ['admin','mic','coach'], true)) { http_response_code(403); exit('Access denied.'); }
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Invalid match.'); }
$_SESSION['match_review_csrf'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['match_review_csrf'];
$match = $pdo = null;
$registrations = [];
$error = '';
try {
    $pdo = db();
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post) {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($token, $_POST['csrf_token'])) { http_response_code(403); exit('Invalid form session.'); }
        $pdo->beginTransaction();
    }
    $match = sportsHubRegistrationMatch($pdo, $id, $post);
    if (!$match || !sportsHubCanManageClub($pdo, isset($match['club_id']) ? (int)$match['club_id'] : null, $userId, $role)) {
        if ($post) $pdo->rollBack();
        http_response_code(403);
        exit('Fixture not found or access denied.');
    }
    if ($post) {
        if (!array_key_exists('registration_open', $match)) {
            throw new DomainException('Complete the database schema upgrade before managing match registrations.');
        }
        $action = $_POST['action'] ?? '';
        $eligible = sportsHubMatchRegistrationOpen(array_replace($match, ['registration_open'=>1]));
        if ($action === 'open' || $action === 'close') {
            if ($action === 'open' && !$eligible) throw new DomainException('Only future eligible fixtures can accept registrations.');
            $stmt = $pdo->prepare('UPDATE matches SET registration_open = ? WHERE m_id = ?');
            $stmt->execute([$action === 'open' ? 1 : 0, $id]);
        } elseif (in_array($action, ['approve','reject','delete'], true)) {
            $registrationId = filter_var($_POST['registration_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $stmt = $pdo->prepare('SELECT * FROM match_registration WHERE match_reg_id = ? AND m_id = ? FOR UPDATE');
            $stmt->execute([$registrationId,$id]);
            $registration = $stmt->fetch();
            if (!$registration) throw new DomainException('Registration not found.');
            if ($action === 'delete') {
                if ($role !== 'admin' || !in_array($registration['registration_status'], ['pending','rejected','withdrawn'], true)) throw new DomainException('Only administrators may delete inactive or pending requests.');
                $studentId = (int)$registration['user_id'];
                $stmt = $pdo->prepare('SELECT 1 FROM team_members WHERE user_id = ? AND team_id IN (?, ?) LIMIT 1 FOR UPDATE');
                $stmt->execute([$studentId, $match['home_team_id'], $match['away_team_id']]);
                if ((int)$match['home_u_id'] === $studentId || (int)$match['away_u_id'] === $studentId || $stmt->fetchColumn()) {
                    throw new DomainException('Remove the student from the assigned fixture or roster before deleting this request.');
                }
                $stmt = $pdo->prepare('DELETE FROM match_registration WHERE match_reg_id = ?');
                $stmt->execute([$registrationId]);
            } else {
                if (!$eligible || $registration['registration_status'] !== 'pending') throw new DomainException('Only pending requests for eligible future fixtures can be reviewed.');
                $stmt = $pdo->prepare('UPDATE match_registration SET registration_status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE match_reg_id = ?');
                $stmt->execute([$action === 'approve' ? 'approved' : 'rejected',$userId,$registrationId]);
            }
        } else { throw new DomainException('Invalid action.'); }
        $pdo->commit();
        header('Location: /matches/registrations.php?id=' . $id . '&saved=1', true, 303);
        exit;
    }
    $stmt = $pdo->prepare('SELECT mr.*, u.name, u.email, sp.student_number, sp.study_program FROM match_registration mr JOIN users u ON u.user_id = mr.user_id LEFT JOIN student_profiles sp ON sp.user_id = u.user_id WHERE mr.m_id = ? ORDER BY mr.requested_at DESC');
    $stmt->execute([$id]);
    $registrations = $stmt->fetchAll();
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!$e instanceof DomainException) error_log('Match registration review: ' . $e->getMessage());
    $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to load or update registrations.';
}
$schemaReady = $match && array_key_exists('registration_open', $match);
$canOpenRegistration = $schemaReady && sportsHubMatchRegistrationOpen(array_replace($match, ['registration_open' => 1]));
$pageTitle = 'Match registration management';
$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';
?>
<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading"><h1>Match registrations.</h1><p class="section-description">Manage participation requests and registration availability.</p></header>
        <div class="tournament-view-navigation"><a class="button button-outline" href="/matches/manage.php">Back to fixtures</a></div>
        <?php if ($error !== ''): ?><p class="tournament-form-alert" role="alert"><?= sportsHubEscape($error) ?></p><?php elseif (isset($_GET['saved'])): ?><p class="tournament-form-success" role="status">Changes saved.</p><?php endif; ?>
        <?php if ($match): ?>
            <article class="tournament-view-panel">
                <h2><?= sportsHubEscape($match['title']) ?></h2>
                <p class="tournament-view-muted">Registration: <?= sportsHubMatchRegistrationOpen($match) ? 'Open' : 'Closed' ?></p>
                <?php if (!$schemaReady): ?>
                    <p class="tournament-form-alert" role="alert">Complete the database schema upgrade to manage registrations.</p>
                <?php elseif ((int) $match['registration_open'] === 1 || $canOpenRegistration): ?>
                    <form method="post" class="tournament-form-actions">
                        <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>">
                        <?php if ((int) $match['registration_open'] === 1): ?>
                            <button class="button button-outline" name="action" value="close">Close registration</button>
                        <?php else: ?>
                            <button class="button button-accent" name="action" value="open">Open registration</button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <p class="tournament-view-muted">This fixture is not eligible for new registrations.</p>
                <?php endif; ?>
            </article>
            <?php if (!$registrations): ?><div class="tournaments-notice"><h2>No registration requests</h2><p>Student requests will appear here.</p></div><?php endif; ?>
            <div class="tournaments-grid">
                <?php foreach ($registrations as $r): ?>
                    <article class="tournament-card"><div class="tournament-card-top"><span class="tournament-status"><?= sportsHubEscape(ucfirst($r['registration_status'])) ?></span></div><h2><?= sportsHubEscape($r['name']) ?></h2><p><?= sportsHubEscape($r['email']) ?></p><p><?= sportsHubEscape($r['student_number'] ?? 'Student ID not provided') ?> · <?= sportsHubEscape($r['study_program'] ?? 'Programme not provided') ?></p><p>Applied: <?= sportsHubEscape($r['requested_at']) ?></p>
                        <form method="post" class="tournament-management-actions"><input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>"><input type="hidden" name="registration_id" value="<?= (int)$r['match_reg_id'] ?>">
                            <?php if ($r['registration_status'] === 'pending' && $canOpenRegistration): ?><button class="button button-accent" name="action" value="approve">Approve</button><button class="button button-outline" name="action" value="reject">Reject</button><?php endif; ?>
                            <?php if ($schemaReady && $role === 'admin' && in_array($r['registration_status'], ['pending','rejected','withdrawn'], true)): ?><button class="button button-danger-outline" name="action" value="delete" onclick="return confirm('Permanently delete this request?');">Delete request</button><?php endif; ?>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
