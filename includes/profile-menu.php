<?php
/**
 * Account-menu context supplied by includes/header.php.
 *
 * @var string $headerAccountUrl
 * @var string $headerAccountLabel
 * @var string|null $headerRole
 */
// Shared account dropdown; rendered only for verified, logged-in roles.
?>
<details class="header-profile-menu">
    <summary class="header-profile-trigger" aria-label="Account options" title="Account options">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21a8 8 0 0 1 16 0"></path>
        </svg>
    </summary>
    <nav class="header-profile-panel" aria-label="Account options">
        <a href="<?= htmlspecialchars($headerAccountUrl, ENT_QUOTES, 'UTF-8') ?>">
            <svg class="nsbm-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
            <span><?= htmlspecialchars($headerAccountLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php if ($headerRole === 'admin'): ?>
            <a href="/admin/manage.php"><svg class="nsbm-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h5"/></svg><span>Manage content</span></a>
        <?php endif; ?>
        <form action="/logout.php" method="POST" class="header-logout-form">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="header-logout-button"><svg class="nsbm-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/></svg><span>Log out</span></button>
        </form>
    </nav>
</details>
