<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

function tournamentReviewEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (
    !isset($_SESSION['tournament_review_csrf']) ||
    !is_string($_SESSION['tournament_review_csrf'])
) {
    $_SESSION['tournament_review_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['tournament_review_csrf'];

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

$scopeSql = '';
$scopeParams = [];
if ($userRole !== 'admin') {
    $scopeSql = "
        AND EXISTS (
            SELECT 1
            FROM sport_staff AS ss
            WHERE ss.club_id = t.club_id
              AND ss.user_id = :staff_user_id
              AND ss.staff_role = :staff_role
              AND ss.status = 'active'
        )
    ";
    $scopeParams = [
        'staff_user_id' => $userId,
        'staff_role' => $userRole,
    ];
}

$errors = [];
$registrations = [];
$loadError = false;
$successMessage = $_SESSION['tournament_review_success'] ?? null;
unset($_SESSION['tournament_review_success']);
$pdo = null;

if ($method === 'POST') {
    $postedId = $_POST['registration_id'] ?? null;
    $registrationId = (is_string($postedId) || is_int($postedId))
        ? filter_var(
            $postedId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;
    $decision = $_POST['decision'] ?? null;
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        $errors[] = 'Invalid form session. Refresh the page and try again.';
    }
    if (!is_int($registrationId)) {
        $errors[] = 'Invalid registration selection.';
    }
    if (!is_string($decision) || !in_array($decision, ['approve', 'reject'], true)) {
        $errors[] = 'Invalid review decision.';
    }

    if ($errors === []) {
        try {
            $pdo = db();

            // Find the parent tournament, then lock that tournament first.
            $lookup = $pdo->prepare(
                'SELECT t_id FROM tournament_registration WHERE reg_id = :reg_id LIMIT 1'
            );
            $lookup->execute(['reg_id' => $registrationId]);
            $tournamentId = $lookup->fetchColumn();

            if ($tournamentId === false) {
                $errors[] = 'Registration not found or no longer available.';
            } else {
                $tournamentId = (int) $tournamentId;
                $pdo->beginTransaction();

                $lockTournament = $pdo->prepare(
                    "SELECT t.t_id, t.status, t.start_date
                     FROM tournaments AS t
                     INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
                     WHERE t.t_id = :tournament_id
                       AND sc.status = 'active'
                       $scopeSql
                     LIMIT 1
                     FOR UPDATE"
                );
                $lockTournament->execute(array_merge(
                    ['tournament_id' => $tournamentId],
                    $scopeParams
                ));
                $tournament = $lockTournament->fetch(PDO::FETCH_ASSOC);

                if (!is_array($tournament)) {
                    $errors[] = 'Registration not found or not authorized.';
                } elseif (
                    strtolower(trim((string) $tournament['status'])) !== 'upcoming' ||
                    (string) $tournament['start_date'] <= $today
                ) {
                    $errors[] = 'Reviews are closed for this tournament.';
                } else {
                    $lockRegistration = $pdo->prepare(
                        "SELECT reg_id, user_id, registration_status
                         FROM tournament_registration
                         WHERE reg_id = :reg_id AND t_id = :tournament_id
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $lockRegistration->execute([
                        'reg_id' => $registrationId,
                        'tournament_id' => $tournamentId,
                    ]);
                    $registration = $lockRegistration->fetch(PDO::FETCH_ASSOC);

                    if (
                        !is_array($registration) ||
                        $registration['registration_status'] !== 'pending'
                    ) {
                        $errors[] = 'Only pending registrations can be reviewed.';
                    } elseif ($decision === 'reject') {
                        // Do not reject a student who is already on a team.
                        $rosterCheck = $pdo->prepare(
                            "SELECT 1
                             FROM team_members AS member
                             INNER JOIN teams AS team ON team.team_id = member.team_id
                             WHERE team.t_id = :tournament_id
                               AND member.user_id = :student_id
                             LIMIT 1"
                        );
                        $rosterCheck->execute([
                            'tournament_id' => $tournamentId,
                            'student_id' => (int) $registration['user_id'],
                        ]);
                        if ($rosterCheck->fetchColumn() !== false) {
                            $errors[] = 'Remove this student from the tournament team before rejecting the registration.';
                        }
                    }

                    if ($errors === []) {
                        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
                        $reviewedAt = (new DateTimeImmutable(
                            'now',
                            new DateTimeZone('Asia/Colombo')
                        ))->format('Y-m-d H:i:s');

                        $update = $pdo->prepare(
                            "UPDATE tournament_registration
                             SET registration_status = :new_status,
                                 reviewed_by = :reviewer,
                                 reviewed_at = :reviewed_at
                             WHERE reg_id = :reg_id
                               AND t_id = :tournament_id
                               AND registration_status = 'pending'"
                        );
                        $update->execute([
                            'new_status' => $newStatus,
                            'reviewer' => $userId,
                            'reviewed_at' => $reviewedAt,
                            'reg_id' => $registrationId,
                            'tournament_id' => $tournamentId,
                        ]);

                        if ($update->rowCount() !== 1) {
                            $errors[] = 'Registration changed before the review was saved.';
                        }
                    }
                }

                if ($errors !== []) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    $_SESSION['tournament_review_success'] =
                        'Registration ' . ($decision === 'approve' ? 'approved' : 'rejected') . ' successfully.';
                    header('Location: /tournaments/registrations.php', true, 303);
                    exit;
                }
            }
        } catch (Throwable $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tournament registration review failed: ' . $error->getMessage());
            http_response_code(500);
            $errors[] = 'Unable to save the review. Please try again.';
        }
    }
}

try {
    $pdo ??= db();
    $list = $pdo->prepare(
        "SELECT
            tr.reg_id,
            tr.registration_status,
            tr.reg_date,
            tr.reviewed_at,
            t.t_id,
            t.t_name,
            t.status AS tournament_status,
            t.start_date,
            sc.club_name,
            student.name AS student_name,
            student.email AS student_email,
            reviewer.name AS reviewer_name,
            EXISTS (
                SELECT 1
                FROM team_members AS member
                INNER JOIN teams AS team ON team.team_id = member.team_id
                WHERE team.t_id = t.t_id AND member.user_id = tr.user_id
            ) AS in_team
         FROM tournament_registration AS tr
         INNER JOIN tournaments AS t ON t.t_id = tr.t_id
         INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
         INNER JOIN users AS student ON student.user_id = tr.user_id
         LEFT JOIN users AS reviewer ON reviewer.user_id = tr.reviewed_by
         WHERE sc.status = 'active'
           $scopeSql
         ORDER BY
           CASE WHEN tr.registration_status = 'pending' THEN 0 ELSE 1 END,
           tr.reg_date DESC,
           tr.reg_id DESC
         LIMIT 100"
    );
    $list->execute($scopeParams);
    $registrations = $list->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('Tournament registration review list failed: ' . $error->getMessage());
    http_response_code(500);
    $loadError = true;
}

require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading">
            <p class="section-label">NSBM SPORTSHUB / TOURNAMENT REGISTRATIONS</p>
            <h1>Review registrations.</h1>
            <p class="section-description">
                Review student participation requests for tournaments in your authorized sports clubs.
            </p>
        </header>

        <div class="tournament-view-navigation">
            <a href="/tournaments/manage.php" class="button button-outline">Back to Tournament Management</a>
        </div>

        <?php if (is_string($successMessage)): ?>
            <div class="tournament-form-success" role="status">
                <?= tournamentReviewEscape($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="tournament-form-alert" role="alert">
                <strong>Review could not be completed:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= tournamentReviewEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <div class="tournaments-notice" role="alert">
                <h2>Unable to load registrations</h2>
                <p>Please try again later.</p>
            </div>
        <?php else: ?>
            <div class="tournament-manage-toolbar">
                <div>
                    <h2>Student applications</h2>
                    <p>Showing <?= count($registrations) ?> registration(s), with pending requests first.</p>
                </div>
            </div>

            <?php if ($registrations === []): ?>
                <div class="tournaments-notice">
                    <h2>No applications available</h2>
                    <p>There are no tournament registrations within your authorized clubs.</p>
                </div>
            <?php else: ?>
                <div class="tournaments-grid">
                    <?php foreach ($registrations as $registration): ?>
                        <?php
                        $reviewAllowed =
                            $registration['registration_status'] === 'pending' &&
                            strtolower(trim((string) $registration['tournament_status'])) === 'upcoming' &&
                            (string) $registration['start_date'] > $today;
                        $studentInTeam = (int) $registration['in_team'] === 1;
                        ?>
                        <article class="tournament-card">
                            <div class="tournament-card-top">
                                <span class="tournament-sport">
                                    <?= tournamentReviewEscape($registration['club_name']) ?>
                                </span>
                                <span class="tournament-status">
                                    <?= tournamentReviewEscape(ucfirst((string) $registration['registration_status'])) ?>
                                </span>
                            </div>
                            <h2><?= tournamentReviewEscape($registration['student_name']) ?></h2>
                            <p class="tournament-club">
                                <?= tournamentReviewEscape($registration['student_email']) ?>
                            </p>
                            <div class="tournament-details">
                                <p><strong>Tournament</strong><span><?= tournamentReviewEscape($registration['t_name']) ?></span></p>
                                <p><strong>Applied</strong><span><?= tournamentReviewEscape($registration['reg_date']) ?></span></p>
                                <p><strong>Starts</strong><span><?= tournamentReviewEscape($registration['start_date']) ?></span></p>
                                <?php if ($registration['reviewer_name'] !== null): ?>
                                    <p><strong>Reviewed by</strong><span><?= tournamentReviewEscape($registration['reviewer_name']) ?></span></p>
                                    <p><strong>Reviewed at</strong><span><?= tournamentReviewEscape($registration['reviewed_at']) ?></span></p>
                                <?php endif; ?>
                            </div>

                            <?php if ($reviewAllowed): ?>
                                <div class="tournament-management-actions">
                                    <form method="post" action="/tournaments/registrations.php">
                                        <input type="hidden" name="csrf_token" value="<?= tournamentReviewEscape($csrfToken) ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $registration['reg_id'] ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="button button-accent">Approve</button>
                                    </form>
                                    <?php if (!$studentInTeam): ?>
                                        <form method="post" action="/tournaments/registrations.php">
                                            <input type="hidden" name="csrf_token" value="<?= tournamentReviewEscape($csrfToken) ?>">
                                            <input type="hidden" name="registration_id" value="<?= (int) $registration['reg_id'] ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <button type="submit" class="button button-outline">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <p class="tournament-view-muted">Already assigned to a team; rejection is blocked.</p>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($registration['registration_status'] === 'pending'): ?>
                                <p class="tournament-view-muted">Review is closed for this tournament.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if (count($registrations) === 100): ?>
                    <p class="tournament-manage-limit">Showing up to 100 registrations.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
