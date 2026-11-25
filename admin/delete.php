<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';
require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// All identifiers below come from this fixed server-side map, never a user SQL fragment.
$definitions = [
    'match' => [
        'table' => 'matches',
        'key' => 'm_id',
        'name' => 'title',
        'singular' => 'match fixture',
        'checks' => [
            ['table' => 'match_registration', 'column' => 'm_id', 'label' => 'match registrations'],
            ['table' => 'tournament_results', 'column' => 'm_id', 'label' => 'recorded results'],
        ],
    ],
    'tournament' => [
        'table' => 'tournaments',
        'key' => 't_id',
        'name' => 't_name',
        'singular' => 'tournament',
        'checks' => [
            ['table' => 'teams', 'column' => 't_id', 'label' => 'teams'],
            ['table' => 'matches', 'column' => 't_id', 'label' => 'fixtures'],
            ['table' => 'tournament_registration', 'column' => 't_id', 'label' => 'tournament registrations'],
            ['table' => 'tournament_results', 'column' => 't_id', 'label' => 'tournament results'],
        ],
    ],
    'training' => [
        'table' => 'training_sessions',
        'key' => 'session_id',
        'name' => 'title',
        'singular' => 'training session',
        'checks' => [
            ['table' => 'training_attendance', 'column' => 'session_id', 'label' => 'attendance records'],
        ],
    ],
    'facility' => [
        'table' => 'resources',
        'key' => 'res_id',
        'name' => 'res_name',
        'singular' => 'facility',
        'checks' => [
            ['table' => 'booking_request', 'column' => 'res_id', 'label' => 'facility bookings'],
            ['table' => 'training_sessions', 'column' => 'location', 'label' => 'linked training sessions'],
        ],
    ],
];

$input = $method === 'POST' ? $_POST : $_GET;
$type = $input['type'] ?? null;
$idRaw = $input['id'] ?? null;
if (!is_string($type) || !isset($definitions[$type])) {
    http_response_code(400);
    exit('Invalid record type.');
}
$id = filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    http_response_code(400);
    exit('Invalid record identifier.');
}

$definition = $definitions[$type];
if (!isset($_SESSION['admin_delete_csrf']) || !is_string($_SESSION['admin_delete_csrf'])) {
    $_SESSION['admin_delete_csrf'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['admin_delete_csrf'];


if ($method === 'POST') {
    $givenToken = $_POST['csrf_token'] ?? null;
    if (!is_string($givenToken) || !hash_equals($token, $givenToken)) {
        http_response_code(403);
        exit('Invalid form submission.');
    }
}

$errorMessage = null;
$itemTitle = '';
$dependencies = [];
$recordFound = false;
$trainingStarted = false;
$pdo = null;

try {
    $pdo = db();
    if ($method === 'POST') {
        $pdo->beginTransaction();
    }
    $record = $pdo->prepare(
        'SELECT ' . $definition['name'] . ' AS record_title' .
        ($type === 'training' ? ', TIMESTAMP(date, start_time) > NOW() AS is_upcoming' : '') .
        ' FROM ' . $definition['table'] .
        ' WHERE ' . $definition['key'] . ' = :id' .
        ($method === 'POST' ? ' FOR UPDATE' : '')
    );
    $record->execute(['id' => $id]);
    $value = $record->fetch(PDO::FETCH_ASSOC);
    if ($value !== false) {
        $recordFound = true;
        $itemTitle = (string) $value['record_title'];
        $trainingStarted = $type === 'training' && (int) $value['is_upcoming'] !== 1;
        foreach ($definition['checks'] as $check) {
            // These names are immutable constants in the definitions map above.
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $check['table'] .
                ' WHERE ' . $check['column'] . ' = :id'
            );
            $countStmt->execute(['id' => (string) $id]);
            $count = (int) $countStmt->fetchColumn();
            if ($count > 0) {
                $dependencies[] = ['label' => $check['label'], 'count' => $count];
            }
        }
    }

    if ($method === 'POST') {
        if (!$recordFound) {
            $errorMessage = 'This record no longer exists.';
            http_response_code(404);
        } elseif ($trainingStarted) {
            $errorMessage = 'Only upcoming training sessions without attendance can be deleted.';
            http_response_code(409);
        } elseif ($dependencies !== []) {
            $errorMessage = 'This record cannot be deleted while related activity exists.';
            http_response_code(409);
        } elseif (($_POST['confirmation'] ?? null) !== 'DELETE') {
            $errorMessage = 'Type DELETE exactly to confirm this action.';
            http_response_code(400);
        } else {
            $deleteStmt = $pdo->prepare(
                'DELETE FROM ' . $definition['table'] .
                ' WHERE ' . $definition['key'] . ' = :id'
            );
            $deleteStmt->execute(['id' => $id]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('Deletion did not affect exactly one row.');
            }
            $pdo->commit();
            // Rotate the token after a successful destructive action.
            $_SESSION['admin_delete_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['admin_delete_feedback'] =
                ucfirst($definition['singular']) . ' deleted successfully.';
            header('Location: /admin/manage.php', true, 303);
            exit;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } elseif (!$recordFound) {
        http_response_code(404);
        $errorMessage = 'This record was not found.';
    } elseif ($trainingStarted) {
        http_response_code(409);
        $errorMessage = 'Only upcoming training sessions without attendance can be deleted.';
    }
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Admin delete operation failed: ' . $exception->getMessage());
    http_response_code(409);
    $errorMessage = 'This record cannot be removed safely. Check its related records or try again.';
}

$pageStylesheets = [];
require __DIR__ . '/../includes/header.php';
?>
<main class="admin-delete-page">
  <div class="admin-content-container">
    <h1>Delete <?= sportsHubEscape($definition['singular']) ?>?</h1>
    <p class="admin-content-lede">
      Permanent deletion is available to administrators only. Please review the record
      and its dependencies before confirming. This action cannot be undone.
    </p>
    <div class="admin-confirm-card">
      <?php if ($recordFound): ?>
        <h2><?= sportsHubEscape($itemTitle) ?></h2>
      <?php endif; ?>
      <?php if ($errorMessage !== null): ?>
        <p role="alert" class="admin-hub-notice"><?= sportsHubEscape($errorMessage) ?></p>
      <?php endif; ?>
      <?php if ($dependencies !== []): ?>
        <p>This item is linked to existing activity and cannot be deleted:</p>
        <ul class="admin-dependency-list">
          <?php foreach ($dependencies as $dependency): ?>
            <li>
              <?= (int) $dependency['count'] ?>
              <?= sportsHubEscape($dependency['label']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p>Preserve this history. You may manage or deactivate the relevant record instead.</p>
      <?php elseif ($recordFound && !$trainingStarted && $method === 'GET'): ?>
        <p>No linked records were found. Enter <strong>DELETE</strong> to confirm permanent removal.</p>
      <?php endif; ?>
      <?php if ($recordFound && !$trainingStarted && $dependencies === []): ?>
        <form method="post" action="/admin/delete.php">
          <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($token) ?>">
          <input type="hidden" name="type" value="<?= sportsHubEscape($type) ?>">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <label for="delete-confirmation">Type DELETE to confirm</label>
          <input id="delete-confirmation" name="confirmation" type="text"
                 autocomplete="off" pattern="DELETE" required>
          <button type="submit" class="button-danger-outline">Permanently delete</button>
        </form>
      <?php endif; ?>
      <div class="admin-hub-actions">
        <a href="/admin/manage.php">Cancel and return to management</a>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
