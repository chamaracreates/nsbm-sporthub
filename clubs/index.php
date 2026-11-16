<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// Safely display text from the database.
function clubEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Read the search and sport filters.
$search = is_string($_GET['q'] ?? null)
    ? trim($_GET['q'])
    : '';

$selectedSport = is_string($_GET['sport'] ?? null)
    ? trim($_GET['sport'])
    : '';

$clubs = [];
$sports = [];
$loadError = false;

try {
    // Get the list of available sports for the filter.
    $sports = db()->query(
        "SELECT DISTINCT sport
         FROM sports_clubs
         WHERE status = 'active'
         ORDER BY sport"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Only show active sports clubs.
    $sql = "SELECT club_id, club_name, sport, description
            FROM sports_clubs
            WHERE status = 'active'";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (
            club_name LIKE :search_name
            OR sport LIKE :search_sport
            OR description LIKE :search_description
        )";

        $keyword = '%' . $search . '%';

        $params['search_name'] = $keyword;
        $params['search_sport'] = $keyword;
        $params['search_description'] = $keyword;
    }

    if ($selectedSport !== '') {
        $sql .= " AND sport = :sport";
        $params['sport'] = $selectedSport;
    }

    $sql .= " ORDER BY club_name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll();

} catch (Throwable $exception) {
    error_log('Club directory error: ' . $exception->getMessage());
    $loadError = true;
}

// Match clubs to the images already used by Chamara.
$clubImages = [
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

require __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/clubs.css">

<main class="clubs-page">

    <section class="shell clubs-intro">
        <p class="clubs-eyebrow">NSBM SportsHub / Sports clubs</p>
        <h1>Find your sport.</h1>
        <p>
            Explore the sports clubs at NSBM Green University.
            Find your community, discover new opportunities,
            and get involved in campus sports.
        </p>
    </section>

    <section class="shell clubs-directory">

        <?php if ($loadError): ?>

            <p role="alert">
                The club directory is temporarily unavailable.
                Please try again later.
            </p>

        <?php else: ?>

            <form
                method="get"
                action="/clubs/index.php"
                class="clubs-filters"
                role="search"
            >
                <label for="club-search">Search clubs</label>

                <input
                    type="search"
                    id="club-search"
                    name="q"
                    placeholder="Search by club or sport"
                    value="<?= clubEscape($search) ?>"
                >

                <label for="sport-filter">Sport</label>

                <select id="sport-filter" name="sport">
                    <option value="">All sports</option>

                    <?php foreach ($sports as $sport): ?>
                        <option
                            value="<?= clubEscape($sport) ?>"
                            <?= $selectedSport === $sport ? 'selected' : '' ?>
                        >
                            <?= clubEscape($sport) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">
                    Search clubs
                </button>

                <a href="/clubs/index.php">Clear filters</a>
            </form>

            <p class="clubs-result-count">
                <?= count($clubs) ?>
                <?= count($clubs) === 1 ? 'club' : 'clubs' ?> found
            </p>

            <?php if (empty($clubs)): ?>

                <div class="clubs-empty">
                    <h2>No clubs found</h2>
                    <p>
                        No active sports clubs match your selection.
                        Try changing the search or filter.
                    </p>
                </div>

            <?php else: ?>

                <div class="clubs-grid">

                    <?php foreach ($clubs as $club): ?>

                        <?php
                        $sportKey = strtolower(trim($club['sport']));

                        $image = $clubImages[$sportKey]
                            ?? '/assets/images/club-marks/general.svg';
                        ?>

                        <article class="clubs-card">

                            <img
                                src="<?= clubEscape($image) ?>"
                                alt="<?= clubEscape($club['club_name']) ?>"
                                loading="lazy"
                            >

                            <div class="clubs-card-content">

                                <span class="clubs-sport">
                                    <?= clubEscape($club['sport']) ?>
                                </span>

                                <h2>
                                    <?= clubEscape($club['club_name']) ?>
                                </h2>

                                <p>
                                    <?= clubEscape($club['description']) ?>
                                </p>

                                <a
                                    href="/clubs/view.php?id=<?= (int) $club['club_id'] ?>"
                                    class="clubs-view-link"
                                >
                                    View club
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
