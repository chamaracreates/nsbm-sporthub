<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$tournamentId = filter_var(
    $_POST['tournament_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if (!is_int($tournamentId)) {
    http_response_code(400);
    exit('Invalid tournament ID.');
}

$expectedToken = $_SESSION['tournament_register_csrf'] ?? null;
$submittedToken = $_POST['csrf_token'] ?? null;
if (
    !is_string($expectedToken) || !is_string($submittedToken)
    || !hash_equals($expectedToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid form session.');
}

function withdrawReturn(int $tournamentId, string $message, bool $success): never
{
    $_SESSION['tournament_withdraw_flash'] = [
        'tournament_id' => $tournamentId,
        'message' => $message,
        'success' => $success,
    ];
    header('Location: /tournaments/register.php?id=' . $tournamentId, true, 303);
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))
    ->format('Y-m-d');
$pdo = null;

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Use the same parent-row lock as team roster changes.
    $statement = $pdo->prepare(
        "SELECT t.status, t.start_date
         FROM tournaments AS t
         INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
         WHERE t.t_id = :tournament_id AND sc.status = 'active'
         FOR UPDATE"
    );
    $statement->execute(['tournament_id' => $tournamentId]);
    $tournament = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($tournament)
        || strtolower(trim((string) $tournament['status'])) !== 'upcoming'
        || (string) $tournament['start_date'] <= $today
    ) {
        $pdo->rollBack();
        withdrawReturn($tournamentId, 'Withdrawal is not available for this tournament.', false);
    }

    $statement = $pdo->prepare(
        "SELECT reg_id, registration_status
         FROM tournament_registration
         WHERE t_id = :tournament_id AND user_id = :user_id
         FOR UPDATE"
    );
    $statement->execute([
        'tournament_id' => $tournamentId,
        'user_id' => $userId,
    ]);
    $registration = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($registration)
        || !in_array((string) $registration['registration_status'], ['pending', 'approved'], true)
    ) {
        $pdo->rollBack();
        withdrawReturn($tournamentId, 'No active registration is available to withdraw.', false);
    }

    // Do not let a withdrawal invalidate an existing team roster.
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
    if ($statement->fetchColumn() !== false) {
        $pdo->rollBack();
        withdrawReturn(
            $tournamentId,
            'You are assigned to a tournament team. Contact tournament staff to withdraw.',
            false
        );
    }

    // Preserve the registration row for auditing.
    $statement = $pdo->prepare(
        "UPDATE tournament_registration
         SET registration_status = 'withdrawn'
         WHERE reg_id = :registration_id
           AND t_id = :tournament_id
           AND user_id = :user_id
           AND registration_status IN ('pending', 'approved')"
    );
    $statement->execute([
        'registration_id' => (int) $registration['reg_id'],
        'tournament_id' => $tournamentId,
        'user_id' => $userId,
    ]);

    if ($statement->rowCount() !== 1) {
        $pdo->rollBack();
        withdrawReturn($tournamentId, 'Registration status has changed. Please retry.', false);
    }

    $pdo->commit();
    withdrawReturn($tournamentId, 'Your registration has been withdrawn successfully.', true);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Tournament withdrawal error: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to process withdrawal. Please try again later.');
}