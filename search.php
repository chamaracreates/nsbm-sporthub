<?php
/** Search publicly accessible content across the supported modules. */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

require_once __DIR__ . '/includes/helpers.php';

$term = $_GET['q'] ?? '';
$term = is_string($term) ? trim($term) : '';
if (function_exists('mb_substr')) {
    $term = mb_substr($term, 0, 80, 'UTF-8');
} else {
    if (preg_match('/^.{0,80}/us', $term, $match)) {
        $term = $match[0];
    } else {
        $term = substr($term, 0, 80);
    }
}
$minLength = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : (preg_match_all('/./us', $term) ?: 0);
$searched = $minLength >= 2;
$results = [];
$failedModules = [];
$sectionCount = 0;
$pages = [
    ['label' => 'Sports clubs', 'url' => '/clubs/index.php', 'keywords' => 'clubs sports teams join membership'],
    ['label' => 'Tournaments', 'url' => '/tournaments/index.php', 'keywords' => 'tournaments competitions results'],
    ['label' => 'Fixtures', 'url' => '/matches/index.php', 'keywords' => 'matches fixtures scores standings'],
    ['label' => 'Training', 'url' => '/training/index.php', 'keywords' => 'training sessions practice coaching'],
    ['label' => 'Facilities', 'url' => '/bookings/index.php', 'keywords' => 'facilities resources equipment booking'],
    ['label' => 'Club news', 'url' => '/index.php#club-spotlight', 'keywords' => 'club news stories achievements announcements'],
];

