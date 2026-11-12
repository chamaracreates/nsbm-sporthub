<?php
// NSBM SportsHub - Shared Authentication Helpers

declare(strict_types=1);

// Start a session when needed
function auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Return the logged-in user's internal ID
function current_user_id(): ?int
{
    auth_start();

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $id = filter_var(
        $_SESSION['user_id'],
        FILTER_VALIDATE_INT
    );

    return $id !== false && $id > 0 ? $id : null;
}

// Return the current user's role
function current_user_role(): ?string
{
    auth_start();

    return current_user_id() !== null
        ? ($_SESSION['role'] ?? null)
        : null;
}

// Check whether a user is logged in
function is_logged_in(): bool
{
    return current_user_id() !== null;
}

// Restrict access to authenticated users
function require_login(): int
{
    $userId = current_user_id();

    if ($userId === null) {
        header('Location: /login.php');
        exit;
    }

    return $userId;
}

// Restrict access to administrators
function require_admin(): int
{
    $userId = require_login();

    if (current_user_role() !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }

    return $userId;
}
