<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function tournamentEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function tournamentDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        ? $date->format('d M Y')
        : $value;
}

/* Validate the tournament directory filter. */
$tabs = [
    'upcoming' => 'Upcoming',
    'ongoing' => 'Ongoing',
    'past' => 'Past',
    'all' => 'All tournaments'
];

$activeTab = $_GET['tab'] ?? 'upcoming';

if (
    !is_string($activeTab) ||
    !array_key_exists($activeTab, $tabs)
) {
    $activeTab = 'upcoming';
}

$tournaments = [];
$loadError = false;

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

/* Retrieve official tournament records. */
try {
    $pdo = db();

    $conditions = ["sc.status = 'active'"];
    $parameters = [];

    switch ($activeTab) {
        case 'upcoming':
            $conditions[] = 't.start_date > :today';
            $parameters['today'] = $today;
            break;

        case 'ongoing':
            $conditions[] = '
                t.start_date <= :start_day
                AND t.end_date >= :end_day
            ';
            $parameters['start_day'] = $today;
            $parameters['end_day'] = $today;
            break;

        case 'past':
            $conditions[] = 't.end_date < :today';
            $parameters['today'] = $today;
            break;
    }

    $where = implode(' AND ', $conditions);

    $order = $activeTab === 'upcoming'
        ? 't.start_date ASC, t.t_id ASC'
        : 't.start_date DESC, t.t_id DESC';

    $sql = "
        SELECT
            t.t_id,
            t.t_name,
            t.sport,
            t.des,
            t.reg_open_at,
            t.reg_end_at,
            t.start_date,
            t.end_date,
            t.venue,
            t.status,
            sc.club_name
        FROM tournaments AS t
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
        WHERE $where
        ORDER BY $order
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $tournaments = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
} catch (Throwable $error) {
    error_log(
        'Tournament directory error: ' .
            $error->getMessage()
    );

    http_response_code(500);
    $loadError = true;
}

require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">

    <section class="tournaments-container section-container">

        <header class="tournaments-heading">
            <p class="tournaments-eyebrow section-label">
                NSBM SPORTSHUB / TOURNAMENTS
            </p>

            <h1>Tournaments &amp; competitions</h1>

            <p class="tournaments-description section-description">
                Explore official NSBM sports tournaments,
                competition schedules, participating clubs
                and registration periods.
            </p>
            <?php if ($canManageTournaments): ?>

                <div class="tournament-directory-actions">

                    <a
                        href="/tournaments/manage.php"
                        class="button button-outline">
                        Manage Tournaments
                    </a>

                    <a
                        href="/tournaments/create.php"
                        class="button button-accent">
                        Create Tournament
                    </a>

                </div>

            <?php endif; ?>

        </header>

        <nav class="tournaments-tabs"
            aria-label="Tournament categories">

            <?php foreach ($tabs as $tab => $label): ?>
                <a
                    href="/tournaments/index.php?tab=<?= tournamentEscape($tab) ?>"
                    class="<?= $activeTab === $tab ? 'active' : '' ?>"
                    <?= $activeTab === $tab
                        ? 'aria-current="page"'
                        : '' ?>>
                    <?= tournamentEscape($label) ?>
                </a>
            <?php endforeach; ?>

        </nav>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load tournaments</h2>
                <p>
                    Tournament information is temporarily
                    unavailable. Please try again later.
                </p>
            </div>

        <?php elseif (empty($tournaments)): ?>

            <div class="tournaments-notice">
                <h2>No tournaments available</h2>
                <p>
                    There are currently no tournaments
                    in the selected category.
                </p>
            </div>

        <?php else: ?>

            <div class="tournaments-count">
                <?= count($tournaments) ?>
                <?= count($tournaments) === 1
                    ? 'tournament'
                    : 'tournaments' ?>
            </div>

            <div class="tournaments-grid">

                <?php foreach ($tournaments as $tournament): ?>

                    <article class="tournament-card">

                        <div class="tournament-card-top">
                            <span class="tournament-sport">
                                <?= tournamentEscape(
                                    $tournament['sport']
                                ) ?>
                            </span>

                            <span class="tournament-status">
                                <?= tournamentEscape(
                                    $tournament['status']
                                ) ?>
                            </span>
                        </div>

                        <h2>
                            <?= tournamentEscape(
                                $tournament['t_name']
                            ) ?>
                        </h2>

                        <p class="tournament-club">
                            Organized by
                            <?= tournamentEscape(
                                $tournament['club_name']
                            ) ?>
                        </p>

                        <p class="tournament-summary">
                            <?= tournamentEscape(
                                $tournament['des']
                            ) ?>
                        </p>

                        <div class="tournament-details">

                            <p>
                                <strong>Event dates</strong>
                                <span>
                                    <?= tournamentEscape(
                                        tournamentDate(
                                            $tournament['start_date']
                                        )
                                    ) ?>
                                    –
                                    <?= tournamentEscape(
                                        tournamentDate(
                                            $tournament['end_date']
                                        )
                                    ) ?>
                                </span>
                            </p>

                            <p>
                                <strong>Registration</strong>
                                <span>
                                    <?= tournamentEscape(
                                        tournamentDate(
                                            $tournament['reg_open_at']
                                        )
                                    ) ?>
                                    –
                                    <?= tournamentEscape(
                                        tournamentDate(
                                            $tournament['reg_end_at']
                                        )
                                    ) ?>
                                </span>
                            </p>

                            <p>
                                <strong>Venue</strong>
                                <span>
                                    <?= tournamentEscape(
                                        $tournament['venue']
                                    ) ?>
                                </span>
                            </p>

                        </div>

                        <a
                            href="/tournaments/view.php?id=<?= (int) $tournament['t_id'] ?>"
                            class="button button-outline tournament-view-link">
                            View Tournament
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php';

require_once __DIR__ . '/../includes/auth.php';

$canManageTournaments = in_array(
    current_user_role(),
    ['admin', 'coach', 'mic'],
    true
);
?>