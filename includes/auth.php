<?php


declare(strict_types=1);

// Validate new student passwords before hashing.
function student_password_error(string $password): ?string
{
    // PHP 8.3 uses bcrypt for PASSWORD_DEFAULT, which accepts at most 72 bytes.
    if (strlen($password) > 72) {
        return 'Password must not exceed 72 bytes.';
    }

    if (preg_match('//u', $password) !== 1 || preg_match('/[\x00\r\n]/', $password)) {
        return 'Password contains invalid characters.';
    }

    if (preg_match('/\A.{8,}\z/us', $password) !== 1) {
        return 'Password must contain at least 8 characters.';
    }

    if (
        !preg_match('/\p{Lu}/u', $password) ||
        !preg_match('/\p{Ll}/u', $password) ||
        !preg_match('/\p{Nd}/u', $password) ||
        !preg_match('/[\p{P}\p{S}]/u', $password)
    ) {
        return 'Password must include an uppercase letter, a lowercase letter, a number and a special character.';
    }

    return null;
}


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


function auth_login_rate_limit(string $email, bool $reset = false): bool
{
    $address = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = is_string($address) ? @inet_pton($address) : false;
    $client = $packed === false ? 'unknown' : bin2hex($packed);
    $clientKey = hash('sha256', 'client:' . $client);
    $accountKey = hash('sha256', 'account:' . $client . ':' . $email);
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'sportshub-auth-' . hash('sha256', __DIR__);
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Unable to initialize login rate limiting.');
    }
    $storage = realpath($directory);
    $documentRoot = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__));
    if ($storage === false || is_link($directory) || $documentRoot === false ||
        $storage === $documentRoot || str_starts_with($storage, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        (@fileperms($storage) & 0777) !== 0700) {
        throw new RuntimeException('Login rate-limit storage is not private.');
    }

    $lock = @fopen($storage . '/attempts.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open login rate-limit storage.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock login rate-limit storage.');
        }
        $path = $storage . '/attempts.json';
        $entries = [];
        if (is_file($path)) {
            $data = @file_get_contents($path, false, null, 0, 1048577);
            if ($data === false || strlen($data) > 1048576) {
                throw new RuntimeException('Unable to read login rate-limit storage.');
            }
            $entries = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) {
                throw new RuntimeException('Invalid login rate-limit storage.');
            }
        }
        $now = time();
        foreach ($entries as $key => $entry) {
            if (!is_array($entry) || !isset($entry['expires'], $entry['count']) ||
                !is_int($entry['expires']) || !is_int($entry['count'])) {
                throw new RuntimeException('Invalid login rate-limit entry.');
            }
            if ($entry['expires'] <= $now) {
                unset($entries[$key]);
            }
        }

        if ($reset) {
            unset($entries[$accountKey]);
        } else {
            if (($entries[$clientKey]['count'] ?? 0) >= 60 ||
                ($entries[$accountKey]['count'] ?? 0) >= 5 ||
                count($entries) + (int) !isset($entries[$clientKey]) + (int) !isset($entries[$accountKey]) > 4096) {
                return false;
            }
            foreach ([$clientKey, $accountKey] as $key) {
                $entries[$key] ??= ['expires' => $now + 300, 'count' => 0];
                $entries[$key]['count']++;
            }
        }

        // Atomic replacement keeps the previous counter intact if writing fails.
        $temporary = @tempnam($storage, 'attempts-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to write login rate-limit storage.');
        }
        try {
            $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporary, $encoded) !== strlen($encoded) || !@rename($temporary, $path)) {
                throw new RuntimeException('Unable to save login rate-limit storage.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}


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
