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
$filters = sportsHubReportFilters($pdo, $_GET);
$rawStartDate = $filters['rawStartDate'];
$rawEndDate = $filters['rawEndDate'];
$startDate = $filters['startDate'];
$endDate = $filters['endDate'];
$filterError = $filters['filterError'];
$selectedClubId = $filters['selectedClubId'];
$selectedSport = $filters['selectedSport'];
$filterClubs = $filters['filterClubs'];
$filterSports = $filters['filterSports'];
$facilityScopedOut = $selectedClubId > 0 || $selectedSport !== '';
$queries = sportsHubReportQueries($filters);
$summary = sportsHubReportRows($pdo, $queries['summary'])->fetch(PDO::FETCH_ASSOC);
$studentParticipation = sportsHubReportRows($pdo, $queries['students'], 50)->fetchAll(PDO::FETCH_ASSOC);
$clubMemberships = sportsHubReportRows($pdo, $queries['clubs'])->fetchAll(PDO::FETCH_ASSOC);
$activeClubs = count($clubMemberships);
$trainingAttendance = sportsHubReportRows($pdo, $queries['training'], 100)->fetchAll(PDO::FETCH_ASSOC);
$tournamentParticipation = sportsHubReportRows($pdo, $queries['tournaments'], 100)->fetchAll(PDO::FETCH_ASSOC);
$matchPerformanceSummary = sportsHubReportRows($pdo, $queries['performance'])->fetch(PDO::FETCH_ASSOC);
$matchPerformance = sportsHubReportRows($pdo, $queries['matches'], 100)->fetchAll(PDO::FETCH_ASSOC);
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

                <a href="../clubs/admin/members.php" class="nav-link">
                    Club Memberships
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

                <div class="admin-account-actions">
                    <a class="admin-home-link" href="/index.php">View website</a>
                    <span class="admin-profile">Administrator</span>
                    <form class="admin-logout-form" action="/logout.php" method="post">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <button class="admin-logout-button" type="submit">Log out</button>
                    </form>
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
                        Filters were not applied; showing the unfiltered report.
                    </p>

                <?php endif; ?>

                <?php
                $reportFilters = [
                    'start_date' => $startDate ?? '',
                    'end_date' => $endDate ?? '',
                    'sport' => $selectedSport,
                    'club_id' => $selectedClubId > 0 ? $selectedClubId : ''
                ];
                $exports = [
                    'students' => 'Student participation',
                    'clubs' => 'Club memberships',
                    'training' => 'Training attendance',
                    'tournaments' => 'Tournament registrations',
                    'matches' => 'Match results',
                    'training-schedule' => 'Training schedule',
                    'tournament-schedule' => 'Tournament schedule'
                ];
                if (!$facilityScopedOut) {
                    $exports['bookings'] = 'Facility bookings';
                }
                ?>
                <div class="report-export-links" role="group" aria-label="CSV exports for applied filters">
                    <?php foreach ($exports as $type => $label): ?>
                        <a class="filter-button report-export-button" href="<?= sportsHubEscape('export.php?' . http_build_query(['type' => $type] + $reportFilters)) ?>"><?= sportsHubEscape($label) ?> CSV</a>
                    <?php endforeach; ?>
                </div>
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
                <p class="report-note">
                    Date filters apply to club approval dates, training session dates,
                    tournament registration dates, facility request dates and match dates.
                    Pending club requests remain current (no request date is stored).
                    Facility requests are not attributable to a club or sport.
                    Club-specific match results include tournament-linked matches only. Tournament schedule exports use event start dates.
                </p>
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
                        <h3>Facility Requests</h3>
                        <p class="stat-number">
                            <?= $facilityScopedOut
                                ? '—'
                                : number_format((int) $summary['total_facility_bookings']) ?>
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
                                <th>Facility Requests</th>
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
                                            <?= $facilityScopedOut ? '—' : number_format((int) $student['facility_bookings_made']) ?>
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
                    Showing up to 50 student records. Club participations count approved memberships,
                    training attendance counts present records, and registrations/requests include all statuses.
                    Facility requests display as — when filtered by club or sport.
                </p>

            </section>

            <section class="report-section">
                <div class="report-heading">
                    <div>
                        <h2>Tournament Participation Report</h2>
                        <p>Student registrations across tournaments</p>
                    </div>
                </div>
                <div class="report-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Tournament</th>
                                <th>Sport</th>
                                <th>Sports Club</th>
                                <th>Student</th>
                                <th>Registration Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tournamentParticipation as $registration): ?>
                                <tr>
                                    <td><?= sportsHubEscape($registration['tournament_name']) ?></td>
                                    <td><?= sportsHubEscape($registration['sport']) ?></td>
                                    <td><?= sportsHubEscape($registration['club_name'] ?? '—') ?></td>
                                    <td><?= sportsHubEscape($registration['student_name']) ?></td>
                                    <td><?= sportsHubEscape($registration['reg_date']) ?></td>
                                    <td><?= sportsHubEscape(ucfirst($registration['registration_status'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$tournamentParticipation): ?>
                                <tr><td colspan="6" class="empty-report">No tournament participation records available.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="report-note">Showing up to 100 tournament registrations.</p>
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
                <p class="report-note">
                    Approved memberships are filtered by their join/approval date.
                    Pending requests are a current snapshot because the database has no submission date for them.
                </p>
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
                                            <?php if ($isCompleted && strcasecmp((string) $match['sport'], 'Volleyball') === 0): ?>
                                                <small>(sets)</small>
                                            <?php endif; ?>
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