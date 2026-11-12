<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Membership applications must use POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Students must be logged in.
$userId = require_login();

// Only student accounts can apply.
if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Only students can apply for club membership.');
}

// Check the CSRF token submitted by the club page.
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !isset($_SESSION['club_join_csrf']) ||
    !is_string($_SESSION['club_join_csrf']) ||
    !hash_equals($_SESSION['club_join_csrf'], $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request. Reload the club page and try again.');
}

// Validate the selected club.
$clubId = filter_input(
    INPUT_POST,
    'club_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$clubId) {
    http_response_code(400);
    exit('Invalid club selection.');
}

try {
    $pdo = db();

    // Only active clubs can accept applications.
    $stmt = $pdo->prepare(
        "SELECT club_id
         FROM sports_clubs
         WHERE club_id = :club_id
           AND status = 'active'
         LIMIT 1"
    );

    $stmt->execute([
        'club_id' => $clubId
    ]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        exit('Club not found or unavailable.');
    }

    // Create the membership application.
    $stmt = $pdo->prepare(
        "INSERT INTO club_membership
            (user_id, club_id, status)
         VALUES
            (:user_id, :club_id, 'pending')"
    );

    $stmt->execute([
        'user_id' => $userId,
        'club_id' => $clubId
    ]);

    // Clear the token after successful submission.
    unset($_SESSION['club_join_csrf']);

    // Redirect to the club profile.
    header(
        'Location: /clubs/view.php?id=' .
        $clubId .
        '&membership=sent',
        true,
        303
    );
    exit;

} catch (PDOException $exception) {

    // The database has a unique user_id + club_id key.
    // This also protects against simultaneous duplicates.
    if ((string) $exception->getCode() === '23000') {
        header(
            'Location: /clubs/view.php?id=' .
            $clubId .
            '&membership=existing',
            true,
            303
        );
        exit;
    }

    error_log(
        'Membership application error: ' .
        $exception->getMessage()
    );

    http_response_code(500);
    exit('Unable to submit your application right now.');
}
