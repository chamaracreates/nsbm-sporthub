<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

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
                    t.reg_open_at, t.reg_end_at, t.start_date, t.is_historical, COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published') AS club_name
             FROM tournaments AS t
             LEFT JOIN sports_clubs AS sc ON sc.club_id = t.club_id
             WHERE t.t_id = :tournament_id AND (sc.status = 'active' OR t.is_historical = 1)
                 AND LOWER(TRIM(t.status)) <> 'draft'
             LIMIT 1"
        );
        $statement->execute(['tournament_id' => $tournamentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(404);
            $pageError = 'Tournament not found.';
        } else {
            $tournament = $row;
            $isOpen = sportsHubRegistrationOpen($tournament, $today);

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
    } elseif ($registration !== null && (!in_array($registration['registration_status'], ['withdrawn', 'rejected'], true) || $rosterMember)) {
        $errors[] = 'A registration record already exists for this tournament.';
    } elseif (!$isOpen) {
        $errors[] = 'Registration is not currently open.';
    } else {
        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "SELECT t.status, t.reg_open_at, t.reg_end_at, t.start_date, t.is_historical
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id AND sc.status = 'active'
                 FOR UPDATE"
            );
            $statement->execute(['tournament_id' => $tournamentId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($current) || !sportsHubRegistrationOpen($current, $today)) {
                $pdo->rollBack();
                $errors[] = 'The registration window is no longer open.';
            } else {
                $statement = $pdo->prepare('SELECT reg_id, registration_status FROM tournament_registration WHERE t_id = ? AND user_id = ? FOR UPDATE');
                $statement->execute([$tournamentId, $userId]);
                $existing = $statement->fetch();
                if ($existing) {
                    if (!in_array($existing['registration_status'], ['withdrawn', 'rejected'], true)) {
                        throw new DomainException('A registration is already active.');
                    }
                    $statement = $pdo->prepare('SELECT 1 FROM team_members tm JOIN teams t ON t.team_id = tm.team_id WHERE t.t_id = ? AND tm.user_id = ?');
                    $statement->execute([$tournamentId, $userId]);
                    if ($statement->fetchColumn()) throw new DomainException('Contact tournament staff about your existing team.');
                    $statement = $pdo->prepare("UPDATE tournament_registration SET registration_status = 'pending', reg_date = ?, reviewed_by = NULL, reviewed_at = NULL WHERE reg_id = ?");
                    $statement->execute([$today, $existing['reg_id']]);
                } else {
                    $statement = $pdo->prepare("INSERT INTO tournament_registration (t_id, user_id, reg_date, registration_status) VALUES (?, ?, ?, 'pending')");
                    $statement->execute([$tournamentId, $userId, $today]);
                }
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

$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';
?>


<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading">
            <h1>Tournament registration.</h1>
            <p class="section-description">Submit and manage your tournament registration.</p>
        </header>

        <div class="tournament-view-navigation">
            <a href="/tournaments/index.php" class="button button-outline">Back to tournaments</a>
            <a href="/student/registrations.php" class="button button-outline">My registrations</a>
        </div>
        <?php if ($pageError !== null): ?>
            <div class="tournaments-notice" role="alert">
                <h2>Registration unavailable</h2>
                <p><?= sportsHubEscape($pageError) ?></p>
            </div>
        <?php elseif ($tournament !== null): ?>
            <article class="tournament-view-panel">
                <div class="tournament-card-top">
                    <span class="tournament-sport"><?= sportsHubEscape($tournament['sport']) ?></span>
                    <span class="tournament-status"><?= sportsHubEscape($tournament['status']) ?></span>
                </div>

                <h2><?= sportsHubEscape($tournament['t_name']) ?></h2>
                <p class="tournament-view-description">
                    Organized by <?= sportsHubEscape($tournament['club_name']) ?>
                </p>

                <dl class="tournament-view-facts">
                    <div>
                        <dt>Registration opens</dt>
                        <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_open_at'])) ?></dd>
                    </div>
                    <div>
                        <dt>Registration closes</dt>
                        <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_end_at'])) ?></dd>
                    </div>
                    <div>
                        <dt>Tournament starts</dt>
                        <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['start_date'])) ?></dd>
                    </div>
                    <div>
                        <dt>Venue</dt>
                        <dd><?= sportsHubEscape($tournament['venue']) ?></dd>
                    </div>
                </dl>

                <?php if ($successMessage !== null): ?>
                    <div class="tournament-form-success" role="status">
                        <?= sportsHubEscape($successMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($withdrawMessage !== null): ?>
                    <div class="<?= $withdrawSucceeded ? 'tournament-form-success' : 'tournament-form-alert' ?>"
                        role="<?= $withdrawSucceeded ? 'status' : 'alert' ?>">
                        <?= sportsHubEscape($withdrawMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="tournament-form-alert" role="alert">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= sportsHubEscape($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($registration !== null): ?>
                    <div class="tournament-form-success">
                        <strong>Your registration status:</strong>
                        <?= sportsHubEscape(ucfirst((string) $registration['registration_status'])) ?>
                    </div>

                    <?php
                    $canWithdraw = in_array(
                        (string) $registration['registration_status'],
                        ['pending', 'approved'],
                        true
                    ) && strtolower(trim((string) $tournament['status'])) === 'upcoming'
                        && (int) ($tournament['is_historical'] ?? 1) === 0
                        && (string) $tournament['start_date'] > $today;
                    ?>

                    <?php if ($canWithdraw && $rosterMember): ?>
                        <p class="tournament-view-muted">
                            You are assigned to a tournament team. Contact tournament staff to withdraw.
                        </p>
                    <?php elseif ($canWithdraw): ?>
                        <form method="post" action="/tournaments/withdraw.php"
                            class="tournament-form-actions"
                            onsubmit="return confirm('Withdraw this registration?');">
                            <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                            <input type="hidden" name="tournament_id" value="<?= (int) $tournamentId ?>">
                            <button type="submit" class="button button-outline">Withdraw Registration</button>
                        </form>
                        <p class="tournament-view-muted">
                            You may register again while registration is open.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($isOpen && !$rosterMember && ($registration === null || in_array($registration['registration_status'], ['withdrawn', 'rejected'], true))): ?>
                    <form method="post"
                        action="/tournaments/register.php?id=<?= (int) $tournamentId ?>"
                        class="tournament-form-actions">
                        <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                        <button type="submit" class="button button-accent"> <?= $registration === null ? 'Submit registration' : 'Register again' ?></button>
                    </form>
                <?php elseif (!$isOpen): ?>
                    <p class="tournament-view-muted">Registration is not currently open.</p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>