<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$type = $_GET['type'] ?? '';
if (!is_string($type) || !in_array($type, [
    'students', 'clubs', 'training', 'tournaments', 'matches',
    'training-schedule', 'tournament-schedule', 'bookings', 'match-registrations'
], true)) {
    http_response_code(400);
    exit('Invalid report type.');
}

try {
    $pdo = db();
    $filters = sportsHubReportFilters($pdo, $_GET);
    if ($filters['filterError'] !== '') {
        http_response_code(400);
        exit('Invalid report filters.');
    }
    if ($type === 'bookings' && ($filters['selectedClubId'] > 0 || $filters['selectedSport'] !== '')) {
        http_response_code(400);
        exit('Bookings cannot be filtered by sport or club.');
    }
    $queries = sportsHubReportQueries($filters);
    $stmt = sportsHubReportRows($pdo, $queries[$type], 10001);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 10000) {
        http_response_code(413);
        exit('Report exceeds 10,000 rows. Select a narrower date range.');
    }
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $columns[] = $stmt->getColumnMeta($i)['name'];
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sportshub-' . $type . '-report.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $columns, ',', '"', '');
    foreach ($rows as $row) {
        foreach ($row as &$value) {
            $value = (string) ($value ?? '');
            if (preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, array_values($row), ',', '"', '');
    }
    fclose($output);
} catch (Throwable $error) {
    error_log('SportsHub report export failed: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit('Unable to export report.');
}
