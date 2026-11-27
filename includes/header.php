<?php
require_once __DIR__ . '/auth.php';

$headerRole = current_user_role();
$headerLoggedIn = $headerRole !== null;
$headerAccountUrl = match ($headerRole) {
    'student' => '/student/profile.php',
    'admin' => '/admin/dashboard.php',
    'coach', 'mic' => '/training/manage.php',
    default => '/login.php',
};
$headerAccountLabel = match ($headerRole) {
    'student' => 'My profile',
    'admin' => 'Admin dashboard',
    'coach', 'mic' => 'Training management',
    default => 'Log in',
};

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
    <title><?= htmlspecialchars(isset($pageTitle) ? $pageTitle . ' | NSBM SportsHub' : 'NSBM SportsHub', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <?php if (!empty($preloadHomepageHero)): ?>
        <link rel="preload" as="image" href="/assets/images/herobanner.webp" fetchpriority="high">
        <link rel="preload" as="image" href="/assets/images/herobannerlight.webp" fetchpriority="high">
    <?php endif; ?>
    <script src="/assets/js/site-interactions.js?v=20261127-hero"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=20261127-hero">

    <?php foreach (($pageStylesheets ?? []) as $stylesheet): ?>
        <?php if (in_array($stylesheet, ['/assets/css/clubs.css', '/assets/css/tournaments.css', '/assets/css/training.css'], true)) $stylesheet .= '?v=20261127-live2'; ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

    <script defer src="/assets/js/navigation-icons.js"></script>
</head>

<body>
    <header class="site-header">
        <div class="topbar">
            <!-- Site branding -->
            <a href="/index.php" class="brand">
                <span class="brand-text">
                    <span>NSBM SportsHub</span>
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
                <a href="/search.php" class="phase13-search-trigger" aria-label="Search SportsHub" title="Search SportsHub (⌘ K)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                </a>
                <?php if ($headerLoggedIn): ?>
                    <?php require __DIR__ . '/profile-menu.php'; ?>
                <?php else: ?>
                    <a href="/login.php">Log in</a>
                    <a href="/register.php" class="join-btn">Register</a>
                <?php endif; ?>
            </div>

            <!-- Mobile navigation controls -->
            <div class="mobile-controls">
                <a href="/search.php" class="phase13-search-trigger" aria-label="Search SportsHub" title="Search SportsHub">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                </a>
                <?php if ($headerLoggedIn): ?>
                    <?php require __DIR__ . '/profile-menu.php'; ?>
                <?php else: ?>
                    <a href="/login.php" class="mobile-account-link" aria-label="Log in">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none"
                             stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>
                    </a>
                <?php endif; ?>

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
                    </nav>
                </details>
            </div>
        </div>
    </header>