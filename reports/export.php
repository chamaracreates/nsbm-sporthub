<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';

$pdo = db();

// Validate report type
$reportType = trim((string) ($_GET['type'] ?? ''));

$allowedReports = [
    'students',
    'clubs',
    'training',
    'tournaments',
    'matches'
];

if (!in_array($reportType, $allowedReports, true)) {
    http_response_code(400);
    exit('Invalid report type.');
}

// Validate date filters
$rawStartDate = trim((string) ($_GET['start_date'] ?? ''));
$rawEndDate = trim((string) ($_GET['end_date'] ?? ''));

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

if (
    ($rawStartDate !== '' && $startDate === null) ||
    ($rawEndDate !== '' && $endDate === null) ||
    ($startDate !== null && $endDate !== null && $startDate > $endDate)
) {
    http_response_code(400);
    exit('Invalid date filters.');
}

// Validate club and sport filters
$selectedClubId = filter_var(
    $_GET['club_id'] ?? '',
    FILTER_VALIDATE_INT
);

if ($selectedClubId === false || $selectedClubId === null) {
    $selectedClubId = 0;
}

$selectedClubId = max(0, (int) $selectedClubId);

$selectedSport = trim((string) ($_GET['sport'] ?? ''));

// Preserve active filters when exporting reports
$exportFilters = [];

if ($startDate !== null) {
    $exportFilters['start_date'] = $startDate;
}

if ($endDate !== null) {
    $exportFilters['end_date'] = $endDate;
}

if ($selectedClubId > 0) {
    $exportFilters['club_id'] = $selectedClubId;
}

if ($selectedSport !== '') {
    $exportFilters['sport'] = $selectedSport;
}

// Generate export URLs
$studentExportUrl = 'export.php?' . http_build_query(
    ['type' => 'students'] + $exportFilters
);

$clubExportUrl = 'export.php?' . http_build_query(
    ['type' => 'clubs'] + $exportFilters
);

$trainingExportUrl = 'export.php?' . http_build_query(
    ['type' => 'training'] + $exportFilters
);

$tournamentExportUrl = 'export.php?' . http_build_query(
    ['type' => 'tournaments'] + $exportFilters
);

$matchExportUrl = 'export.php?' . http_build_query(
    ['type' => 'matches'] + $exportFilters
);

// Prepare database parameters
$params = [];


