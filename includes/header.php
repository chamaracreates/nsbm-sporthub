<?php
require_once __DIR__ . '/auth.php';

$headerRole = current_user_role();
$headerLoggedIn = $headerRole !== null;
$headerAccountUrl = $headerRole === 'student'
    ? '/student/profile.php'
    : '/login.php';
$headerAccountLabel = $headerRole === 'student'
    ? 'My profile'
    : ($headerLoggedIn ? 'My account' : 'Log in');

if (
    $headerLoggedIn &&
    (!isset($_SESSION['logout_csrf']) ||
     !is_string($_SESSION['logout_csrf']) ||
     $_SESSION['logout_csrf'] === '')
) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NSBM SportsHub</title>
    <link rel="stylesheet" href="/assets/css/style.css">

    <?php foreach (($pageStylesheets ?? []) as $stylesheet): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

</head>

<body>
    <header class="site-header">
        <div class="topbar">
            <!-- Site branding -->
            <a href="/index.php" class="brand">
                <span class="brand-text">
                    <span>SportsHub</span>
                </span>
            </a>

            <!-- Desktop navigation -->
            <nav class="main-nav">
                <a href="/index.php">Home</a>
                <a href="/clubs/index.php">Sports clubs</a>
                <a href="/tournaments/index.php">Tournaments</a>
                <a href="/matches/index.php">Fixtures</a>
                <a href="/training/index.php">Training</a>
                <a href="/bookings/index.php">Facilities</a>
            </nav>

            <!-- Desktop and tablet account actions -->
            <div class="account-nav">
                <?php if ($headerLoggedIn): ?>
                    <a href="<?= htmlspecialchars($headerAccountUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($headerAccountLabel, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <form action="/logout.php" method="POST" class="header-logout-form">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="header-logout-button">Log out</button>
                    </form>
                <?php else: ?>
                    <a href="/login.php">Log in</a>
                    <a href="/register.php" class="join-btn">Register</a>
                <?php endif; ?>
            </div>

            <!-- Mobile navigation controls -->
            <div class="mobile-controls">
                <a
                    href="<?= htmlspecialchars($headerAccountUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="mobile-account-link"
                    aria-label="<?= htmlspecialchars($headerAccountLabel, ENT_QUOTES, 'UTF-8') ?>">
                    <svg
                        viewBox="0 0 24 24"
                        width="24"
                        height="24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21a8 8 0 0 1 16 0"></path>
                    </svg>
                </a>

                <details class="mobile-menu">
                    <summary
                        class="mobile-menu-trigger"
                        aria-label="Toggle navigation menu">
                        <svg
                            viewBox="0 0 24 24"
                            width="26"
                            height="26"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            aria-hidden="true">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                    </summary>

                    <nav class="mobile-menu-panel" aria-label="Mobile navigation">
                        <a href="/index.php">Home</a>
                        <a href="/clubs/index.php">Sports clubs</a>
                        <a href="/tournaments/index.php">Tournaments</a>
                        <a href="/matches/index.php">Fixtures</a>
                        <a href="/training/index.php">Training</a>
                        <a href="/bookings/index.php">Facilities</a>

                        <div class="mobile-menu-account">
                            <?php if ($headerLoggedIn): ?>
                                <a href="<?= htmlspecialchars($headerAccountUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($headerAccountLabel, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <form action="/logout.php" method="POST" class="header-logout-form">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="header-logout-button">Log out</button>
                                </form>
                            <?php else: ?>
                                <a href="/login.php">Log in</a>
                                <a href="/register.php">Register</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                </details>
            </div>
        </div>
    </header>