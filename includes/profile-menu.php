<?php
$menuIcons = [
    'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'management' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h5"/>',
    'members' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/><circle cx="9" cy="7" r="4"/>',
    'registrations' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6M9 18h3"/>',
    'bookings' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M9 15l2 2 4-4"/>',
    'history' => '<path d="M3 12a9 9 0 1 1 3 6.7M3 12V6m0 6h6M12 7v5l3 2"/>',
    'leadership' => '<path d="m3 7 5 4 4-7 4 7 5-4-2 12H5L3 7zM5 21h14"/>',
    'tournaments' => '<path d="M8 21h8M12 17v4M7 4h10v7a5 5 0 0 1-10 0V4zM7 6H3v2a4 4 0 0 0 4 4M17 6h4v2a4 4 0 0 1-4 4"/>',
    'fixtures' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 15h4m2 0h4"/>',
];
$menuLinks = [[$headerAccountUrl, $headerAccountLabel, $headerRole === 'student' ? 'profile' : 'management']];
if ($headerRole === 'admin') {
    $menuLinks[] = ['/admin/manage.php', 'Manage content', 'management'];
} elseif ($headerRole === 'student') {
    $menuLinks = array_merge($menuLinks, [
        ['/clubs/my-memberships.php', 'My memberships', 'members'],
        ['/student/registrations.php', 'My registrations', 'registrations'],
        ['/bookings/history.php', 'My bookings', 'bookings'],
        ['/student/history.php', 'Participation history', 'history'],
    ]);
} elseif (in_array($headerRole, ['mic', 'coach'], true)) {
    $menuLinks[] = ['/clubs/admin/members.php', $headerRole === 'mic' ? 'Membership applications' : 'Club members', 'members'];
    if ($headerRole === 'mic') {
        $menuLinks[] = ['/clubs/admin/leaders.php', 'Club leadership', 'leadership'];
    }
    $menuLinks = array_merge($menuLinks, [
        ['/tournaments/manage.php', 'Tournament management', 'tournaments'],
        ['/tournaments/registrations.php', 'Tournament registrations', 'registrations'],
        ['/matches/manage.php', 'Match management', 'fixtures'],
    ]);
}
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
        <?php foreach ($menuLinks as [$url, $label, $icon]): ?>
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                <svg class="nsbm-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $menuIcons[$icon] ?></svg>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
        <form action="/logout.php" method="POST" class="header-logout-form">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="header-logout-button"><svg class="nsbm-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/></svg><span>Log out</span></button>
        </form>
    </nav>
</details>
