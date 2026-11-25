<?php

function sportsHubEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sportsHubInput(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function sportsHubValidDate(string $value): bool
{
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function sportsHubLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $length = preg_match_all('/./us', $value);
    return $length !== false ? $length : strlen($value);
}

function sportsHubDisplayDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false ? $date->format('d M Y') : $value;
}

function sportsHubTournamentDate(?string $value): string
{
    return $value === null || $value === '' ? 'Not published' : sportsHubDisplayDate($value);
}

function sportsHubClubCover(string $sport): string
{
    $images = [
        'cricket' => '/assets/images/cricket.jpg',
        'badminton' => '/assets/images/badminton.jpg',
        'rugby' => '/assets/images/rugby.jpg',
        'athletics' => '/assets/images/athletics.jpg',
        'chess' => '/assets/images/chess.jpg',
        'martial arts' => '/assets/images/martialarts.jpg',
        'netball' => '/assets/images/netball.jpg',
        'swimming' => '/assets/images/swimming.jpg',
        'table tennis' => '/assets/images/tabletennis.jpg',
        'tennis' => '/assets/images/tennis.jpg',
        'volleyball' => '/assets/images/volleyball.jpg'
    ];
    return $images[strtolower(trim($sport))] ?? '';
}

function sportsHubCoverLibrary(): array
{
    $covers = ['' => 'Automatic sport photograph', '/assets/images/hero-main.webp' => 'General sports', '/assets/images/herobanner.png' => 'Basketball'];
    foreach (['Cricket', 'Badminton', 'Rugby', 'Athletics', 'Chess', 'Martial arts', 'Netball', 'Swimming', 'Table tennis', 'Tennis', 'Volleyball'] as $sport) {
        $covers[sportsHubClubCover($sport)] = $sport;
    }
    return $covers;
}

function sportsHubCustomCover(mixed $value): string
{
    return is_string($value) && array_key_exists($value, sportsHubCoverLibrary()) ? $value : '';
}

function sportsHubCoverOptions(string $selected): string
{
    $html = '';
    foreach (sportsHubCoverLibrary() as $path => $label) {
        $html .= '<option value="' . sportsHubEscape($path) . '"' . ($path === $selected ? ' selected' : '') . '>' . sportsHubEscape($label) . '</option>';
    }
    return $html;
}

function sportsHubIsCricket(string $sport): bool
{
    return str_contains(strtolower($sport), 'cricket');
}

function sportsHubOversText(mixed $balls): string
{
    return $balls === null ? '' : intdiv((int) $balls, 6) . '.' . ((int) $balls % 6);
}

function sportsHubOversBalls(string $value): int|null|false
{
    if ($value === '') return null;
    if (!preg_match('/^(\d{1,3})(?:\.([0-5]))?$/D', $value, $parts)) return false;
    return (int) $parts[1] * 6 + (int) ($parts[2] ?? 0);
}

function sportsHubMatchScore(array $match, string $side, bool $includeOvers = false): string
{
    $score = (string) (int) $match[$side . '_score'];
    if (!sportsHubIsCricket((string) $match['sport'])) return $score;
    $wickets = $match[$side . '_wickets'] ?? null;
    $balls = $match[$side . '_balls'] ?? null;
    if ($wickets !== null) $score .= '/' . (int) $wickets;
    if ($includeOvers && $balls !== null) $score .= ' (' . sportsHubOversText($balls) . ' ov)';
    return $score;
}

