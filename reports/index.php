<?php
// NSBM SportsHub - Reports & Analytics

require_once __DIR__ . '/../includes/db.php';

$pdo = db();

// Report filter values
$rawStartDate = trim((string) ($_GET['start_date'] ?? ''));
$rawEndDate = trim((string) ($_GET['end_date'] ?? ''));

$startDate = null;
$endDate = null;
$filterError = '';

$validateDate = static function (string $date): ?string {
    if ($date === '') {
        return null;
    }

    $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsedDate && $parsedDate->format('Y-m-d') === $date
        ? $date
        : null;
};

$startDate = $validateDate($rawStartDate);
$endDate = $validateDate($rawEndDate);

if ($rawStartDate !== '' && $startDate === null) {
    $filterError = 'Please enter a valid start date.';
}

if ($rawEndDate !== '' && $endDate === null) {
    $filterError = 'Please enter a valid end date.';
}

if (
    $startDate !== null &&
    $endDate !== null &&
    $startDate > $endDate
) {
    $filterError = 'The start date cannot be after the end date.';
}

// Selected club and sport
$selectedClubId = filter_var(
    $_GET['club_id'] ?? '',
    FILTER_VALIDATE_INT
);

$selectedClubId = $selectedClubId !== false
    ? (int) $selectedClubId
    : 0;

$selectedSport = trim((string) ($_GET['sport'] ?? ''));

