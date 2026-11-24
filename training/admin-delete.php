<?php
/** Delete eligible future training sessions after authorization and CSRF validation. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/timezone.php';
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Method not allowed.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['phase14_training_csrf'])
    || !hash_equals((string) $_SESSION['phase14_training_csrf'], $token)) {
    http_response_code(403); exit('Invalid form session.');
}
$id = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    http_response_code(400); exit('Invalid session ID.');
}
try {
    $pdo = trainingDb();
    $pdo->beginTransaction();
    $get = $pdo->prepare('SELECT session_id, date, start_time FROM training_sessions WHERE session_id = ? FOR UPDATE');
    $get->execute([$id]);
    $session = $get->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        $pdo->rollBack();
        $_SESSION['phase14_training_flash'] = 'Training session no longer exists.';
    } else {
        $att = $pdo->prepare('SELECT COUNT(*) FROM training_attendance WHERE session_id = ?');
        $att->execute([$id]);
        $clock = $pdo->prepare('SELECT TIMESTAMP(?, ?) > NOW()');
        $clock->execute([$session['date'], $session['start_time']]);
        if ((int) $att->fetchColumn() > 0 || (int) $clock->fetchColumn() !== 1) {
            $pdo->rollBack();
            $_SESSION['phase14_training_flash'] = 'Only upcoming training sessions without attendance can be deleted.';
        } else {
            $delete = $pdo->prepare('DELETE FROM training_sessions WHERE session_id = ?');
            $delete->execute([$id]);
            $pdo->commit();
            $_SESSION['phase14_training_flash'] = 'Training session deleted.';
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Training deletion: ' . $e->getMessage());
    $_SESSION['phase14_training_flash'] = 'Could not delete training session. Please check dependencies.';
}
header('Location: /training/index.php', true, 303);
exit;