function sportsHubOfficialNews(): array
{
    return [
        [
            'title' => 'NSBM inter-faculty badminton tournament 2026 announces champions',
            'source' => 'https://www.nsbm.ac.lk/nsbm-inter-faculty-badminton-tournament-2026/',
            'image' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/08/nsbm-badminton-tournament-2026-2-1037x600.jpg',
        ],
        [
            'title' => 'NSBM Athletics Club tops higher-education category at Mercantile Athletic Championship 2026',
            'source' => 'https://www.nsbm.ac.lk/nsbm-athletics-club-crowned-champions-in-higher-education-category-at-41st-mercantile-athletic-championship-2026/',
            'image' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/08/nsbm-athletics-club-mercantile-athletic-championship-2026-3-1037x600.jpg',
        ],
        [
            'title' => 'NSBM Chess Club concludes Inter Batch 4.0 tournament',
            'source' => 'https://www.nsbm.ac.lk/nsbm-chess-club-hosts-nsbms-biggest-ever-inter-batch-chess-tournament/',
            'image' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-chess-club-inter-batch-chess-tournament1-1037x600.jpg',
        ],
        [
            'title' => 'NSBM cricket team wins the KDU Leaders’ Trophy T20 2026',
            'source' => 'https://www.nsbm.ac.lk/nsbm-cricket-team-crowned-champions-again-at-kdu-inter-university-t20-championship-2026/',
            'image' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-cricket-kdu-t20-champions-2026-1200x600.jpg',
        ],
        [
            'title' => 'NSBM men’s volleyball team takes UOC Vice Chancellor’s Challenge Trophy 2026',
            'source' => 'https://www.nsbm.ac.lk/nsbm-volleyball-team-emerges-champions-at-uoc-vice-chancellors-challenge-trophy-2026/',
            'image' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/10/nsbm-volleyball-champions-uoc-trophy-2026-3-1200x600.jpg',
        ],
    ];
}