// Student participation export
if ($reportType === 'students') {

    $sql = "
        SELECT
            u.user_id,
            u.name AS student_name,
            u.email,

            (
                SELECT COUNT(DISTINCT cm.club_id)
                FROM club_membership cm
                INNER JOIN sports_clubs sc
                    ON sc.club_id = cm.club_id
                WHERE cm.user_id = u.user_id
                  AND cm.status = 'approved'
    ";

    if ($startDate !== null) {
        $sql .= " AND cm.joined_date >= :club_start";
        $params['club_start'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND cm.joined_date <= :club_end";
        $params['club_end'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $sql .= " AND sc.club_id = :club_id";
        $params['club_id'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND sc.sport = :club_sport";
        $params['club_sport'] = $selectedSport;
    }

    $sql .= "
            ) AS total_clubs_joined,

            (
                SELECT COUNT(*)
                FROM training_attendance ta
                INNER JOIN training_sessions ts
                    ON ts.session_id = ta.session_id
                INNER JOIN sports_clubs sc
                    ON sc.club_id = ts.club_id
                WHERE ta.user_id = u.user_id
    ";

    if ($startDate !== null) {
        $sql .= " AND ts.date >= :training_start";
        $params['training_start'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND ts.date <= :training_end";
        $params['training_end'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $sql .= " AND sc.club_id = :training_club";
        $params['training_club'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND sc.sport = :training_sport";
        $params['training_sport'] = $selectedSport;
    }

    $sql .= "
            ) AS training_sessions_attended,

            (
                SELECT COUNT(*)
                FROM tournament_registration tr
                INNER JOIN tournaments t
                    ON t.t_id = tr.t_id
                WHERE tr.user_id = u.user_id
                  AND tr.registration_status = 'approved'
    ";

    if ($startDate !== null) {
        $sql .= " AND tr.reg_date >= :tournament_start";
        $params['tournament_start'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND tr.reg_date <= :tournament_end";
        $params['tournament_end'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $sql .= " AND t.club_id = :tournament_club";
        $params['tournament_club'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND t.sport = :tournament_sport";
        $params['tournament_sport'] = $selectedSport;
    }

    $sql .= "
            ) AS tournaments_registered,

            (
                SELECT COUNT(*)
                FROM booking_request br
                WHERE br.user_id = u.user_id
    ";

    if ($startDate !== null) {
        $sql .= " AND br.date >= :booking_start";
        $params['booking_start'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND br.date <= :booking_end";
        $params['booking_end'] = $endDate;
    }

    $sql .= "
            ) AS facility_bookings_made

        FROM users u
        WHERE u.role = 'student'
        ORDER BY u.name ASC
    ";
}


// Club membership export
if ($reportType === 'clubs') {

    $sql = "
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
    ";

    if ($selectedClubId > 0) {
        $sql .= " AND sc.club_id = :club_id";
        $params['club_id'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND sc.sport = :sport";
        $params['sport'] = $selectedSport;
    }

    $sql .= "
        GROUP BY sc.club_id, sc.club_name, sc.sport
        ORDER BY sc.club_name ASC
    ";
}


// Training attendance export
if ($reportType === 'training') {

    $sql = "
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
    ";

    if ($startDate !== null) {
        $sql .= " AND ts.date >= :start_date";
        $params['start_date'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND ts.date <= :end_date";
        $params['end_date'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $sql .= " AND sc.club_id = :club_id";
        $params['club_id'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND sc.sport = :sport";
        $params['sport'] = $selectedSport;
    }

    $sql .= "
        ORDER BY ts.date DESC, u.name ASC
    ";
}


// Tournament participation export
if ($reportType === 'tournaments') {

    $sql = "
        SELECT
            t.t_name AS tournament_name,
            t.sport,
            sc.club_name,
            u.name AS student_name,
            tr.reg_date,
            tr.registration_status
        FROM tournament_registration tr
        INNER JOIN tournaments t
            ON t.t_id = tr.t_id
        INNER JOIN sports_clubs sc
            ON sc.club_id = t.club_id
        INNER JOIN users u
            ON u.user_id = tr.user_id
        WHERE u.role = 'student'
    ";

    if ($startDate !== null) {
        $sql .= " AND tr.reg_date >= :tournament_start";
        $params['tournament_start'] = $startDate;
    }

    if ($endDate !== null) {
        $sql .= " AND tr.reg_date <= :tournament_end";
        $params['tournament_end'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $sql .= " AND t.club_id = :club_id";
        $params['club_id'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $sql .= " AND t.sport = :sport";
        $params['sport'] = $selectedSport;
    }

    $sql .= "
        ORDER BY tr.reg_date DESC, u.name ASC
    ";
}


// Match performance export
if ($reportType === 'matches') {

    $matchWhere = [];

    if ($startDate !== null) {
        $matchWhere[] = "m.date >= :start_date";
        $params['start_date'] = $startDate;
    }

    if ($endDate !== null) {
        $matchWhere[] = "m.date <= :end_date";
        $params['end_date'] = $endDate;
    }

    if ($selectedClubId > 0) {
        $matchWhere[] = "t.club_id = :club_id";
        $params['club_id'] = $selectedClubId;
    }

    if ($selectedSport !== '') {
        $matchWhere[] = "m.sport = :sport";
        $params['sport'] = $selectedSport;
    }

    $matchFilterSql = $matchWhere
        ? " AND " . implode(" AND ", $matchWhere)
        : "";

    $sql = "
        SELECT
            m.title,
            m.sport,
            m.home_name,
            m.away_name,
            m.date AS match_date,
            m.venue,
            m.match_status,
            m.home_score,
            m.away_score,
            CASE
                WHEN LOWER(TRIM(m.match_status))
                    IN ('completed', 'finished')
                THEN
                    CASE
                        WHEN m.home_score > m.away_score
                            THEN CONCAT(m.home_name, ' won')
                        WHEN m.away_score > m.home_score
                            THEN CONCAT(m.away_name, ' won')
                        ELSE 'Draw'
                    END
                ELSE 'Not played'
            END AS result_summary
        FROM matches m
        LEFT JOIN tournaments t
            ON t.t_id = m.t_id
        WHERE 1=1
        " . $matchFilterSql . "
        ORDER BY m.date DESC, m.m_id DESC
    ";
}


// CSV column headings for each report type
$reportHeaders = [
    'students' => [
        'user_id',
        'student_name',
        'email',
        'total_clubs_joined',
        'training_sessions_attended',
        'tournaments_registered',
        'facility_bookings_made'
    ],
    'clubs' => [
        'club_name',
        'sport',
        'approved_members',
        'pending_members'
    ],
    'training' => [
        'student_name',
        'session_title',
        'club_name',
        'sport',
        'session_date',
        'att_status'
    ],
    'tournaments' => [
        'tournament_name',
        'sport',
        'club_name',
        'student_name',
        'reg_date',
        'registration_status'
    ],
    'matches' => [
        'title',
        'sport',
        'home_name',
        'away_name',
        'match_date',
        'venue',
        'match_status',
        'home_score',
        'away_score',
        'result_summary'
    ]
];


// Execute selected report query
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate filename
    $filename = 'nsbm_' . $reportType . '_report_' . date('Y-m-d') . '.csv';

    // Send CSV download headers
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Open output stream
    $output = fopen('php://output', 'w');

    // UTF-8 BOM for spreadsheet compatibility
    fwrite($output, "\xEF\xBB\xBF");

    // Column headings (always include them, even when there are no records)
    $csvHeaders = $reportHeaders[$reportType];

    if (!empty($csvHeaders)) {
        fputcsv($output, $csvHeaders);
    }

    foreach ($rows as $row) {
        $safeRow = [];

        foreach ($row as $value) {
            $value = (string) ($value ?? '');

            // Prevent spreadsheet formula injection
            if (preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }

            $safeRow[] = $value;
        }

        fputcsv($output, $safeRow);
    }

    fclose($output);
    exit;
} catch (PDOException $exception) {
    error_log('Report CSV export error: ' . $exception->getMessage());

    http_response_code(500);
    exit('Unable to generate the report.');
}
