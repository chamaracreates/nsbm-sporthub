<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

auth_start();


// Validate the club ID from the URL.
$clubId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$clubId) {
    http_response_code(404);
    exit('Club not found.');
}

$club = null;
$loadError = false;

try {
    $stmt = db()->prepare(
        "SELECT club_id, club_name, sport,
                description, club_email
         FROM sports_clubs
         WHERE club_id = :club_id
           AND status = 'active'
         LIMIT 1"
    );

    $stmt->execute(['club_id' => $clubId]);
    $club = $stmt->fetch();

} catch (Throwable $exception) {
    error_log('Club profile error: ' . $exception->getMessage());
    http_response_code(500);
    $loadError = true;
}

if (!$loadError && !$club) {
    http_response_code(404);
}

// Existing project images.

$image = $club
    ? (sportsHubClubCover($club['sport']) ?: '/assets/images/hero-main.webp')
    : '';

/* Load assigned MICs and coaches */

$clubStaff = [];
$staffLoadError = false;

if ($club && !$loadError) {
    try {
        $staffStmt = db()->prepare(
            "SELECT
                ss.staff_id,
                ss.staff_role,
                ss.specialization,
                u.name AS staff_name
             FROM sport_staff ss
             INNER JOIN users u
                 ON ss.user_id = u.user_id
             WHERE ss.club_id = :club_id
               AND ss.status = 'active'
             ORDER BY
                CASE
                    WHEN ss.staff_role = 'mic' THEN 0
                    ELSE 1
                END,
                u.name"
        );

        $staffStmt->execute([
            'club_id' => $clubId
        ]);

        $clubStaff = $staffStmt->fetchAll();

    } catch (Throwable $exception) {
        error_log(
            'Club staff loading error: ' .
            $exception->getMessage()
        );

        $staffLoadError = true;
    }
}


/* Load the club captain and vice-captain */

$clubLeaders = [];
$leadersLoadError = false;

if ($club && !$loadError) {
    try {
        $leaderStmt = db()->prepare(
            "SELECT
                cl.leader_role,
                cl.appointed_at,
                u.name AS student_name
             FROM club_leaders cl
             INNER JOIN users u
                ON u.user_id = cl.user_id
             INNER JOIN club_membership cm
                ON cm.user_id = cl.user_id
               AND cm.club_id = cl.club_id
             WHERE cl.club_id = :club_id
               AND u.role = 'student'
               AND cm.status = 'approved'
             ORDER BY
                CASE
                    WHEN cl.leader_role = 'captain' THEN 0
                    ELSE 1
                END"
        );

        $leaderStmt->execute([
            'club_id' => $clubId
        ]);

        $clubLeaders = $leaderStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $exception) {
        error_log(
            'Club leadership loading error: ' .
            $exception->getMessage()
        );

        $leadersLoadError = true;
    }
}

$viewerId = current_user_id();
$isStudent = $viewerId !== null && current_user_role() === 'student';

$membershipStatus = null;
$membershipCheckFailed = false;
$csrfToken = '';

if ($club && $isStudent) {
    try {
        $stmt = db()->prepare(
            "SELECT status
             FROM club_membership
             WHERE user_id = :user_id
               AND club_id = :club_id
             LIMIT 1"
        );

        $stmt->execute([
            'user_id' => $viewerId,
            'club_id' => $clubId
        ]);

        $status = $stmt->fetchColumn();

        if ($status !== false) {
            $membershipStatus = (string) $status;
        }

        // Generate a secure token for new applications.
        if ($membershipStatus === null) {
            if (empty($_SESSION['club_join_csrf'])) {
                $_SESSION['club_join_csrf'] =
                    bin2hex(random_bytes(32));
            }

            $csrfToken = $_SESSION['club_join_csrf'];
        }

    } catch (Throwable $exception) {
        error_log(
            'Membership status error: ' .
            $exception->getMessage()
        );

        $membershipCheckFailed = true;
    }
}

$pageStylesheets = ['/assets/css/clubs.css'];
require __DIR__ . '/../includes/header.php';
?>

<?php
// Find the next scheduled training session for the selected club.
$nsbmNextTraining = null;
if ($club && !$loadError) {
    try {
        $upcomingTraining = db()->prepare(
            "SELECT session_id, title, date, start_time, end_time
             FROM training_sessions
             WHERE club_id = :club_id
               AND TIMESTAMP(date, end_time) > NOW()
             ORDER BY date ASC, start_time ASC, session_id ASC
             LIMIT 1"
        );
        $upcomingTraining->execute(['club_id' => (int)$club['club_id']]);
        $nextRecord = $upcomingTraining->fetch(PDO::FETCH_ASSOC);
        if (is_array($nextRecord)) {
            $nsbmNextTraining = $nextRecord;
        }
    } catch (Throwable $exception) {
        error_log('Club next training lookup: ' . $exception->getMessage());
    }
}

