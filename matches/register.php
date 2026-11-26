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
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Invalid match.');
}
$_SESSION['match_registration_csrf'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['match_registration_csrf'];
$match = $registration = $pdo = null;
$error = '';
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
    $match = sportsHubRegistrationMatch($pdo, $id, $post);
    if (!$match) {
        http_response_code(404);
        throw new DomainException('Match not found.');
    }
    $stmt = $pdo->prepare('SELECT * FROM match_registration WHERE m_id = ? AND user_id = ?' . ($post ? ' FOR UPDATE' : ''));
    $stmt->execute([$id, $userId]);
    $registration = $stmt->fetch() ?: null;
    if ($post) {
        $action = $_POST['action'] ?? '';
        $status = $registration['registration_status'] ?? '';
        if ($action === 'register') {
            if (!sportsHubMatchRegistrationOpen($match) || in_array($status, ['pending','approved'], true)) throw new DomainException('Registration is closed or you already have an active request.');
            if ($registration) {
                $stmt = $pdo->prepare("UPDATE match_registration SET registration_status = 'pending', requested_at = CURRENT_TIMESTAMP, reviewed_by = NULL, reviewed_at = NULL WHERE match_reg_id = ?");
                $stmt->execute([$registration['match_reg_id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO match_registration (m_id,user_id,registration_status) VALUES (?,?,'pending')");
                $stmt->execute([$id,$userId]);
            }
        } elseif ($action === 'withdraw') {
            if (!in_array($status, ['pending','approved'], true) || !in_array($match['match_status'], ['scheduled','upcoming'], true) || $match['date'] <= date('Y-m-d')) throw new DomainException('Withdrawal is unavailable for this match.');
            $stmt = $pdo->prepare('SELECT 1 FROM team_members WHERE user_id = ? AND team_id IN (?, ?)');
            $stmt->execute([$userId,$match['home_team_id'],$match['away_team_id']]);
            if ((int)$match['home_u_id'] === $userId || (int)$match['away_u_id'] === $userId || $stmt->fetchColumn()) throw new DomainException('Contact staff before withdrawing from an assigned fixture.');
            $stmt = $pdo->prepare("UPDATE match_registration SET registration_status = 'withdrawn' WHERE match_reg_id = ?");
            $stmt->execute([$registration['match_reg_id']]);
        } else {
            throw new DomainException('Invalid registration action.');
        }
        $pdo->commit();
        header('Location: /matches/register.php?id=' . $id . '&saved=1', true, 303);
        exit;
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!$e instanceof DomainException) error_log('Match registration: ' . $e->getMessage());
    $error = $e instanceof DomainException ? $e->getMessage() : 'Unable to process registration. Please try again.';
}
$pageTitle = 'Match registration';
$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';
?>
<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading"><h1>Match registration.</h1><p class="section-description">Request participation in an eligible fixture.</p></header>
        <div class="tournament-view-navigation"><a class="button button-outline" href="/matches/view.php?id=<?= (int)$id ?>">View fixture</a><a class="button button-outline" href="/student/registrations.php">My registrations</a></div>
        <?php if ($error !== ''): ?><p role="alert" class="tournament-form-alert"><?= sportsHubEscape($error) ?></p><?php elseif (isset($_GET['saved'])): ?><p role="status" class="tournament-form-success">Registration updated.</p><?php endif; ?>
        <?php if ($match): ?>
            <article class="tournament-view-panel">
                <h2><?= sportsHubEscape($match['title']) ?></h2>
                <dl class="tournament-view-facts"><div><dt>Date</dt><dd><?= sportsHubEscape(sportsHubDisplayDate($match['date'])) ?></dd></div><div><dt>Venue</dt><dd><?= sportsHubEscape($match['venue']) ?></dd></div><div><dt>Registration</dt><dd><?= sportsHubEscape(ucfirst($registration['registration_status'] ?? 'Not registered')) ?></dd></div></dl>
                <p>Approval confirms your participation request. Team or player selection remains a staff decision.</p>
                <?php if (sportsHubMatchRegistrationOpen($match) && !in_array($registration['registration_status'] ?? '', ['pending','approved'], true)): ?>
                    <form method="post" class="tournament-form-actions"><input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>"><button class="button button-accent" name="action" value="register"><?= $registration ? 'Register again' : 'Submit registration' ?></button></form>
                <?php elseif (in_array($registration['registration_status'] ?? '', ['pending','approved'], true) && $match['date'] > date('Y-m-d') && in_array($match['match_status'], ['scheduled','upcoming'], true)): ?>
                    <form method="post" class="tournament-form-actions" onsubmit="return confirm('Withdraw this registration?');"><input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>"><button class="button button-outline" name="action" value="withdraw">Withdraw registration</button></form>
                <?php else: ?><p class="tournament-view-muted">Registration is not currently open.</p><?php endif; ?>
            </article>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
