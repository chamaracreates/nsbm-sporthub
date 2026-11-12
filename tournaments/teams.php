<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = require_login();
$userRole = current_user_role();

/* Restrict management access to authorized staff. */
if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

/* Escape values before HTML output. */
function teamManageEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* Format creation dates. */
function teamManageDate(string $value): string
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

$teamCreatedMessage =
    $_SESSION['team_created_flash'] ?? null;

unset($_SESSION['team_created_flash']);

$teams = [];
$loadError = false;

try {
    $pdo = db();

    /*
     * Staff can manage teams belonging only to
     * their actively assigned sports clubs.
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
     * Retrieve teams, associated tournaments,
     * sports clubs, and roster counts.
     */
    $sql = "
        SELECT
            tm.team_id,
            tm.team_name,
            tm.created_at,
            t.t_id,
            t.t_name,
            t.sport,
            t.status AS tournament_status,
            sc.club_name,
            (
                SELECT COUNT(*)
                FROM team_members AS members
                WHERE members.team_id = tm.team_id
            ) AS member_count

        FROM teams AS tm

        INNER JOIN tournaments AS t
            ON t.t_id = tm.t_id

        INNER JOIN sports_clubs AS sc
            ON sc.club_id = tm.club_id

        WHERE sc.status = 'active'
        $clubScope

        ORDER BY
            tm.created_at DESC,
            tm.team_id DESC

        LIMIT 100
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $teams = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log(
        'Team management error: ' .
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
                NSBM SPORTSHUB / TEAM MANAGEMENT
            </p>

            <h1>Manage teams.</h1>

            <p class="section-description">
                Explore participating teams, review their
                tournament assignments and monitor team
                membership across authorized sports clubs.
            </p>

        </header>

        <?php if (is_string($teamCreatedMessage)): ?>

            <div class="tournament-form-success" role="status">
                <?= teamManageEscape($teamCreatedMessage) ?>
            </div>

        <?php endif; ?>

        <div class="tournament-view-navigation">
            <a
                href="/tournaments/manage.php"
                class="button button-outline">
                Back to Tournaments
            </a>
        </div>

        <?php if ($loadError): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Unable to load teams</h2>
                <p>
                    Team information is temporarily unavailable.
                    Please try again later.
                </p>
            </div>

        <?php else: ?>

            <div class="tournament-manage-toolbar">

                <div>
                    <h2>Registered teams</h2>

                    <p>
                        Showing <?= count($teams) ?>
                        <?= count($teams) === 1
                            ? 'team'
                            : 'teams' ?>
                    </p>
                </div>

                <a
                    href="/tournaments/create-team.php"
                    class="button button-accent">
                    Create Team
                </a>

            </div>

            <?php if (empty($teams)): ?>

                <div class="tournaments-notice">

                    <h2>No teams available</h2>

                    <p>
                        There are currently no teams
                        associated with your authorized
                        sports clubs.
                    </p>

                </div>

            <?php else: ?>

                <div class="tournaments-grid">

                    <?php foreach ($teams as $team): ?>

                        <article class="tournament-card">

                            <div class="tournament-card-top">

                                <span class="tournament-sport">
                                    <?= teamManageEscape(
                                        $team['sport']
                                    ) ?>
                                </span>

                                <span class="tournament-status">
                                    <?= teamManageEscape(
                                        $team['tournament_status']
                                    ) ?>
                                </span>

                            </div>

                            <h2>
                                <?= teamManageEscape(
                                    $team['team_name']
                                ) ?>
                            </h2>

                            <p class="tournament-club">
                                <?= teamManageEscape(
                                    $team['club_name']
                                ) ?>
                            </p>

                            <div class="tournament-details">

                                <p>
                                    <strong>Tournament</strong>

                                    <span>
                                        <?= teamManageEscape(
                                            $team['t_name']
                                        ) ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Team members</strong>

                                    <span>
                                        <?= (int) $team['member_count'] ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Created</strong>

                                    <span>
                                        <?= teamManageEscape(
                                            teamManageDate(
                                                $team['created_at']
                                            )
                                        ) ?>
                                    </span>
                                </p>

                            </div>

                            <div class="tournament-management-actions">

                                <a
                                    href="/tournaments/view.php?id=<?= (int) $team['t_id'] ?>"
                                    class="button button-outline">
                                    View Tournament
                                </a>

                                <a
                                    href="/tournaments/team-roster.php?id=<?= (int) $team['team_id'] ?>"
                                    class="button button-accent">
                                    Manage Roster
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

                <?php if (count($teams) === 100): ?>

                    <p class="tournament-manage-limit">
                        Showing the latest 100 teams.
                    </p>

                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>