// Retrieve published news associated with the current active club.
$phase13ClubHighlights = [];
$phase14NewsImageColumn = 'NULL AS image_path';
if ($club && !$loadError) {
    try {

        if (db()->query("SHOW COLUMNS FROM club_news LIKE 'image_path'")->fetch(PDO::FETCH_ASSOC)) {
            $phase14NewsImageColumn = 'image_path';
        }
        $newsStmt = db()->prepare(
            "SELECT title, summary, published_at, {$phase14NewsImageColumn}
             FROM club_news WHERE club_id = ? AND status = 'published'
             ORDER BY published_at DESC, news_id DESC LIMIT 6"
        );
        $newsStmt->execute([(int) $club['club_id']]);
        $phase13ClubHighlights = $newsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Club highlights loading: ' . $e->getMessage());
    }
}
?>
<main class="clubs-page">

    <div class="shell club-profile">

        <a href="/clubs/index.php" class="club-back">
            &larr; Back to sports clubs
        </a>

        <?php if ($loadError): ?>

            <section class="clubs-empty">
                <h1>Club information unavailable</h1>
                <p>Please try again later.</p>
            </section>

        <?php elseif (!$club): ?>

            <section class="clubs-empty">
                <h1>Club not found</h1>
                <p>
                    This club does not exist or is no longer active.
                </p>
            </section>

        <?php else: ?>

            <section class="club-profile-header" aria-labelledby="club-profile-title">
                <div class="club-profile-image" aria-hidden="true">
                    <img
                        class="club-profile-hero-image"
                        src="<?= sportsHubEscape($image) ?>"
                        alt=""
                        loading="eager"
                        decoding="async"
                    >
                </div>

                <div class="club-profile-heading">
                    <h1 id="club-profile-title">
                        <?= sportsHubEscape($club['club_name']) ?>
                    </h1>
                    <p><?= sportsHubEscape($club['description']) ?></p>

                    <div class="club-profile-action">
                        <?php if (!is_logged_in()): ?>
                            <a href="/login.php" class="club-apply-button">Join us</a>
                        <?php elseif ($isStudent && $membershipCheckFailed): ?>
                            <p class="club-hero-status" role="alert">
                                Membership status is temporarily unavailable.
                            </p>
                        <?php elseif ($isStudent && $membershipStatus === null): ?>
                            <form action="/clubs/join.php" method="post">
                                <input type="hidden" name="club_id"
                                    value="<?= (int) $club['club_id'] ?>">
                                <input type="hidden" name="csrf_token"
                                    value="<?= sportsHubEscape($csrfToken) ?>">
                                <button type="submit" class="club-apply-button">Join us</button>
                            </form>
                        <?php elseif ($isStudent): ?>
                            <p class="club-hero-status" role="status">
                                Membership: <strong><?= sportsHubEscape(ucfirst($membershipStatus)) ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if ($nsbmNextTraining !== null): ?>
            <section class="nsbm-club-next-training" aria-labelledby="club-next-training-heading">
                <h2 id="club-next-training-heading">Next training session</h2>
                <p class="nsbm-next-training-name"><?= sportsHubEscape($nsbmNextTraining['title']) ?></p>
                <div class="nsbm-next-training-meta">
                    <span><strong>Date</strong> <?= sportsHubEscape(date('D, d M Y', strtotime((string)$nsbmNextTraining['date']))) ?></span>
                    <span><strong>Time</strong> <?= sportsHubEscape(date('g:i A', strtotime((string)$nsbmNextTraining['start_time']))) ?> – <?= sportsHubEscape(date('g:i A', strtotime((string)$nsbmNextTraining['end_time']))) ?></span>
                </div>
                <a class="nsbm-next-training-link" href="/training/index.php">View training schedule</a>
            </section>
            <?php endif; ?>

            <section class="club-profile-info">

                <div class="club-info-card">
                    <h2>About the club</h2>

                    <p>
                        <?= sportsHubEscape($club['description']) ?>
                    </p>
                </div>

                <div class="club-info-card">
                    <h2>Club information</h2>

                    <p>
                        <strong>Sport:</strong>
                        <?= sportsHubEscape($club['sport']) ?>
                    </p>

                    <p>
                        <strong>Email:</strong>
                        <a href="mailto:<?= sportsHubEscape($club['club_email']) ?>">
                            <?= sportsHubEscape($club['club_email']) ?>
                        </a>
                    </p>

                    <p>
                        <strong>Status:</strong>
                        Active
                    </p>
                </div>

            </section>

