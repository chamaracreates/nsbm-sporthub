<?php
// NSBM SportsHub - Admin Dashboard

require_once __DIR__ . '/../includes/db.php';

$pdo = db();

//Total registered students
$totalStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'student'"
)->fetchColumn();

//Active sports clubs
$activeClubs = (int) $pdo->query(
    "SELECT COUNT(*) FROM sports_clubs WHERE status = 'active'"
)->fetchColumn();

//Upcoming matches
$upcomingMatches = (int) $pdo->query(
    "SELECT COUNT(*) FROM matches WHERE `date` >= CURDATE() AND match_status = 'scheduled'"
)->fetchColumn();

//Currently running tournaments
$activeTournaments = (int) $pdo->query(
    "SELECT COUNT(*) FROM tournaments WHERE start_date <= CURDATE() AND end_date >= CURDATE() "
)->fetchColumn();

//Total pending approvals
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

//Recent sports activities
$recentActivities = $pdo->query(
    "SELECT activity_date, activity_type, activity_title, activity_detail
     FROM (
        SELECT
            cn.created_at AS activity_date,
            'Club News' AS activity_type,
            cn.title AS activity_title,
            sc.club_name AS activity_detail
        FROM club_news cn
        LEFT JOIN sports_clubs sc ON cn.club_id = sc.club_id
        WHERE cn.status = 'published'

        UNION ALL

        SELECT
            ts.date,
            'Training Session',
            ts.title,
            sc.club_name
        FROM training_sessions ts
        LEFT JOIN sports_clubs sc ON ts.club_id = sc.club_id

        UNION ALL

        SELECT
            m.date,
            'Match',
            m.title,
            CONCAT(m.sport, ' - ', m.match_status)
        FROM matches m

        UNION ALL

        SELECT
            br.date,
            'Facility Booking',
            br.purpose,
            CONCAT('Status: ', br.booking_status)
        FROM booking_request br

        UNION ALL

        SELECT
            tr.reg_date,
            'Tournament Registration',
            t.t_name,
            CONCAT('Status: ', tr.registration_status)
        FROM tournament_registration tr
        LEFT JOIN tournaments t ON tr.t_id = t.t_id
     ) AS recent_activity
     ORDER BY activity_date DESC
     LIMIT 8

 ")->fetchALL();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | NSBM SportsHub</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">

</head>

<body>
    <div class="admin-layout">

        <!--sidebar-->
        <aside class="sidebar">

            <div class="sidebar-brand">

                NSBM SportsHub
                <span>ADMINISTRATION</span>
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link active">
                    Dashboard
                </a>

                <a href="../student/" class="nav-link">
                    Students
                </a>

                <a href="../clubs/" class="nav-link">
                    Sports Clubs
                </a>

                <a href="../training/" class="nav-link">
                    Training Sessions
                </a>

                <a href="../tournaments/" class="nav-link">
                    Tournaments
                </a>

                <a href="../matches/" class="nav-link">
                    Matches
                </a>

                <a href="../bookings/" class="nav-link">
                    Facilities & Bookings
                </a>

                <a href="../reports/" class="nav-link">
                    Reports & Analytics
                </a>

            </nav>

        </aside>

        <!--Main Content-->
        <main class="main-content">

            <header class="topbar">
                <div>
                    <h2>Admin Dashboard</h2>
                    <p>NSBM Sports Club Management System</p>
                </div>

                <div class="admin-profile">
                    Administrator
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

                </div>

            </section>

            <!--Recent Activities-->
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