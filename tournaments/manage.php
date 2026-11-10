<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/* Require an authenticated administrative or staff account. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

function tournamentManageEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function tournamentManageDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        ? $date->format('d M Y')
        : $value;
}

$isAdmin = $userRole === 'admin';
$clubs = [];
$tournaments = [];
$loadError = false;

try {
    $pdo = db();

    /*
     * Admins can manage all active clubs.
     * Coaches and MICs are limited to active assignments.
     */
    $clubScope = '';
    $parameters = [];

    if (!$isAdmin) {
        $clubScope = "
            AND EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = sc.club_id
                  AND ss.user_id = :staff_user_id
                  AND ss.staff_role = :staff_role
                  AND ss.status = 'active'
            )
        ";

        $parameters = [
            'staff_user_id' => $userId,
            'staff_role' => $userRole
        ];
    }

    /* Retrieve clubs within the authorized scope. */
    $clubStatement = $pdo->prepare(
        "SELECT
            sc.club_id,
            sc.club_name
         FROM sports_clubs AS sc
         WHERE sc.status = 'active'
         $clubScope
         ORDER BY sc.club_name ASC"
    );

    $clubStatement->execute($parameters);

    $clubs = $clubStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    /* Retrieve only tournaments within authorized clubs. */
    $tournamentStatement = $pdo->prepare(
        "SELECT
            t.t_id,
            t.t_name,
            t.sport,
            t.start_date,
            t.end_date,
            t.venue,
            t.status,
            sc.club_name,
            (
                SELECT COUNT(*)
                FROM teams AS tm
                WHERE tm.t_id = t.t_id
            ) AS team_count
         FROM tournaments AS t
         INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
         WHERE sc.status = 'active'
         $clubScope
         ORDER BY t.start_date DESC, t.t_id DESC
         LIMIT 100"
    );

    $tournamentStatement->execute($parameters);

    $tournaments = $tournamentStatement->fetchAll(
        PDO::FETCH_ASSOC
    );
} catch (Throwable $error) {
    error_log(
        'Tournament management error: ' .
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

            <p class="section-label">
                NSBM SPORTSHUB / TOURNAMENT ADMINISTRATION
            </p>

            <h1>Manage tournaments.</h1>

            <p class="section-description">
                Review tournaments, monitor participating
                teams and manage competitions for your
                authorized sports clubs.
            </p>

        </header>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load tournaments</h2>

                <p>
                    Tournament management is temporarily
                    unavailable. Please try again later.
                </p>
            </div>

        <?php else: ?>

            <div class="tournament-manage-toolbar">

                <div>
                    <h2>Managed tournaments</h2>

                    <p>
                        Showing <?= count($tournaments) ?>
                        <?= count($tournaments) === 1
                            ? 'tournament'
                            : 'tournaments' ?>
                    </p>
                </div>

                <?php if (!empty($clubs)): ?>

                    <a
                        href="/tournaments/create.php"
                        class="button button-accent">
                        Create Tournament
                    </a>

                <?php endif; ?>

            </div>

            <?php if (empty($clubs)): ?>

                <div class="tournaments-notice">

                    <h2>No authorized clubs available</h2>

                    <p>
                        You need access to an active sports club
                        before managing tournaments.
                    </p>

                </div>

            <?php elseif (empty($tournaments)): ?>

                <div class="tournaments-notice">

                    <h2>No tournaments to manage</h2>

                    <p>
                        Your authorized clubs do not currently
                        have any tournaments. You can create
                        a new tournament using the button above.
                    </p>

                </div>

            <?php else: ?>

                <div class="tournaments-grid">

                    <?php foreach ($tournaments as $tournament): ?>

                        <article class="tournament-card">

                            <div class="tournament-card-top">

                                <span class="tournament-sport">
                                    <?= tournamentManageEscape(
                                        $tournament['sport']
                                    ) ?>
                                </span>

                                <span class="tournament-status">
                                    <?= tournamentManageEscape(
                                        $tournament['status']
                                    ) ?>
                                </span>

                            </div>

                            <h2>
                                <?= tournamentManageEscape(
                                    $tournament['t_name']
                                ) ?>
                            </h2>

                            <p class="tournament-club">
                                <?= tournamentManageEscape(
                                    $tournament['club_name']
                                ) ?>
                            </p>

                            <div class="tournament-details">

                                <p>
                                    <strong>Start date</strong>
                                    <span>
                                        <?= tournamentManageEscape(
                                            tournamentManageDate(
                                                $tournament['start_date']
                                            )
                                        ) ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>End date</strong>
                                    <span>
                                        <?= tournamentManageEscape(
                                            tournamentManageDate(
                                                $tournament['end_date']
                                            )
                                        ) ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Venue</strong>
                                    <span>
                                        <?= tournamentManageEscape(
                                            $tournament['venue']
                                        ) ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Teams</strong>
                                    <span>
                                        <?= (int) $tournament['team_count'] ?>
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

                <?php if (count($tournaments) === 100): ?>
                    <p class="tournament-manage-limit">
                        Showing up to 100 tournaments,
                        ordered by start date.
                    </p>
                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>