<section class="club-staff-section">

    <div class="club-staff-heading">
        <h2>MICs &amp; Coaches</h2>
        <p>
            Meet the staff supporting training,
            development and club activities.
        </p>
    </div>

    <?php if ($staffLoadError): ?>

        <p role="alert">
            Staff information is temporarily unavailable.
        </p>

    <?php elseif (empty($clubStaff)): ?>

        <div class="club-staff-empty">
            <p>
                No MICs or coaches have been assigned
                to this club yet.
            </p>
        </div>

    <?php else: ?>

        <div class="club-staff-grid">

            <?php foreach ($clubStaff as $staff): ?>

                <article class="club-staff-card">

                    <span class="club-staff-role">
                        <?= $staff['staff_role'] === 'mic'
                            ? 'Member in Charge (MIC)'
                            : 'Coach' ?>
                    </span>

                    <h3>
                        <?= sportsHubEscape($staff['staff_name']) ?>
                    </h3>

                    <?php if (!empty($staff['specialization'])): ?>

                        <p>
                            Specialization:
                            <?= sportsHubEscape(
                                $staff['specialization']
                            ) ?>
                        </p>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<section class="club-staff-section">

    <div class="club-staff-heading">

        <h2>Captain &amp; Vice-Captain</h2>

        <p>
            Meet the student leaders representing
            this sports club.
        </p>
    </div>

    <?php if ($leadersLoadError): ?>

        <p role="alert">
            Club leadership information is temporarily unavailable.
        </p>

    <?php elseif (empty($clubLeaders)): ?>

        <div class="club-staff-empty">
            <p>
                No captain or vice-captain has been
                assigned to this club yet.
            </p>
        </div>

    <?php else: ?>

        <div class="club-staff-grid">

            <?php foreach ($clubLeaders as $leader): ?>

                <article class="club-staff-card">

                    <span class="club-staff-role">
                        <?= $leader['leader_role'] === 'captain'
                            ? 'Captain'
                            : 'Vice-Captain' ?>
                    </span>

                    <h3>
                        <?= sportsHubEscape(
                            $leader['student_name']
                        ) ?>
                    </h3>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

        <?php endif; ?>

    </div>


<?php if ($club && !$loadError && $phase13ClubHighlights): ?>
<?php
// Use article-specific photographs for club news.
$officialNewsImages = array_column(sportsHubOfficialNews(), 'image', 'source');
?>
<section class="shell phase13-club-highlights" id="club-highlights" aria-labelledby="phase13-club-news-title">
    <h2 id="phase13-club-news-title">Latest club news</h2>
    <div class="phase13-club-highlights-list">
        <?php foreach ($phase13ClubHighlights as $story): ?>
            <?php
            $rawSummary = trim((string) ($story['summary'] ?? ''));
            $source = '';
            if (preg_match('~Official source:\s*(https?://[^\s<>]+)~iu', $rawSummary, $sourceMatch)) {
                $candidate = rtrim($sourceMatch[1], '.,)');
                $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
                if ($host === 'www.nsbm.ac.lk' || $host === 'nsbm.ac.lk') {
                    $source = $candidate;
                }
            }
            // Preserve the full story text while removing the importer's attribution paragraph.
            $summary = preg_replace(
                '~\s*Originally reported by NSBM on\s+\d{4}-\d{2}-\d{2}\.\s*Official source:\s*https?://[^\s<>]+~iu',
                '',
                $rawSummary
            );
            $summary = trim($summary ?? $rawSummary);

            // Use the original article date when available (the database publication
            // timestamp may instead be the date the historical news was imported).
            $displayDate = substr((string) ($story['published_at'] ?? ''), 0, 10);
            if (preg_match('/Originally reported by NSBM on\s+(\d{4}-\d{2}-\d{2})/iu', $rawSummary, $dateMatch)) {
                $displayDate = $dateMatch[1];
            }
            $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $displayDate);
            $validDate = $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d') === $displayDate;

            $newsImage = sportsHubNewsCover($story, $officialNewsImages[$source] ?? '');
            ?>
            <article class="club-news-article">
                <div class="club-news-photo<?= $newsImage === '' ? ' club-news-photo--empty' : '' ?>">
                    <?php if ($newsImage !== ''): ?>
                        <img src="<?= sportsHubEscape($newsImage) ?>"
                             alt="<?= sportsHubEscape('Photo from ' . (string) ($story['title'] ?? 'club news')) ?>"
                             loading="lazy" decoding="async">
                    <?php else: ?>
                        <span>SportsHub news</span>
                    <?php endif; ?>
                </div>
                <div class="club-news-card-body">
                    <?php if ($validDate): ?>
                        <time datetime="<?= sportsHubEscape($displayDate) ?>">
                            <?= sportsHubEscape($dateTime->format('j M Y')) ?>
                        </time>
                    <?php endif; ?>
                    <h3><?= sportsHubEscape($story['title']) ?></h3>
                    <p><?= sportsHubEscape($summary) ?></p>
                    <?php if ($source !== ''): ?>
                        <a class="club-news-original" href="<?= sportsHubEscape($source) ?>"
                           target="_blank" rel="noopener noreferrer">Read original</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>