if ($searched) {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
    foreach ($pages as $page) {
        $haystack = strtolower($page['label'] . ' ' . $page['keywords']);
        if (str_contains($haystack, $needle)) {
            $results['Pages'][] = ['title' => $page['label'], 'detail' => 'SportsHub section', 'url' => $page['url']];
        }
    }
    // ESCAPE makes literal % and _ searchable; placeholders avoid SQL interpolation.
    $like = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $catalogues = [
        'Sports clubs' => [
            'sql' => "SELECT club_id, club_name, sport FROM sports_clubs WHERE status = 'active' AND (club_name LIKE ? ESCAPE '!' OR sport LIKE ? ESCAPE '!') ORDER BY club_name LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['club_name'], 'detail' => $r['sport'], 'url' => '/clubs/view.php?id=' . (int) $r['club_id']],
            'params' => 2,
        ],
        'Tournaments' => [
            'sql' => "SELECT t.t_id, t.t_name, t.sport FROM tournaments t LEFT JOIN sports_clubs sc ON sc.club_id=t.club_id WHERE (sc.status='active' OR t.is_historical=1) AND LOWER(TRIM(t.status)) <> 'draft' AND (t.t_name LIKE ? ESCAPE '!' OR t.sport LIKE ? ESCAPE '!') ORDER BY t.start_date DESC LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['t_name'], 'detail' => $r['sport'], 'url' => '/tournaments/view.php?id=' . (int) $r['t_id']],
            'params' => 2,
        ],
        'Fixtures' => [
            'sql' => "SELECT m_id, title, sport, home_name, away_name FROM matches WHERE (title LIKE ? ESCAPE '!' OR sport LIKE ? ESCAPE '!' OR home_name LIKE ? ESCAPE '!' OR away_name LIKE ? ESCAPE '!') ORDER BY date DESC LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['title'], 'detail' => $r['home_name'] . ' vs ' . $r['away_name'] . ' · ' . $r['sport'], 'url' => '/matches/view.php?id=' . (int) $r['m_id']],
            'params' => 4,
        ],
        'Training' => [
            'sql' => "SELECT ts.title, sc.club_name FROM training_sessions ts JOIN sports_clubs sc ON ts.club_id = sc.club_id AND sc.status = 'active' WHERE (ts.title LIKE ? ESCAPE '!' OR sc.club_name LIKE ? ESCAPE '!') ORDER BY ts.date DESC LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['title'], 'detail' => 'Training · ' . $r['club_name'], 'url' => '/training/index.php'],
            'params' => 2,
        ],
        'Facilities' => [
            'sql' => "SELECT res_name, res_type, location FROM resources WHERE LOWER(TRIM(res_status)) = 'active' AND (res_name LIKE ? ESCAPE '!' OR res_type LIKE ? ESCAPE '!' OR location LIKE ? ESCAPE '!') ORDER BY res_name LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['res_name'], 'detail' => $r['res_type'] . ' · ' . ($r['location'] ?? ''), 'url' => '/bookings/index.php'],
            'params' => 3,
        ],
        'Club news' => [
            'sql' => "SELECT n.title, n.summary, c.club_name, c.club_id FROM club_news n JOIN sports_clubs c ON c.club_id = n.club_id AND c.status = 'active' WHERE n.status = 'published' AND (n.title LIKE ? ESCAPE '!' OR n.summary LIKE ? ESCAPE '!' OR c.club_name LIKE ? ESCAPE '!') ORDER BY n.published_at DESC LIMIT 8",
            'mapper' => static fn(array $r): array => ['title' => $r['title'], 'detail' => $r['club_name'], 'url' => '/clubs/view.php?id=' . (int) $r['club_id'] . '#club-highlights'],
            'params' => 3,
        ],
    ];
    try {
        $pdo = db();
        foreach ($catalogues as $section => $config) {
            try {
                $stmt = $pdo->prepare($config['sql']);
                $stmt->execute(array_fill(0, $config['params'], $like));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
                    $results[$section][] = $config['mapper']($record);
                }
            } catch (Throwable $e) {
                error_log('Site search (' . $section . '): ' . $e->getMessage());
                $failedModules[] = $section;
            }
        }
    } catch (Throwable $e) {
        error_log('Site search database: ' . $e->getMessage());
        $failedModules[] = 'database';
    }
}
foreach ($results as $items) { $sectionCount += count($items); }
// Return limited public search results for the header search interface.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $items = [];
    foreach ($results as $section => $group) {
        foreach ($group as $item) {
            if (count($items) >= 24) break 2;
            $items[] = ['group' => $section, 'title' => (string) $item['title'],
                        'detail' => (string) $item['detail'], 'url' => (string) $item['url']];
        }
    }
    echo json_encode(['query' => $term, 'results' => $items,
                      'partial' => $failedModules !== []],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$pageTitle = 'Search';
require __DIR__ . '/includes/header.php';
?>
<main class="phase13-search-page shell">
    <div class="phase13-search-intro">
        <h1>Search SportsHub.</h1>
        <p>Find clubs, tournaments, fixtures, training, facilities and published club highlights.</p>
    </div>
    <form action="/search.php" method="get" role="search" class="phase13-search-form">
        <label for="site-search-q" class="sr-only">Search SportsHub</label>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input id="site-search-q" name="q" type="search" maxlength="80" minlength="2" autocomplete="off" placeholder="Search anything public on SportsHub" value="<?= sportsHubEscape($term) ?>" autofocus required>
        <button class="button button-accent" type="submit">Search</button>
    </form>
    <?php if (!$searched && $term === ''): ?>
        <div class="phase13-search-empty">Start typing to search. Tip: press <kbd>⌘ K</kbd> or <kbd>Ctrl K</kbd> from any page.</div>
    <?php elseif (!$searched): ?>
        <p class="phase13-search-empty" role="alert">Enter at least two characters to search.</p>
    <?php else: ?>
        <p class="phase13-search-count"><?= $sectionCount ?> result<?= $sectionCount === 1 ? '' : 's' ?> for “<?= sportsHubEscape($term) ?>”</p>
        <?php if ($sectionCount === 0): ?>
            <p class="phase13-search-empty">No matching public content found. Try another keyword or sport.</p>
        <?php endif; ?>
        <div class="phase13-search-groups">
            <?php foreach ($results as $section => $items): ?>
                <?php if ($items === []): continue; endif; ?>
                <section class="phase13-search-group" aria-label="<?= sportsHubEscape($section) ?>">
                    <h2><?= sportsHubEscape($section) ?></h2>
                    <?php foreach ($items as $item): ?>
                        <a class="phase13-search-result" href="<?= sportsHubEscape($item['url']) ?>">
                            <span><strong><?= sportsHubEscape($item['title']) ?></strong><small><?= sportsHubEscape($item['detail']) ?></small></span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php if ($failedModules !== []): ?>
            <p class="phase13-search-note" role="status">Some sections are temporarily unavailable. Results may be incomplete.</p>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
