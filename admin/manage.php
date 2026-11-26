<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

$modules = [
    [
        'name' => 'Sports clubs',
        'description' => 'Create, edit and delete eligible sports clubs; manage memberships, staff and leaders.',
        'manage' => '/clubs/admin/clubs.php',
        'create' => '/clubs/admin/clubs.php',
        'extra' => '/clubs/admin/members.php',
        'extra_label' => 'Memberships',
    ],
    [
        'name' => 'Tournaments',
        'description' => 'Manage competitions, teams and student registrations. Delete unused tournaments from the management list.',
        'manage' => '/tournaments/manage.php',
        'create' => '/tournaments/create.php',
        'extra' => '/tournaments/registrations.php',
        'extra_label' => 'Registrations',
    ],
    [
        'name' => 'Fixtures & results',
        'description' => 'Schedule fixtures, review registrations, record results and manage unused matches.',
        'manage' => '/matches/manage.php',
        'create' => '/matches/create.php',
        'extra' => '/matches/standings.php',
        'extra_label' => 'Standings',
    ],
    [
        'name' => 'Sports facilities',
        'description' => 'Add, edit, activate or deactivate facilities. Delete only facilities with no bookings or linked training.',
        'manage' => '/bookings/admin/index.php',
        'create' => '/bookings/admin/create.php',
        'extra' => '/bookings/admin/requests.php',
        'extra_label' => 'Booking approvals',
    ],
    [
        'name' => 'Training sessions',
        'description' => 'Schedule training, review attendance and manage sessions. Delete only sessions with no recorded attendance.',
        'manage' => '/training/manage.php',
        'create' => '/training/create.php',
        'extra' => '/training/index.php',
        'extra_label' => 'Schedule',
    ],
];

$pageStylesheets = [];
require __DIR__ . '/../includes/header.php';
$feedback = $_SESSION['admin_delete_feedback'] ?? null;
unset($_SESSION['admin_delete_feedback']);
?>
<main class="admin-content-hub">
  <div class="admin-content-container">
    <h1>Manage SportsHub.</h1>
    <p class="admin-content-lede">
      A single starting point for SportsHub administration. Create and maintain
      clubs, tournaments, fixtures, facilities and training sessions.
    </p>
    <?php if (is_string($feedback)): ?>
      <p role="status" class="admin-hub-feedback">
        <?= htmlspecialchars($feedback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </p>
    <?php endif; ?>
    <div class="admin-hub-grid">
        <article class="admin-hub-card">
            <h2>Club staff and leadership</h2>
            <p>Create MIC and coach accounts, assign clubs and manage student leaders.</p>
            <div class="admin-hub-actions">
                <a href="/admin/accounts.php">Student and staff accounts</a>
                <a href="/clubs/admin/staff.php">Staff assignments</a>
                <a href="/clubs/admin/create-staff.php" class="primary">Create staff account</a>
                <a href="/clubs/admin/leaders.php">Club leadership</a>
            </div>
        </article>
        <article class="admin-hub-card phase14-news-module">
            <h2>Club news</h2>
            <p>Create, edit and publish club announcements, achievements and stories with cover images.</p>
            <div class="phase14-module-actions">
                <a class="button button-outline" href="/admin/news.php">Manage</a>
                <a class="button button-accent" href="/admin/news.php#news-editor">Create</a>
            </div>
        </article>

      <?php foreach ($modules as $module): ?>
        <section class="admin-hub-card">
          <h2><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
          <div class="admin-hub-actions">
            <a href="<?= htmlspecialchars($module['manage'], ENT_QUOTES, 'UTF-8') ?>">Manage</a>
            <a class="primary" href="<?= htmlspecialchars($module['create'], ENT_QUOTES, 'UTF-8') ?>">Create</a>
            <a href="<?= htmlspecialchars($module['extra'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($module['extra_label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <!-- Administrative module navigation. -->
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