// Active club options
$filterClubs = $pdo->query("
    SELECT club_id, club_name
    FROM sports_clubs
    WHERE status = 'active'
    ORDER BY club_name
")->fetchAll(PDO::FETCH_ASSOC);

// Available sports
$filterSports = $pdo->query("
    SELECT sport
    FROM sports_clubs
    WHERE status = 'active'

    UNION

    SELECT DISTINCT sport
    FROM matches
    WHERE sport <> ''

    ORDER BY sport
")->fetchAll(PDO::FETCH_COLUMN);

// Participation overview
$summary = $pdo->query("
    SELECT
        COUNT(*) AS students_in_report,
        COALESCE(SUM(total_clubs_joined), 0) AS total_club_participation,
        COALESCE(SUM(training_sessions_attended), 0) AS total_training_attendance,
        COALESCE(SUM(tournaments_registered), 0) AS total_tournament_registrations,
        COALESCE(SUM(facility_bookings_made), 0) AS total_facility_bookings
    FROM v_student_participation_summary
")->fetch(PDO::FETCH_ASSOC);

// Active clubs
$activeClubs = (int) $pdo->query(
    "SELECT COUNT(*) FROM sports_clubs WHERE status = 'active'"
)->fetchColumn();

// Individual student participation
$studentParticipation = $pdo->query("
    SELECT
        user_id,
        student_name,
        email,
        total_clubs_joined,
        training_sessions_attended,
        tournaments_registered,
        facility_bookings_made
    FROM v_student_participation_summary
    ORDER BY student_name ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Club membership statistics
$clubMemberships = $pdo->query("
    SELECT
        sc.club_name,
        sc.sport,
        COUNT(DISTINCT CASE
            WHEN cm.status = 'approved' THEN cm.user_id
        END) AS approved_members,
        COUNT(DISTINCT CASE
            WHEN cm.status = 'pending' THEN cm.user_id
        END) AS pending_members
    FROM sports_clubs sc
    LEFT JOIN club_membership cm
        ON cm.club_id = sc.club_id
    WHERE sc.status = 'active'
    GROUP BY sc.club_id, sc.club_name, sc.sport
    ORDER BY sc.club_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Training attendance report
$trainingAttendance = $pdo->query("
    SELECT
        u.name AS student_name,
        ts.title AS session_title,
        sc.club_name,
        sc.sport,
        ts.date AS session_date,
        ta.att_status
    FROM training_attendance ta
    INNER JOIN users u
        ON u.user_id = ta.user_id
    INNER JOIN training_sessions ts
        ON ts.session_id = ta.session_id
    INNER JOIN sports_clubs sc
        ON sc.club_id = ts.club_id
    WHERE u.role = 'student'
    ORDER BY ts.date DESC, u.name ASC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Match performance summary
$matchPerformanceSummary = $pdo->query("
    SELECT
        COUNT(*) AS completed_matches,
        COALESCE(SUM(CASE
            WHEN home_score > away_score THEN 1 ELSE 0
        END), 0) AS home_wins,
        COALESCE(SUM(CASE
            WHEN away_score > home_score THEN 1 ELSE 0
        END), 0) AS away_wins,
        COALESCE(SUM(CASE
            WHEN home_score = away_score THEN 1 ELSE 0
        END), 0) AS draws
    FROM matches
    WHERE LOWER(TRIM(match_status))
        IN ('completed', 'finished')
")->fetch(PDO::FETCH_ASSOC);

// Match performance details
$matchPerformance = $pdo->query("
    SELECT
        title,
        sport,
        home_name,
        away_name,
        date AS match_date,
        venue,
        match_status,
        home_score,
        away_score,
        CASE
            WHEN LOWER(TRIM(match_status))
                IN ('completed', 'finished')
            THEN
                CASE
                    WHEN home_score > away_score
                        THEN CONCAT(home_name, ' won')
                    WHEN away_score > home_score
                        THEN CONCAT(away_name, ' won')
                    ELSE 'Draw'
                END
            ELSE 'Not played'
        END AS result_summary
    FROM matches
    ORDER BY date DESC, m_id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reports & Analytics | NSBM SportsHub</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/report.css">
</head>

<body>

    <div class="admin-layout">

        <!-- Sidebar -->
        <aside class="sidebar">

            <div class="sidebar-brand">
                NSBM SportsHub
                <span>ADMINISTRATION</span>
            </div>

            <nav class="sidebar-nav">

                <a href="../admin/dashboard.php" class="nav-link">
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

                <a href="index.php" class="nav-link active">
                    Reports & Analytics
                </a>

            </nav>

        </aside>

        <!-- Main Content -->
        <main class="main-content">

            <header class="topbar">

                <div>
                    <h2>Reports & Analytics</h2>
                    <p>NSBM Sports Club Management System</p>
                </div>

                <div class="admin-profile">
                    Administrator
                </div>

            </header>

            <!-- Welcome Section -->
            <section class="welcome-section">

                <h1>SportsHub Reports & Insights</h1>

                <p>
                    Monitor student participation, club memberships,
                    training attendance, tournament registrations
                    and facility usage.
                </p>

            </section>


            <!-- Report Filters -->
            <section class="report-section report-filters">
                <div class="report-heading">
                    <div>
                        <h2>Filter Reports</h2>
                        <p>Choose a date range, sports club or sport.</p>
                    </div>
                </div>

                <?php if ($filterError !== ''): ?>
                    <p class="filter-error">
                        <?= htmlspecialchars($filterError) ?>
                    </p>

                <?php endif; ?>

                <form method="GET" action="index.php">
                    <div class="filter-grid">

                        <div class="filter-field">
                            <label for="start_date">Start Date</label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                value="<?= htmlspecialchars($rawStartDate) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="end_date">End Date</label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                value="<?= htmlspecialchars($rawEndDate) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="club_id">Sports Club</label>
                            <select id="club_id" name="club_id">
                                <option value="">All Clubs</option>

                                <?php foreach ($filterClubs as $club): ?>
                                    <option
                                        value="<?= (int) $club['club_id'] ?>"
                                        <?= $selectedClubId === (int) $club['club_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($club['club_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="sport">Sport</label>
                            <select id="sport" name="sport">
                                <option value="">All Sports</option>

                                <?php foreach ($filterSports as $sport): ?>
                                    <option
                                        value="<?= htmlspecialchars($sport) ?>"
                                        <?= $selectedSport === $sport ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sport) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="filter-button">
                            Apply Filters
                        </button>

                        <a href="index.php" class="filter-button filter-reset-button">
                            Reset Filters
                        </a>
                    </div>
                </form>
            </section>


            <!-- Participation Overview -->
            <section class="statistics-section">

                <h2>Participation Overview</h2>

                <div class="statistics-grid">

                    <div class="stat-card">
                        <h3>Students in Report</h3>
                        <p class="stat-number">
                            <?= number_format((int) $summary['students_in_report']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Club Participations</h3>
                        <p class="stat-number">
                            <?= number_format((int) $summary['total_club_participation']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Training Attendance</h3>
                        <p class="stat-number">
                            <?= number_format((int) $summary['total_training_attendance']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Tournament Registrations</h3>
                        <p class="stat-number">
                            <?= number_format((int) $summary['total_tournament_registrations']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Facility Bookings</h3>
                        <p class="stat-number">
                            <?= number_format((int) $summary['total_facility_bookings']) ?>
                        </p>
                    </div>

                </div>

            </section>

            <!-- Student Participation Report -->
            <section class="report-section">

                <div class="report-heading">
                    <div>
                        <h2>Student Participation Report</h2>
                        <p>Individual student sports participation statistics</p>
                    </div>
                </div>

                <div class="report-table-wrapper">

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Email</th>
                                <th>Clubs Joined</th>
                                <th>Training Attended</th>
                                <th>Tournaments</th>
                                <th>Facility Bookings</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (!empty($studentParticipation)): ?>

                                <?php foreach ($studentParticipation as $student): ?>

                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) ($student['student_name'] ?? 'Unknown')) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars((string) ($student['email'] ?? '—')) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $student['total_clubs_joined']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $student['training_sessions_attended']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $student['tournaments_registered']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $student['facility_bookings_made']) ?>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="6" class="empty-report">
                                        No student participation records available yet.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="report-note">
                    Showing up to 50 student records.
                </p>

            </section>

            <!-- Club Membership Report -->
            <section class="report-section">

                <div class="report-heading">
                    <div>
                        <h2>Club Membership Statistics</h2>
                        <p>
                            Membership overview across
                            <?= number_format($activeClubs) ?> active sports clubs
                        </p>
                    </div>
                </div>

                <div class="report-table-wrapper">

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Sports Club</th>
                                <th>Sport</th>
                                <th>Approved Members</th>
                                <th>Pending Requests</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (!empty($clubMemberships)): ?>

                                <?php foreach ($clubMemberships as $club): ?>

                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) $club['club_name']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars((string) $club['sport']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $club['approved_members']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $club['pending_members']) ?>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="4" class="empty-report">
                                        No active sports club records available yet.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

            <!-- Training Attendance Report -->
            <section class="report-section">
                <div class="report-heading">
                    <div>
                        <h2>Training Attendance Report</h2>
                        <p>Student attendance across sports club training sessions</p>
                    </div>
                </div>

                <div class="report-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Training Session</th>
                                <th>Sports Club</th>
                                <th>Sport</th>
                                <th>Session Date</th>
                                <th>Attendance Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($trainingAttendance)): ?>
                                <?php foreach ($trainingAttendance as $attendance): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($attendance['student_name']) ?></td>
                                        <td><?= htmlspecialchars($attendance['session_title']) ?></td>
                                        <td><?= htmlspecialchars($attendance['club_name']) ?></td>
                                        <td><?= htmlspecialchars($attendance['sport']) ?></td>
                                        <td><?= htmlspecialchars($attendance['session_date']) ?></td>
                                        <td><?= htmlspecialchars($attendance['att_status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-report">
                                        No training attendance records available yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="report-note">
                    Showing up to 100 attendance records.
                </p>
            </section>

            <!-- Match Performance Report -->
            <section class="report-section">
                <div class="report-heading">
                    <div>
                        <h2>Match Performance Overview</h2>
                        <p>Results and performance statistics from completed matches</p>
                    </div>
                </div>

                <div class="statistics-grid">
                    <div class="stat-card">
                        <h3>Completed Matches</h3>
                        <p class="stat-number">
                            <?= number_format((int) $matchPerformanceSummary['completed_matches']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Home Wins</h3>
                        <p class="stat-number">
                            <?= number_format((int) $matchPerformanceSummary['home_wins']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Away Wins</h3>
                        <p class="stat-number">
                            <?= number_format((int) $matchPerformanceSummary['away_wins']) ?>
                        </p>
                    </div>

                    <div class="stat-card">
                        <h3>Draws</h3>
                        <p class="stat-number">
                            <?= number_format((int) $matchPerformanceSummary['draws']) ?>
                        </p>
                    </div>
                </div>

                <div class="report-heading" style="margin-top: 30px;">
                    <div>
                        <h2>Match Results</h2>
                        <p>Match schedules and completed results</p>
                    </div>
                </div>

                <div class="report-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Match</th>
                                <th>Sport</th>
                                <th>Teams</th>
                                <th>Score</th>
                                <th>Date</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th>Result</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($matchPerformance)): ?>
                                <?php foreach ($matchPerformance as $match): ?>
                                    <?php
                                    $isCompleted = in_array(
                                        strtolower(trim($match['match_status'])),
                                        ['completed', 'finished'],
                                        true
                                    );
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($match['title']) ?></td>
                                        <td><?= htmlspecialchars($match['sport']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($match['home_name']) ?>
                                            vs
                                            <?= htmlspecialchars($match['away_name']) ?>
                                        </td>
                                        <td>
                                            <?= $isCompleted
                                                ? (int) $match['home_score'] . ' - ' . (int) $match['away_score']
                                                : '—' ?>
                                        </td>
                                        <td><?= htmlspecialchars($match['match_date']) ?></td>
                                        <td><?= htmlspecialchars($match['venue']) ?></td>
                                        <td><?= htmlspecialchars($match['match_status']) ?></td>
                                        <td><?= htmlspecialchars($match['result_summary']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-report">
                                        No match records available yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="report-note">
                    Showing up to 100 match records. Only completed matches
                    contribute to performance statistics.
                </p>
            </section>

        </main>

    </div>

</body>

</html>