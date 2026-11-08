<?php
// NSBM SportsHub - Secure Logout

require_once __DIR__ . '/includes/auth.php';

auth_start();

// Logout must be submitted using POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Verify the logout CSRF token
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    empty($_SESSION['logout_csrf']) ||
    !hash_equals($_SESSION['logout_csrf'], $submittedToken)
) {
    http_response_code(403);
    exit('Invalid logout request.');
}

// Clear session variables
$_SESSION = [];

// Delete the browser's session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Destroy the session
session_destroy();

// Return to the login page
header('Location: /login.php');
exit;