function sportsHubNewsUpload(mixed $path): string
{
    $path = (string) ($path ?? '');
    return preg_match('~^/assets/uploads/club-news/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $path)
        ? $path : '';
}

// NULL retains the official cover fallback; an empty string explicitly removes it.
function sportsHubNewsCover(array $story, string $officialCover): string
{
    return ($story['image_path'] ?? null) === null
        ? $officialCover : sportsHubNewsUpload($story['image_path']);
}

/** Presentation-only cover imagery, matching the existing tournament directory. */
function sportsHubTournamentCover(array $tournament, ?bool &$official = null): string
{
    $officialTournamentImages = [
                'https://www.nsbm.ac.lk/inaugural-vice-chancellors-challenge-trophy-football-match/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2024/05/Vice-Chancellor-Challenge-Trophy-Football2-1037x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-celebrates-double-victories-at-inter-university-volleyball-championship-2024/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2024/11/NSBMInterUniversityVolleyballChampionship9.jpg',
                'https://www.nsbm.ac.lk/nsbm-sports-fiesta-2025-grand-finale/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/03/NSBM-Sports-Fiesta-2025-Grand-Finale-5-1037x600.jpg',
                'https://www.nsbm.ac.lk/inter-batch-chess-tournament-2025/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/07/521415419_1180613190769897_1301112117457178750_n-1200x600.jpg',
                'https://www.nsbm.ac.lk/inter-club-cricket-tournament-2025-organized-by-international-center-for-cultural-exchange/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/07/Inter-Club-Cricket-Tournament25-1-1037x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-athletic-club-wins-big-at-sliit-momentum-2025-inter-university-athletic-championship/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/09/NSBM-Athletic-Club-Wins-Big-at-SLIIT-Momentum-2025-1-1037x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-mens-volleyball-team-emerges-champions-at-sliit-dash-fiesta-2025/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/10/NSBM-Mens-Volleyball-Team-Emerges-Champions-at-SLIIT-Dash-Fiesta-2025-2-1200x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-sports-fiesta-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/03/NSBM-Sports-Fiesta-2026-16-1200x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-alumni-basketball-team-clinches-victory-at-vice-chancellors-challenge-trophy-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/08/nsbm-alumni-basketball-team-vice-chancellors-challenge-trophy-2026-8-1037x600.jpg',
                'https://www.nsbm.ac.lk/students-wellbeing-association-concludes-swa-champions-trophy-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-swa-champions-trophy-2026-5-1037x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-cricket-team-crowned-champions-again-at-kdu-inter-university-t20-championship-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-cricket-kdu-t20-champions-2026-1200x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-volleyball-team-emerges-champions-at-uoc-vice-chancellors-challenge-trophy-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/10/nsbm-volleyball-champions-uoc-trophy-2026-3-1200x600.jpg',
                'https://www.nsbm.ac.lk/nsbm-athletics-club-crowned-champions-in-higher-education-category-at-41st-mercantile-athletic-championship-2026/' =>
                    'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/08/nsbm-athletics-club-mercantile-athletic-championship-2026-3-1037x600.jpg',
            ];
    $tournamentSportImages = [
                'Athletics' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/09/NSBM-Athletic-Club-Wins-Big-at-SLIIT-Momentum-2025-1-1037x600.jpg',
                'Cricket' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-cricket-kdu-t20-champions-2026-1200x600.jpg',
                'Volleyball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/10/NSBM-Mens-Volleyball-Team-Emerges-Champions-at-SLIIT-Dash-Fiesta-2025-2-1200x600.jpg',
                'Chess' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/07/521415419_1180613190769897_1301112117457178750_n-1200x600.jpg',
                'Football' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2024/05/Vice-Chancellor-Challenge-Trophy-Football2-1037x600.jpg',
                'Basketball' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/06/Thrilling-Basketball-Showdown-of-NSBM-vs-University-of-Peradeniya-1200x600.jpg',
                'Multi-sport' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/03/NSBM-Sports-Fiesta-2026-16-1200x600.jpg',
                'Sports' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2026/09/nsbm-swa-champions-trophy-2026-5-1037x600.jpg',
                'Rugby' => 'https://d3c539pel8wzjz.cloudfront.net/wp-content/uploads/2025/03/NSBM-Rugby-Team-Emerges-as-Champions-of-Sabra-Super-7s-Rugby-Tournament-2025-6-1037x600.jpg',
            ];
    $source = trim((string)($tournament['source_url'] ?? ''));
    $sport = trim((string)($tournament['sport'] ?? 'Sports'));
    $custom = sportsHubCustomCover($tournament['image_path'] ?? null);
    $official = $custom === '' && isset($officialTournamentImages[$source]);
    if ($custom !== '') return $custom;
    return $officialTournamentImages[$source]
        ?? $tournamentSportImages[$sport]
        ?? $tournamentSportImages['Sports'];
}

function sportsHubRegistrationOpen(array $tournament, string $today): bool
{
    return strtolower(trim((string) $tournament['status'])) === 'upcoming'
        && (int) ($tournament['is_historical'] ?? 1) === 0
        && !empty($tournament['reg_open_at'])
        && !empty($tournament['reg_end_at'])
        && (string) $tournament['reg_open_at'] <= $today
        && (string) $tournament['reg_end_at'] >= $today
        && (string) $tournament['start_date'] > $today;
}

function sportsHubReportFilters(PDO $pdo, array $input): array
{
    $rawStartDate = is_string($input['start_date'] ?? null) ? trim($input['start_date']) : '';
    $rawEndDate = is_string($input['end_date'] ?? null) ? trim($input['end_date']) : '';

    $startDate = null;
    $endDate = null;
    $filterError = '';
    foreach (['start_date', 'end_date', 'sport', 'club_id'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) {
            $filterError = 'Invalid report filter.';
        }
    }

    $startDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rawStartDate) && sportsHubValidDate($rawStartDate)) ? $rawStartDate : null;
    $endDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rawEndDate) && sportsHubValidDate($rawEndDate)) ? $rawEndDate : null;

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

    $selectedClubId = filter_var(
        $input['club_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $selectedClubId = $selectedClubId !== false && $selectedClubId !== null
        ? (int) $selectedClubId
        : 0;

    $selectedSport = is_string($input['sport'] ?? null) ? trim($input['sport']) : '';

    $filterClubs = $pdo->query("
        SELECT club_id, club_name
        FROM sports_clubs
        WHERE status = 'active'
        ORDER BY club_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $filterSports = $pdo->query("
        SELECT sport
        FROM sports_clubs
        WHERE status = 'active'

        UNION

        SELECT DISTINCT sport
        FROM matches
        WHERE sport <> ''

        UNION

        SELECT DISTINCT sport
        FROM tournaments
        WHERE sport <> ''

        ORDER BY sport
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (isset($input['club_id']) && $input['club_id'] !== '' && $selectedClubId <= 0) {
        $filterError = 'Please select a valid sports club.';
    }
    if ($selectedClubId > 0 && !in_array(
        $selectedClubId,
        array_map('intval', array_column($filterClubs, 'club_id')),
        true
    )) {
        $filterError = 'The selected sports club is not available.';
    }
    if ($selectedSport !== '' && !in_array($selectedSport, $filterSports, true)) {
        $filterError = 'The selected sport is not available.';
    }
    if ($filterError !== '') {
        $startDate = null;
        $endDate = null;
        $selectedClubId = 0;
        $selectedSport = '';
    }

    return compact('rawStartDate', 'rawEndDate', 'startDate', 'endDate', 'filterError',
        'selectedClubId', 'selectedSport', 'filterClubs', 'filterSports');
}

function sportsHubReportQueries(array $filters): array
{
    $startDate = $filters['startDate'];
    $endDate = $filters['endDate'];
    $selectedClubId = $filters['selectedClubId'];
    $selectedSport = $filters['selectedSport'];
    $makeScope = static function (
        string $dateColumn,
        ?string $clubColumn,
        ?string $sportColumn,
        string $prefix
    ) use ($startDate, $endDate, $selectedClubId, $selectedSport): array {
        $conditions = [];
        $params = [];

        if ($startDate !== null) {
            $key = $prefix . '_from';
            $conditions[] = "$dateColumn >= :$key";
            $params[$key] = $startDate;
        }
        if ($endDate !== null) {
            $key = $prefix . '_to';
            $conditions[] = "$dateColumn <= :$key";
            $params[$key] = $endDate;
        }
        if ($clubColumn !== null && $selectedClubId > 0) {
            $key = $prefix . '_club';
            $conditions[] = "$clubColumn = :$key";
            $params[$key] = $selectedClubId;
        }
        if ($sportColumn !== null && $selectedSport !== '') {
            $key = $prefix . '_sport';
            $conditions[] = "$sportColumn = :$key";
            $params[$key] = $selectedSport;
        }

        return [$conditions, $params];
    };

    $hasFilters = $startDate !== null || $endDate !== null
        || $selectedClubId > 0 || $selectedSport !== '';
    $facilityScopedOut = $selectedClubId > 0 || $selectedSport !== '';
    $facilityCount = $facilityScopedOut ? 'NULL' : 'COALESCE(bc.total, 0)';
    $queries = [];

    // Each aggregate is calculated per student before joining. This avoids
    // multiplying counts across unrelated one-to-many relationships.
    [$memberWhere, $memberParams] = $makeScope(
        'cm.joined_date', 'sc.club_id', 'sc.sport', 'member'
    );
    $memberConditions = array_merge(["cm.status = 'approved'"], $memberWhere);
    $memberAgg = "
        SELECT cm.user_id, COUNT(DISTINCT cm.club_id) AS total
        FROM club_membership cm
        JOIN sports_clubs sc ON sc.club_id = cm.club_id
        WHERE " . implode(' AND ', $memberConditions) . "
        GROUP BY cm.user_id
    ";

    [$trainingWhere, $trainingParams] = $makeScope(
        'ts.date', 'sc.club_id', 'sc.sport', 'training'
    );
    $trainingConditions = array_merge(["ta.att_status = 'present'"], $trainingWhere);
    $trainingAgg = "
        SELECT ta.user_id, COUNT(DISTINCT ta.session_id) AS total
        FROM training_attendance ta
        JOIN training_sessions ts ON ts.session_id = ta.session_id
        JOIN sports_clubs sc ON sc.club_id = ts.club_id
        WHERE " . implode(' AND ', $trainingConditions) . "
        GROUP BY ta.user_id
    ";

    [$tourneyWhere, $tourneyParams] = $makeScope(
        'tr.reg_date', 't.club_id', 't.sport', 'tourney'
    );
    $tourneyAgg = "
        SELECT tr.user_id, COUNT(DISTINCT tr.t_id) AS total
        FROM tournament_registration tr
        JOIN tournaments t ON t.t_id = tr.t_id
        " . ($tourneyWhere ? 'WHERE ' . implode(' AND ', $tourneyWhere) : '') . "
        GROUP BY tr.user_id
    ";

    $bookingParams = [];
    if ($facilityScopedOut) {
        // Booking requests are associated with facilities, not sports clubs.
        $bookingAgg = 'SELECT br.user_id, COUNT(*) AS total'
            . ' FROM booking_request br WHERE 1 = 0 GROUP BY br.user_id';
    } else {
        [$bookingWhere, $bookingParams] = $makeScope(
            'br.date', null, null, 'booking'
        );
        $bookingAgg = "
            SELECT br.user_id, COUNT(DISTINCT br.b_id) AS total
            FROM booking_request br
            " . ($bookingWhere ? 'WHERE ' . implode(' AND ', $bookingWhere) : '') . "
            GROUP BY br.user_id
        ";
    }

    $participationParams = array_merge(
        $memberParams, $trainingParams, $tourneyParams, $bookingParams
    );
    $participationFrom = "
        FROM users u
        LEFT JOIN ($memberAgg) mc ON mc.user_id = u.user_id
        LEFT JOIN ($trainingAgg) ac ON ac.user_id = u.user_id
        LEFT JOIN ($tourneyAgg) tc ON tc.user_id = u.user_id
        LEFT JOIN ($bookingAgg) bc ON bc.user_id = u.user_id
        WHERE u.role = 'student'
    ";
    $studentActivity = 'COALESCE(mc.total, 0) + COALESCE(ac.total, 0)'
        . ' + COALESCE(tc.total, 0) + COALESCE(bc.total, 0)';
    if ($hasFilters) {
        $participationFrom .= " AND ($studentActivity) > 0";
    }

    $queries['summary'] = ["
        SELECT
            COUNT(*) AS students_in_report,
            COALESCE(SUM(mc.total), 0) AS total_club_participation,
            COALESCE(SUM(ac.total), 0) AS total_training_attendance,
            COALESCE(SUM(tc.total), 0) AS total_tournament_registrations,
            COALESCE(SUM(bc.total), 0) AS total_facility_bookings
        $participationFrom
    ", $participationParams];

    $queries['students'] = ["
        SELECT u.user_id, u.name AS student_name, u.email,
               COALESCE(mc.total, 0) AS total_clubs_joined,
               COALESCE(ac.total, 0) AS training_sessions_attended,
               COALESCE(tc.total, 0) AS tournaments_registered,
               $facilityCount AS facility_bookings_made
        $participationFrom
        ORDER BY u.name ASC, u.user_id ASC
    ", $participationParams];

    // Club membership approval dates can be filtered, but pending requests have
    // no request date column and therefore remain a current snapshot.
    [$clubScope, $clubParams] = $makeScope(
        'cm.joined_date', null, null, 'club_membership'
    );
    $approvedDate = $clubScope ? ' AND ' . implode(' AND ', $clubScope) : '';
    $clubFilterWhere = ["sc.status = 'active'"];
    if ($selectedClubId > 0) {
        $clubFilterWhere[] = 'sc.club_id = :club_filter_id';
        $clubParams['club_filter_id'] = $selectedClubId;
    }
    if ($selectedSport !== '') {
        $clubFilterWhere[] = 'sc.sport = :club_filter_sport';
        $clubParams['club_filter_sport'] = $selectedSport;
    }
    $queries['clubs'] = ["
        SELECT sc.club_name, sc.sport,
               COUNT(DISTINCT CASE WHEN cm.status = 'approved'
                   $approvedDate THEN cm.user_id END) AS approved_members,
               COUNT(DISTINCT CASE WHEN cm.status = 'pending'
                   THEN cm.user_id END) AS pending_members
        FROM sports_clubs sc
        LEFT JOIN club_membership cm ON cm.club_id = sc.club_id
        WHERE " . implode(' AND ', $clubFilterWhere) . "
        GROUP BY sc.club_id, sc.club_name, sc.sport
        ORDER BY sc.club_name ASC
    ", $clubParams];

    [$attendanceWhere, $attendanceParams] = $makeScope(
        'ts.date', 'sc.club_id', 'sc.sport', 'attendance'
    );
    $attendanceConditions = array_merge(["u.role = 'student'"], $attendanceWhere);
    $queries['training'] = ["
        SELECT u.name AS student_name, ts.title AS session_title,
               sc.club_name, sc.sport, ts.date AS session_date, ta.att_status
        FROM training_attendance ta
        JOIN users u ON u.user_id = ta.user_id
        JOIN training_sessions ts ON ts.session_id = ta.session_id
        JOIN sports_clubs sc ON sc.club_id = ts.club_id
        WHERE " . implode(' AND ', $attendanceConditions) . "
        ORDER BY ts.date DESC, u.name ASC, ts.session_id DESC, u.user_id ASC
    ", $attendanceParams];

    // Matches belong to a club only when tied to a tournament; independent
    // fixtures remain available when filtering by sport or date alone.
    [$matchWhere, $matchParams] = $makeScope(
        'm.date', 't.club_id', 'm.sport', 'match'
    );
    $matchConditions = $matchWhere ? 'WHERE ' . implode(' AND ', $matchWhere) : '';
    $matchFrom = "FROM matches m LEFT JOIN tournaments t ON t.t_id = m.t_id";
    $completedCondition = "LOWER(TRIM(m.match_status)) IN ('completed', 'finished')";
    $performanceWhere = $matchConditions
        ? $matchConditions . ' AND ' . $completedCondition
        : 'WHERE ' . $completedCondition;

    $queries['performance'] = ["
        SELECT COUNT(*) AS completed_matches,
               COALESCE(SUM(CASE WHEN m.home_score > m.away_score THEN 1 ELSE 0 END), 0)
                   AS home_wins,
               COALESCE(SUM(CASE WHEN m.away_score > m.home_score THEN 1 ELSE 0 END), 0)
                   AS away_wins,
               COALESCE(SUM(CASE WHEN m.home_score = m.away_score THEN 1 ELSE 0 END), 0)
                   AS draws
        $matchFrom
        $performanceWhere
    ", $matchParams];

    $queries['matches'] = ["
        SELECT m.title, m.sport, m.home_name, m.away_name,
               m.date AS match_date, m.venue, m.match_status,
               m.home_score, m.away_score,
               CASE WHEN $completedCondition THEN
                   CASE
                       WHEN m.home_score > m.away_score THEN CONCAT(m.home_name, ' won')
                       WHEN m.away_score > m.home_score THEN CONCAT(m.away_name, ' won')
                       ELSE 'Draw'
                   END
               ELSE 'Not played' END AS result_summary
        $matchFrom
        $matchConditions
        ORDER BY m.date DESC, m.m_id DESC
    ", $matchParams];

    $registrationConditions = array_merge(["u.role = 'student'"], $tourneyWhere);
    $queries['tournaments'] = ["
        SELECT t.t_name AS tournament_name, t.sport, sc.club_name,
               u.name AS student_name, tr.reg_date, tr.registration_status
        FROM tournament_registration tr
        JOIN tournaments t ON t.t_id = tr.t_id
        LEFT JOIN sports_clubs sc ON sc.club_id = t.club_id
        JOIN users u ON u.user_id = tr.user_id
        WHERE " . implode(' AND ', $registrationConditions) . "
        ORDER BY tr.reg_date DESC, u.name ASC, tr.reg_id DESC
    ", $tourneyParams];

    [$tournamentScheduleWhere, $tournamentScheduleParams] = $makeScope('t.start_date', 't.club_id', 't.sport', 'tournament_schedule');
    $queries['tournament-schedule'] = [
        'SELECT t.t_name, t.sport, t.start_date AS event_date, t.end_date, t.status, t.venue, t.organizer_name, t.source_url FROM tournaments t'
        . ($tournamentScheduleWhere ? ' WHERE ' . implode(' AND ', $tournamentScheduleWhere) : '')
        . ' ORDER BY t.start_date DESC, t.t_id DESC',
        $tournamentScheduleParams
    ];

    [$trainingScheduleWhere, $trainingScheduleParams] = $makeScope('ts.date', 'ts.club_id', 'sc.sport', 'training_schedule');
    $queries['training-schedule'] = [
        'SELECT ts.date AS event_date, ts.title, sc.club_name, sc.sport, ts.start_time, ts.end_time, r.res_name AS facility FROM training_sessions ts JOIN sports_clubs sc ON sc.club_id = ts.club_id LEFT JOIN resources r ON r.res_id = ts.location'
        . ($trainingScheduleWhere ? ' WHERE ' . implode(' AND ', $trainingScheduleWhere) : '')
        . ' ORDER BY ts.date DESC, ts.session_id DESC',
        $trainingScheduleParams
    ];

    [$bookingsWhere, $bookingsParams] = $makeScope('br.date', null, null, 'bookings');
    if ($facilityScopedOut) {
        $bookingsWhere[] = '1 = 0';
    }
    $queries['bookings'] = [
        'SELECT br.date AS event_date, u.name AS student, u.email, r.res_name AS facility, br.starts_at, br.ends_at, br.booking_status, br.purpose FROM booking_request br JOIN users u ON br.user_id = u.user_id JOIN resources r ON br.res_id = r.res_id'
        . ($bookingsWhere ? ' WHERE ' . implode(' AND ', $bookingsWhere) : '')
        . ' ORDER BY br.date DESC, br.b_id DESC',
        $bookingsParams
    ];

    return $queries;
}

function sportsHubReportRows(PDO $pdo, array $query, int $limit = 0): PDOStatement
{
    [$sql, $params] = $query;
    $stmt = $pdo->prepare($sql . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    $stmt->execute($params);
    return $stmt;
}

function sportsHubAdminSidebar(string $active): void
{
    $links = [
        'dashboard' => ['/admin/dashboard.php', 'Dashboard'],
        'manage' => ['/admin/manage.php', 'Manage Content'],
        'members' => ['/clubs/admin/members.php', 'Club Memberships'],
        'clubs' => ['/clubs/', 'Sports Clubs'],
        'training' => ['/training/', 'Training Sessions'],
        'tournaments' => ['/tournaments/', 'Tournaments'],
        'matches' => ['/matches/', 'Matches'],
        'bookings' => ['/bookings/', 'Facilities & Bookings'],
        'reports' => ['/reports/', 'Reports & Analytics'],
    ];
    ?>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <a class="admin-site-brand-link" href="/index.php" title="View website">NSBM SportsHub</a>
        </div>
        <nav class="sidebar-nav" aria-label="Administration">
            <?php foreach ($links as $key => [$href, $label]): ?>
                <a href="<?= sportsHubEscape($href) ?>" class="nav-link<?= $active === $key ? ' active' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= sportsHubEscape($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-account-section">
            <form action="/logout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($_SESSION['logout_csrf']) ?>">
                <button type="submit">Log out</button>
            </form>
        </div>
    </aside>
    <?php
}
