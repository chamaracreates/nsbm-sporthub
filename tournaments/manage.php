<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

/*
 * NSBM SportsHub
 * Tournament and team management operations.
 * Administrative tournament management.
 */

/* Require an authenticated staff account. */
$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Only GET requests are permitted. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}


/* Format dates for the tournament cards. */


/* Determine the current staff access level. */
$isAdmin = $userRole === 'admin';

/* Use the project's Sri Lankan timezone. */
$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

/* Initialize data containers. */
$clubs = [];
$tournaments = [];
$loadError = false;

try {
    $pdo = db();
    $columnNames = $pdo->query('SHOW COLUMNS FROM `tournaments`')->fetchAll(PDO::FETCH_COLUMN);
    $historyExpr = in_array('is_historical', $columnNames, true) ? 't.is_historical' : '0';
    $yearExpr = in_array('event_year', $columnNames, true) ? 't.event_year' : 'YEAR(t.start_date)';
    $organizerExpr = in_array('organizer_name', $columnNames, true)
        ? "COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published')"
        : "COALESCE(sc.club_name, 'Organizer not published')";

    /*
     * Administrators can manage all active clubs.
     *
     * Coaches and MICs can only manage clubs
     * where they hold an active staff assignment.
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

    /*
     * Retrieve sports clubs that the current
     * account is authorized to manage.
     */
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

    /*
     * Retrieve tournaments within the authorized scope.
     *
     * Also calculate:
     * - Number of participating teams
     * - Number of tournament registrations
     * - Number of associated match fixtures
     *
     * These counts determine whether a tournament
     * is eligible for editing.
     */
    $tournamentStatement = $pdo->prepare(
        "SELECT
            t.t_id,
            t.t_name,
            t.sport,
            t.start_date,
            t.end_date,
            t.venue,
            t.status,
            $organizerExpr AS club_name,
            $historyExpr AS is_historical,

            (
                SELECT COUNT(*)
                FROM teams AS tm
                WHERE tm.t_id = t.t_id
            ) AS team_count,

            (
                SELECT COUNT(*)
                FROM tournament_registration AS tr
                WHERE tr.t_id = t.t_id
            ) AS registration_count,

            (
                SELECT COUNT(*)
                FROM matches AS m
                WHERE m.t_id = t.t_id
            ) AS fixture_count

         FROM tournaments AS t

         LEFT JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id

         WHERE (sc.status = 'active' $clubScope " . ($isAdmin ? "OR $historyExpr = 1" : "") . ")

         ORDER BY
            COALESCE(t.start_date, t.end_date, MAKEDATE($yearExpr, 1)) DESC,
            t.t_id DESC

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

/* Load the shared NSBM SportsHub header. */
$pageStylesheets = ['/assets/css/tournaments.css'];
require __DIR__ . '/../includes/header.php';

?>

<main class="tournaments-page sports-hub-workflow">

    <section class="tournaments-container section-container">

        <!-- Page heading -->
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

            <div class="tournament-directory-actions">
                <a
                    href="/tournaments/teams.php"
                    class="button button-outline">
                    Manage Teams
                </a>

                <a
                    href="/tournaments/registrations.php"
                    class="button button-accent">
                    Review Registrations
                </a>
            </div>

        </header>

        <?php if ($loadError): ?>

            <!-- Database error state -->
            <div class="tournaments-notice" role="alert">

                <h2>Unable to load tournaments</h2>

                <p>
                    Tournament management is temporarily
                    unavailable. Please try again later.
                </p>

            </div>

        <?php else: ?>

            <!-- Management toolbar -->
            <div class="tournament-manage-toolbar">

                <div>

                    <h2>Managed tournaments</h2>

                    <p>
                        Showing
                        <?= count($tournaments) ?>

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

                <!-- No authorized clubs -->
                <div class="tournaments-notice">

                    <h2>No authorized clubs available</h2>

                    <p>
                        You need access to an active sports
                        club before managing tournaments.
                    </p>

                </div>

            <?php elseif (empty($tournaments)): ?>

                <!-- No tournament records -->
                <div class="tournaments-notice">

                    <h2>No tournaments to manage</h2>

                    <p>
                        Your authorized sports clubs do not
                        currently have any tournaments.

                        You can create a new tournament
                        using the button above.
                    </p>

                </div>

            <?php else: ?>

                <!-- Tournament cards -->
                <div class="tournaments-grid">

                    <?php foreach ($tournaments as $tournament): ?>

                        <?php
                        /*
                         * Tournament editing is permitted only if:
                         *
                         * 1. Status is upcoming
                         * 2. Start date is in the future
                         * 3. No teams are associated
                         * 4. No registrations exist
                         * 5. No match fixtures exist
                         *
                         * The edit.php page independently
                         * enforces these restrictions.
                         */
                        $canEdit =
                            strtolower(
                                trim(
                                    (string) $tournament['status']
                                )
                            ) === 'upcoming'

                            && (string) $tournament['start_date']
                            > $today

                            && (int) $tournament['team_count']
                            === 0

                            && (int) $tournament['registration_count']
                            === 0

                            && (int) $tournament['fixture_count']
                            === 0;
                        ?>

                        <article class="tournament-card">

                            <!-- Tournament metadata -->
                            <div class="tournament-card-top">

                                <span class="tournament-sport">
                                    <?= sportsHubEscape(
                                        $tournament['sport']
                                    ) ?>
                                </span>

                                <span class="tournament-status">
                                    <?= sportsHubEscape(
                                        $tournament['status']
                                    ) ?>
                                </span>

                            </div>

                            <!-- Tournament title -->
                            <h2>
                                <?= sportsHubEscape(
                                    $tournament['t_name']
                                ) ?>
                            </h2>

                            <!-- Organizing sports club -->
                            <p class="tournament-club">
                                <?= sportsHubEscape(
                                    $tournament['club_name']
                                ) ?>
                            </p>

                            <!-- Tournament details -->
                            <div class="tournament-details">

                                <?php /* Show a single date when the tournament begins and ends on the same day. */
                                    $startDate = trim((string)($tournament['start_date'] ?? ''));
                                    $endDate = trim((string)($tournament['end_date'] ?? ''));
                                    $oneDay = $startDate !== '' && ($startDate === $endDate || $endDate === '');
                                ?>
                                <?php if ($oneDay): ?>
                                    <p><strong>Date</strong><span><?= sportsHubEscape(sportsHubTournamentDate($startDate)) ?></span></p>
                                <?php else: ?>
                                    <p><strong>Start date</strong><span><?= $startDate !== '' ? sportsHubEscape(sportsHubTournamentDate($startDate)) : 'Not specified' ?></span></p>
                                    <p><strong>End date</strong><span><?= $endDate !== '' ? sportsHubEscape(sportsHubTournamentDate($endDate)) : 'Not specified' ?></span></p>
                                <?php endif; ?>

                                <p>
                                    <strong>Venue</strong>

                                    <span>
                                        <?= sportsHubEscape(
                                            $tournament['venue']
                                        ) ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Participating teams</strong>

                                    <span>
                                        <?= (int) $tournament['team_count'] ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Registrations</strong>

                                    <span>
                                        <?= (int) $tournament['registration_count'] ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Fixtures</strong>

                                    <span>
                                        <?= (int) $tournament['fixture_count'] ?>
                                    </span>
                                </p>

                            </div>

                            <!-- Tournament management actions -->
                            <div class="tournament-management-actions">

                                <a
                                    href="/tournaments/view.php?id=<?= (int) $tournament['t_id'] ?>"
                                    class="button button-outline">
                                    View Tournament
                                </a>

                                <?php if ($canEdit): ?>

                                    <a
                                        href="/tournaments/edit.php?id=<?= (int) $tournament['t_id'] ?>"
                                        class="button button-accent">
                                        Edit Tournament
                                    </a>

                                <?php endif; ?>

                                <?php if ($userRole === 'admin'): ?>
                                    <a
                                        href="/admin/delete.php?type=tournament&amp;id=<?= (int) $tournament['t_id'] ?>"
                                        class="button button-danger-outline">
                                        Delete tournament
                                    </a>
                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

                <!-- Result limit information -->
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