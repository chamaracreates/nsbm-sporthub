<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
$userId = require_login();
if (current_user_role() !== 'student') { http_response_code(403); exit('Access denied.'); }
$groups = [];
$error = false;
try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT t.t_id AS id, t.t_name AS title, t.start_date AS event_date, tr.registration_status FROM tournament_registration tr JOIN tournaments t ON t.t_id = tr.t_id WHERE tr.user_id = ? ORDER BY t.start_date DESC');
    $stmt->execute([$userId]);
    $groups['Tournaments'] = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT m.m_id AS id, m.title, m.date AS event_date, mr.registration_status FROM match_registration mr JOIN matches m ON m.m_id = mr.m_id WHERE mr.user_id = ? ORDER BY m.date DESC');
    $stmt->execute([$userId]);
    $groups['Matches'] = $stmt->fetchAll();
} catch (Throwable $e) { error_log('Student registrations: ' . $e->getMessage()); $error = true; }
$pageTitle = 'My registrations';
$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';
?>
<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading"><h1>My registrations</h1><p class="section-description">Track tournament and match participation requests.</p></header>
        <nav class="tournament-view-navigation" aria-label="Explore competitions">
            <a class="button button-accent" href="/tournaments/index.php">Explore tournaments</a>
            <a class="button button-outline" href="/matches/index.php">Explore fixtures</a>
        </nav>
        <?php if ($error): ?><p class="tournament-form-alert" role="alert">Unable to load registrations.</p><?php endif; ?>
        <?php foreach ($groups as $label=>$rows): ?>
            <section class="registration-section">
                <h2><?= sportsHubEscape($label) ?></h2>
                <?php if (!$rows): ?>
                    <div class="tournaments-notice"><p>No <?= $label === 'Tournaments' ? 'tournament' : 'match' ?> registrations yet.</p></div>
                <?php else: ?>
                    <div class="registration-list">
                        <?php foreach ($rows as $row): ?>
                            <article class="registration-card">
                                <div class="registration-card-content">
                                    <span class="tournament-status registration-status-<?= sportsHubEscape($row['registration_status']) ?>"><?= sportsHubEscape(ucfirst($row['registration_status'])) ?></span>
                                    <h3><?= sportsHubEscape($row['title']) ?></h3>
                                    <p><time datetime="<?= sportsHubEscape($row['event_date']) ?>"><?= sportsHubEscape(sportsHubDisplayDate($row['event_date'])) ?></time></p>
                                </div>
                                <a class="button button-accent" href="/<?= $label === 'Tournaments' ? 'tournaments' : 'matches' ?>/register.php?id=<?= (int) $row['id'] ?>">Manage registration</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
