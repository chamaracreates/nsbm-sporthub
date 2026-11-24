<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/helpers.php';

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

// Map supported sports to their published club images.

$pageStylesheets = ['/assets/css/clubs.css'];
$pageTitle = 'Sports clubs';
require __DIR__ . '/../includes/header.php';
?>


<main class="clubs-page">

    <section class="shell clubs-intro">
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
                <div class="clubs-filter-field clubs-filter-search">
                    <label for="club-search">Search clubs</label>
                    <input
                        type="search"
                        id="club-search"
                        name="q"
                        placeholder="Search by club or sport"
                        value="<?= sportsHubEscape($search) ?>"
                    >
                </div>

                <div class="clubs-filter-field clubs-filter-sport">
                    <label for="sport-filter">Sport</label>
                    <select id="sport-filter" name="sport">
                        <option value="">All sports</option>
                        <?php foreach ($sports as $sport): ?>
                            <option
                                value="<?= sportsHubEscape($sport) ?>"
                                <?= $selectedSport === $sport ? 'selected' : '' ?>
                            >
                                <?= sportsHubEscape($sport) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="clubs-filter-actions">
                    <button type="submit">Search clubs</button>
                    <?php if ($search !== '' || $selectedSport !== ''): ?>
                        <a href="/clubs/index.php">Clear filters</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($search !== '' || $selectedSport !== ''): ?>
                <p class="clubs-result-count" role="status">
                    <?= count($clubs) ?>
                    <?= count($clubs) === 1 ? 'club' : 'clubs' ?> found
                </p>
            <?php endif; ?>

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

                        $image = sportsHubClubCover($sportKey) ?: '/assets/images/hero-main.webp';
                        ?>

                        <article class="clubs-card">

                            <img
                                src="<?= sportsHubEscape($image) ?>"
                                alt="<?= sportsHubEscape($club['club_name']) ?>"
                                loading="lazy"
                            >

                            <div class="clubs-card-content">

                                <h2>
                                    <?= sportsHubEscape($club['club_name']) ?>
                                </h2>

                                <p>
                                    <?= sportsHubEscape($club['description']) ?>
                                </p>

                                <a
                                    href="/clubs/view.php?id=<?= (int) $club['club_id'] ?>"
                                    class="clubs-view-link"
                                    aria-label="View <?= sportsHubEscape($club['club_name']) ?>"
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
