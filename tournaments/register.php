<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

function registerEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function registerWindowOpen(array $tournament, string $today): bool
{
    return strtolower(trim((string) $tournament['status'])) === 'upcoming'
        && !empty($tournament['reg_open_at'])
        && !empty($tournament['reg_end_at'])
        && (string) $tournament['reg_open_at'] <= $today
        && (string) $tournament['reg_end_at'] >= $today
        && (string) $tournament['start_date'] > $today;
}

$tournamentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))
    ->format('Y-m-d');

if (
    !isset($_SESSION['tournament_register_csrf'])
    || !is_string($_SESSION['tournament_register_csrf'])
) {
    $_SESSION['tournament_register_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['tournament_register_csrf'];

$tournament = null;
$registration = null;
$rosterMember = false;
$isOpen = false;
$pageError = null;
$errors = [];
$successMessage = null;
$withdrawMessage = null;
$withdrawSucceeded = false;
$pdo = null;

if (is_int($tournamentId)) {
    if (($_SESSION['tournament_register_success'] ?? null) === $tournamentId) {
        $successMessage = 'Registration submitted. Your application is awaiting review.';
        unset($_SESSION['tournament_register_success']);
    }

    $withdrawFlash = $_SESSION['tournament_withdraw_flash'] ?? null;
    if (
        is_array($withdrawFlash)
        && ($withdrawFlash['tournament_id'] ?? null) === $tournamentId
    ) {
        $withdrawMessage = is_string($withdrawFlash['message'] ?? null)
            ? $withdrawFlash['message']
            : '';
        $withdrawSucceeded = ($withdrawFlash['success'] ?? false) === true;
        unset($_SESSION['tournament_withdraw_flash']);
    }
}

if (!is_int($tournamentId)) {
    http_response_code(400);
    $pageError = 'Invalid tournament ID.';
} else {
    try {
        $pdo = db();

        $statement = $pdo->prepare(
            "SELECT t.t_id, t.t_name, t.sport, t.venue, t.status,
                    t.reg_open_at, t.reg_end_at, t.start_date, sc.club_name
             FROM tournaments AS t
             INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
             WHERE t.t_id = :tournament_id AND sc.status = 'active'
             LIMIT 1"
        );
        $statement->execute(['tournament_id' => $tournamentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(404);
            $pageError = 'Tournament not found.';
        } else {
            $tournament = $row;
            $isOpen = registerWindowOpen($tournament, $today);

            $statement = $pdo->prepare(
                "SELECT reg_id, registration_status, reg_date
                 FROM tournament_registration
                 WHERE t_id = :tournament_id AND user_id = :user_id
                 LIMIT 1"
            );
            $statement->execute([
                'tournament_id' => $tournamentId,
                'user_id' => $userId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $registration = is_array($row) ? $row : null;

            if ($registration !== null) {
                $statement = $pdo->prepare(
                    "SELECT 1
                     FROM team_members AS member
                     INNER JOIN teams AS team ON team.team_id = member.team_id
                     WHERE member.user_id = :user_id AND team.t_id = :tournament_id
                     LIMIT 1"
                );
                $statement->execute([
                    'user_id' => $userId,
                    'tournament_id' => $tournamentId,
                ]);
                $rosterMember = $statement->fetchColumn() !== false;
            }
        }
    } catch (Throwable $error) {
        error_log('Registration page error: ' . $error->getMessage());
        http_response_code(500);
        $pageError = 'Unable to load tournament registration.';
    }
}

if (
    $method === 'POST' && $pageError === null
    && $tournament !== null && $pdo instanceof PDO
) {
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        $errors[] = 'Invalid form session. Refresh the page and try again.';
    } elseif ($registration !== null) {
        $errors[] = 'A registration record already exists for this tournament.';
    } elseif (!$isOpen) {
        $errors[] = 'Registration is not currently open.';
    } else {
        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "SELECT t.status, t.reg_open_at, t.reg_end_at, t.start_date
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id AND sc.status = 'active'
                 FOR UPDATE"
            );
            $statement->execute(['tournament_id' => $tournamentId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($current) || !registerWindowOpen($current, $today)) {
                $pdo->rollBack();
                $errors[] = 'The registration window is no longer open.';
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO tournament_registration
                        (t_id, user_id, reg_date, registration_status)
                     VALUES (:tournament_id, :user_id, :reg_date, 'pending')"
                );
                $statement->execute([
                    'tournament_id' => $tournamentId,
                    'user_id' => $userId,
                    'reg_date' => $today,
                ]);
                $pdo->commit();

                $_SESSION['tournament_register_success'] = $tournamentId;
                header('Location: /tournaments/register.php?id=' . $tournamentId, true, 303);
                exit;
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Registration submission error: ' . $error->getMessage());
            $errors[] = 'Unable to submit registration. It may already exist; refresh the page.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">
    <section class="tournaments-container section-container">
        <div class="tournament-view-navigation">
            <a href="/tournaments/index.php" class="button button-outline">All Tournaments</a>
        </div>

        <header class="tournaments-heading">
            <p class="section-label">NSBM SPORTSHUB / REGISTRATION</p>
            <h1>Register for a tournament.</h1>
            <p class="section-description">Submit and manage your tournament registration.</p>
        </header>

        <?php if ($pageError !== null): ?>
            <div class="tournaments-notice" role="alert">
                <h2>Registration unavailable</h2>
                <p><?= registerEscape($pageError) ?></p>
            </div>
        <?php elseif ($tournament !== null): ?>
            <article class="tournament-view-panel">
                <div class="tournament-card-top">
                    <span class="tournament-sport"><?= registerEscape($tournament['sport']) ?></span>
                    <span class="tournament-status"><?= registerEscape($tournament['status']) ?></span>
                </div>

                <h2><?= registerEscape($tournament['t_name']) ?></h2>
                <p class="tournament-view-description">
                    Organized by <?= registerEscape($tournament['club_name']) ?>
                </p>

                <dl class="tournament-view-facts">
                    <div>
                        <dt>Registration opens</dt>
                        <dd><?= registerEscape($tournament['reg_open_at']) ?></dd>
                    </div>
                    <div>
                        <dt>Registration closes</dt>
                        <dd><?= registerEscape($tournament['reg_end_at']) ?></dd>
                    </div>
                    <div>
                        <dt>Tournament starts</dt>
                        <dd><?= registerEscape($tournament['start_date']) ?></dd>
                    </div>
                    <div>
                        <dt>Venue</dt>
                        <dd><?= registerEscape($tournament['venue']) ?></dd>
                    </div>
                </dl>

                <?php if ($successMessage !== null): ?>
                    <div class="tournament-form-success" role="status">
                        <?= registerEscape($successMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($withdrawMessage !== null): ?>
                    <div class="<?= $withdrawSucceeded ? 'tournament-form-success' : 'tournament-form-alert' ?>"
                        role="<?= $withdrawSucceeded ? 'status' : 'alert' ?>">
                        <?= registerEscape($withdrawMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="tournament-form-alert" role="alert">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= registerEscape($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($registration !== null): ?>
                    <div class="tournament-form-success">
                        <strong>Your registration status:</strong>
                        <?= registerEscape(ucfirst((string) $registration['registration_status'])) ?>
                    </div>

                    <?php
                    $canWithdraw = in_array(
                        (string) $registration['registration_status'],
                        ['pending', 'approved'],
                        true
                    ) && strtolower(trim((string) $tournament['status'])) === 'upcoming'
                        && (string) $tournament['start_date'] > $today;
                    ?>

                    <?php if ($canWithdraw && $rosterMember): ?>
                        <p class="tournament-view-muted">
                            You are assigned to a tournament team. Contact tournament staff to withdraw.
                        </p>
                    <?php elseif ($canWithdraw): ?>
                        <form method="post" action="/tournaments/withdraw.php"
                            class="tournament-form-actions"
                            onsubmit="return confirm('Withdraw this registration? You will not be able to reapply automatically.');">
                            <input type="hidden" name="csrf_token" value="<?= registerEscape($csrfToken) ?>">
                            <input type="hidden" name="tournament_id" value="<?= (int) $tournamentId ?>">
                            <button type="submit" class="button button-outline">Withdraw Registration</button>
                        </form>
                        <p class="tournament-view-muted">
                            Withdrawal is final. Contact tournament staff if you need to register again.
                        </p>
                    <?php endif; ?>
                <?php elseif ($isOpen): ?>
                    <form method="post"
                        action="/tournaments/register.php?id=<?= (int) $tournamentId ?>"
                        class="tournament-form-actions">
                        <input type="hidden" name="csrf_token" value="<?= registerEscape($csrfToken) ?>">
                        <button type="submit" class="button button-accent">Submit Registration</button>
                    </form>
                <?php else: ?>
                    <p class="tournament-view-muted">Registration is not currently open.</p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>