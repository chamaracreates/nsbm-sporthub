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

// Verify the account and refresh its role once per request.
function current_user_role(): ?string
{
    $userId = current_user_id();

    if ($userId === null) {
        return null;
    }

    static $verifiedId = null;
    static $verifiedRole = null;

    if ($verifiedId === $userId) {
        return $verifiedRole;
    }

    $verifiedId = $userId;

    try {
        require_once __DIR__ . '/db.php';

        $statement = db()->prepare(
            'SELECT role, name FROM users WHERE user_id = :user_id LIMIT 1'
        );

        $statement->execute(['user_id' => $userId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($account) ||
            !in_array(
                $account['role'] ?? null,
                ['admin', 'student', 'coach', 'mic'],
                true
            )
        ) {
            unset(
                $_SESSION['user_id'],
                $_SESSION['role'],
                $_SESSION['name']
            );

            return null;
        }

        $verifiedRole = $account['role'];
        $_SESSION['role'] = $verifiedRole;
        $_SESSION['name'] = (string) $account['name'];

        return $verifiedRole;

    } catch (Throwable $error) {
        error_log(
            'Authentication account verification failed: ' .
            $error->getMessage()
        );

        return null;
    }
}

// Check whether a user is logged in
function is_logged_in(): bool
{
    return current_user_role() !== null;
}

// Restrict access to authenticated users
function require_login(): int
{
    $userId = current_user_id();

    if ($userId === null || current_user_role() === null) {
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
