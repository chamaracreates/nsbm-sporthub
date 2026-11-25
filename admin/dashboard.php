<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Standalone admin pages share the site's POST/CSRF logout contract.
if (empty($_SESSION['logout_csrf']) || !is_string($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}


require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();


$totalStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'student'"
)->fetchColumn();


$activeClubs = (int) $pdo->query(
    "SELECT COUNT(*) FROM sports_clubs WHERE status = 'active'"
)->fetchColumn();


$upcomingMatches = (int) $pdo->query(
    "SELECT COUNT(*) FROM matches WHERE `date` >= CURDATE() AND match_status = 'scheduled'"
)->fetchColumn();


$activeTournaments = (int) $pdo->query(
    "SELECT COUNT(*) FROM tournaments WHERE start_date <= CURDATE() AND end_date >= CURDATE() "
)->fetchColumn();

// Dashboard counters are calculated from the current application records.
$completedMatches = (int) $pdo->query("SELECT COUNT(*) FROM matches WHERE LOWER(TRIM(match_status)) IN ('completed','finished')")->fetchColumn();
$historicalTournaments = (int) $pdo->query("SELECT COUNT(*) FROM tournaments WHERE is_historical = 1")->fetchColumn();
$pendingBookings = (int) $pdo->query("SELECT COUNT(*) FROM booking_request WHERE booking_status = 'pending'")->fetchColumn();
$upcomingTraining = (int) $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE TIMESTAMP(date,start_time) > NOW()")->fetchColumn();
$activeStaffAssignments = (int) $pdo->query("SELECT COUNT(*) FROM sport_staff WHERE status = 'active'")->fetchColumn();
$appointedLeaders = (int) $pdo->query("SELECT COUNT(*) FROM club_leaders")->fetchColumn();


$pendingApprovals = (int) $pdo->query(
    "SELECT
          (SELECT COUNT(*) FROM club_membership WHERE status = 'pending')
          +
          (SELECT COUNT(*) FROM tournament_registration WHERE registration_status = 'pending')
          +
          (SELECT COUNT(*) FROM match_registration WHERE registration_status = 'pending')
          +
          (SELECT COUNT(*) FROM booking_request WHERE booking_status = 'pending')
    "
)->fetchColumn();


// Use a common collation for UNION text columns.
$recentActivities = $pdo->query(
    "SELECT activity_date, activity_type, activity_title, activity_detail
     FROM (
        SELECT
            cn.created_at AS activity_date,
            'Club News' COLLATE utf8mb4_general_ci AS activity_type,
            cn.title COLLATE utf8mb4_general_ci AS activity_title,
            sc.club_name COLLATE utf8mb4_general_ci AS activity_detail
        FROM club_news cn
        LEFT JOIN sports_clubs sc ON cn.club_id = sc.club_id
        WHERE cn.status = 'published'

        UNION ALL

        SELECT
            ts.date AS activity_date,
            'Training Session' COLLATE utf8mb4_general_ci AS activity_type,
            ts.title COLLATE utf8mb4_general_ci AS activity_title,
            sc.club_name COLLATE utf8mb4_general_ci AS activity_detail
        FROM training_sessions ts
        LEFT JOIN sports_clubs sc ON ts.club_id = sc.club_id

        UNION ALL

        SELECT
            m.date AS activity_date,
            'Match' COLLATE utf8mb4_general_ci AS activity_type,
            m.title COLLATE utf8mb4_general_ci AS activity_title,
            CONCAT(m.sport, ' - ', m.match_status)
                COLLATE utf8mb4_general_ci AS activity_detail
        FROM matches m

        UNION ALL

        SELECT
            br.date AS activity_date,
            'Facility Booking' COLLATE utf8mb4_general_ci AS activity_type,
            br.purpose COLLATE utf8mb4_general_ci AS activity_title,
            CONCAT('Status: ', br.booking_status)
                COLLATE utf8mb4_general_ci AS activity_detail
        FROM booking_request br

        UNION ALL

        SELECT
            tr.reg_date AS activity_date,
            'Tournament Registration' COLLATE utf8mb4_general_ci AS activity_type,
            t.t_name COLLATE utf8mb4_general_ci AS activity_title,
            CONCAT('Status: ', tr.registration_status)
                COLLATE utf8mb4_general_ci AS activity_detail
        FROM tournament_registration tr
        LEFT JOIN tournaments t ON tr.t_id = t.t_id
     ) AS recent_activity
     ORDER BY activity_date DESC
     LIMIT 8"
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | NSBM SportsHub</title>

    <script src="/assets/js/site-interactions.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">

</head>

<body>
    <div class="admin-layout">

        <?php sportsHubAdminSidebar('dashboard'); ?>

        <!--Main Content-->
        <main class="main-content">

            <header class="topbar">
                <div>
                    <h2>Admin Dashboard</h2>
                    <p>NSBM Sports Club Management System</p>
                </div>
            </header>

            <!--Welcome Section-->
            <section class="welcome-section">

                <h1>Welcome to SportsHub Administration</h1>
                <p>
                    Manage Sports clubs, students, training sessions, tournaments, matches and university sports activities.
                </p>

            </section>

            <!--Dashboard Statistics-->
            <section class="statistics-section">
                <h2>Overview</h2>

                <div class="statistics-grid">
                    <div class="stat-card">
                        <h3>Total Students</h3>
                        <p class="stat-number"><?= number_format($totalStudents)?></p>
                    </div>

                    <div class="stat-card">
                        <h3>Active Sports Clubs</h3>
                        <p class="stat-number"><?= number_format($activeClubs)?></p>
                    </div>

                    <div class="stat-card">
                        <h3>Upcoming Matches</h3>
                        <p class="stat-number"><?= number_format($upcomingMatches)?></p>
                    </div>

                    <div class="stat-card">
                        <h3>Active Tournaments</h3>
                        <p class="stat-number"><?= number_format($activeTournaments)?></p>
                    </div>

                    <div class="stat-card">
                        <h3>Pending Approvals</h3>
                        <p class="stat-number"><?= number_format($pendingApprovals)?></p>
                    </div>

                    <!-- Operational metrics and management shortcuts. -->
                    <div class="stat-card"><h3>Completed Matches</h3><p class="stat-number"><?= number_format($completedMatches) ?></p></div>
                    <div class="stat-card"><h3>Pending Facility Bookings</h3><p class="stat-number"><?= number_format($pendingBookings) ?></p></div>
                    <div class="stat-card"><h3>Upcoming Training</h3><p class="stat-number"><?= number_format($upcomingTraining) ?></p></div>
                    <div class="stat-card"><h3>Active MIC / Coach Assignments</h3><p class="stat-number"><?= number_format($activeStaffAssignments) ?></p></div>
                    <div class="stat-card"><h3>Club Leader Assignments</h3><p class="stat-number"><?= number_format($appointedLeaders) ?></p></div>

                </div>

            </section>

            <!--Recent Activities-->

            <!-- Quick actions are separate from the dashboard metrics. -->
            <nav class="v15-shortcuts" aria-label="Administrative shortcuts">
                <h2>Quick actions</h2>
                <div class="v15-shortcut-links">
                    <a href="/bookings/admin/requests.php">Review bookings</a>
                    <a href="/matches/index.php?tab=results">Match results</a>
                    <a href="/training/manage.php">Training</a>
                    <a href="/clubs/admin/staff.php">Staff</a>
                    <a href="/clubs/admin/leaders.php">Club leadership</a>
                </div>
            </nav>

            <section class="recent-activities">

                <h2>Recent Activities</h2>

                <?php if (!empty($recentActivities)): ?>

                    <div class="activity-list">

                        <?php foreach ($recentActivities as $activity): ?>

                            <div class="activity-item">

                                <div class="activity-info">

                                    <span class="activity-type">
                                        <?= htmlspecialchars($activity['activity_type']) ?>
                                    </span>

                                    <h3>
                                        <?= htmlspecialchars((string) $activity['activity_title']) ?>
                                    </h3>

                                    <p>
                                        <?= htmlspecialchars((string) ($activity['activity_detail'] ??'')) ?>
                                    </p>

                                </div>

                                <span class="activity-date">
                                    <?= date('d M Y', strtotime($activity['activity_date'])) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <p class="empty-activity">
                        No recent activities available yet.
                    </p>

                <?php endif; ?>

            </section>

        </main>

    </div>

</body>

</html>