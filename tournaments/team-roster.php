<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/* NSBM SportsHub - Team Roster Management */

$userId = require_login();
$userRole = current_user_role();

if (!in_array($userRole, ['admin', 'coach', 'mic'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

function rosterEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function rosterFindTeam(
    PDO $pdo,
    int $teamId,
    int $userId,
    string $userRole,
    bool $lock = false
): ?array {
    $scope = '';
    $params = ['team_id' => $teamId];

    if ($userRole !== 'admin') {
        $scope = "
            AND EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = tm.club_id
                  AND ss.user_id = :staff_user_id
                  AND ss.staff_role = :staff_role
                  AND ss.status = 'active'
            )
        ";

        $params['staff_user_id'] = $userId;
        $params['staff_role'] = $userRole;
    }

    $sql = "
        SELECT
            tm.team_id,
            tm.team_name,
            tm.club_id,
            t.t_id,
            t.t_name,
            t.status,
            t.start_date,
            sc.club_name
        FROM teams AS tm
        INNER JOIN tournaments AS t
            ON t.t_id = tm.t_id
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = tm.club_id
        WHERE tm.team_id = :team_id
          AND tm.club_id = t.club_id
          AND sc.status = 'active'
          $scope
        LIMIT 1
    ";

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $result = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($result) ? $result : null;
}

function rosterHasFixtures(
    PDO $pdo,
    int $teamId,
    int $tournamentId
): bool {
    $statement = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM matches
            WHERE t_id = :tournament_id
               OR home_team_id = :home_team_id
               OR away_team_id = :away_team_id
        )"
    );

    $statement->execute([
        'tournament_id' => $tournamentId,
        'home_team_id' => $teamId,
        'away_team_id' => $teamId
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function rosterCanChange(
    PDO $pdo,
    array $team,
    string $today
): bool {
    return strtolower(trim((string) $team['status']))
        === 'upcoming'
        && (string) $team['start_date'] > $today
        && !rosterHasFixtures(
            $pdo,
            (int) $team['team_id'],
            (int) $team['t_id']
        );
}

$teamId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

if (
    !isset($_SESSION['team_roster_csrf']) ||
    !is_string($_SESSION['team_roster_csrf'])
) {
    $_SESSION['team_roster_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['team_roster_csrf'];

$team = null;
$members = [];
$students = [];
$errors = [];
$pageError = null;
$canChange = false;
$pdo = null;

$flash = $_SESSION['team_roster_flash'] ?? null;
unset($_SESSION['team_roster_flash']);

if (!is_int($teamId)) {
    http_response_code(400);
    $pageError = 'Invalid team ID.';
} else {
    try {
        $pdo = db();

        $team = rosterFindTeam(
            $pdo,
            $teamId,
            $userId,
            (string) $userRole
        );

        if ($team === null) {
            http_response_code(404);
            $pageError = 'Team not found or access denied.';
        } else {
            $canChange = rosterCanChange(
                $pdo,
                $team,
                $today
            );
        }
    } catch (Throwable $error) {
        error_log(
            'Roster loading error: ' . $error->getMessage()
        );

        http_response_code(500);
        $pageError = 'Unable to load team information.';
    }
}

/* Handle add and remove actions. */
if (
    $method === 'POST' &&
    $team !== null &&
    $pdo instanceof PDO
) {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($csrfToken, $token)
    ) {
        $errors[] = 'Invalid form session.';
    }

    if (
        !is_string($action) ||
        !in_array($action, ['add', 'remove'], true)
    ) {
        $errors[] = 'Invalid roster action.';
    }

    if (!$canChange) {
        http_response_code(409);
        $errors[] = 'The roster is locked for changes.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the parent tournament first.
             * This coordinates roster updates with
             * tournament and fixture administration.
             */
            $lockTournament = $pdo->prepare(
                "SELECT t_id
                 FROM tournaments
                 WHERE t_id = :tournament_id
                 FOR UPDATE"
            );

            $lockTournament->execute([
                'tournament_id' => (int) $team['t_id']
            ]);

            $current = rosterFindTeam(
                $pdo,
                $teamId,
                $userId,
                (string) $userRole,
                true
            );

            if (
                $current === null ||
                !rosterCanChange($pdo, $current, $today)
            ) {
                $errors[] =
                    'Team permissions or tournament ' .
                    'conditions have changed.';
            } elseif ($action === 'add') {

                $studentId = filter_var(
                    $_POST['student_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $memberRole = $_POST['role'] ?? '';

                if (
                    !is_int($studentId) ||
                    !is_string($memberRole) ||
                    !in_array(
                        $memberRole,
                        ['player', 'captain'],
                        true
                    )
                ) {
                    $errors[] = 'Select a valid student and role.';
                } else {
                    /*
                     * Only approved student members
                     * of this sports club are eligible.
                     */
                    $eligible = $pdo->prepare(
                        "SELECT u.user_id
                         FROM users AS u
                         INNER JOIN club_membership AS cm
                            ON cm.user_id = u.user_id
                         WHERE u.user_id = :student_id
                           AND u.role = 'student'
                           AND cm.club_id = :club_id
                           AND cm.status = 'approved'
                         LIMIT 1"
                    );

                    $eligible->execute([
                        'student_id' => $studentId,
                        'club_id' => (int) $current['club_id']
                    ]);

                    if ($eligible->fetchColumn() === false) {
                        $errors[] =
                            'Student does not have approved ' .
                            'membership in this club.';
                    }

                    $duplicate = $pdo->prepare(
                        "SELECT 1
                         FROM team_members
                         WHERE team_id = :team_id
                           AND user_id = :student_id
                         LIMIT 1"
                    );

                    $duplicate->execute([
                        'team_id' => $teamId,
                        'student_id' => $studentId
                    ]);

                    if ($duplicate->fetchColumn() !== false) {
                        $errors[] =
                            'This student is already on the roster.';
                    }

                    if ($memberRole === 'captain') {
                        $captain = $pdo->prepare(
                            "SELECT 1
                             FROM team_members
                             WHERE team_id = :team_id
                               AND LOWER(TRIM(role)) = 'captain'
                             LIMIT 1"
                        );

                        $captain->execute([
                            'team_id' => $teamId
                        ]);

                        if ($captain->fetchColumn() !== false) {
                            $errors[] =
                                'This team already has a captain.';
                        }
                    }

                    if (empty($errors)) {
                        $insert = $pdo->prepare(
                            "INSERT INTO team_members (
                                team_id,
                                user_id,
                                role
                            ) VALUES (
                                :team_id,
                                :student_id,
                                :member_role
                            )"
                        );

                        $insert->execute([
                            'team_id' => $teamId,
                            'student_id' => $studentId,
                            'member_role' => $memberRole
                        ]);
                    }
                }
            } elseif ($action === 'remove') {

                $memberId = filter_var(
                    $_POST['member_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!is_int($memberId)) {
                    $errors[] = 'Invalid team member.';
                } else {
                    $delete = $pdo->prepare(
                        "DELETE FROM team_members
                         WHERE team_mem_id = :member_id
                           AND team_id = :team_id"
                    );

                    $delete->execute([
                        'member_id' => $memberId,
                        'team_id' => $teamId
                    ]);

                    if ($delete->rowCount() !== 1) {
                        $errors[] =
                            'Team member was not found.';
                    }
                }
            }

            if (empty($errors)) {
                $pdo->commit();

                $_SESSION['team_roster_flash'] =
                    $action === 'add'
                    ? 'Student added to the roster.'
                    : 'Student removed from the roster.';

                header(
                    'Location: /tournaments/team-roster.php?id=' .
                        $teamId,
                    true,
                    303
                );
                exit;
            }

            $pdo->rollBack();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Roster update error: ' . $error->getMessage()
            );

            http_response_code(500);
            $errors[] = 'Unable to update the roster.';
        }
    }
}

/* Retrieve roster members and eligible students. */
if ($team !== null && $pdo instanceof PDO) {
    try {
        $statement = $pdo->prepare(
            "SELECT
                tm.team_mem_id,
                tm.role,
                u.name,
                u.user_id
             FROM team_members AS tm
             INNER JOIN users AS u
                ON u.user_id = tm.user_id
             WHERE tm.team_id = :team_id
             ORDER BY
                CASE
                    WHEN LOWER(TRIM(tm.role)) = 'captain'
                    THEN 0
                    ELSE 1
                END,
                u.name ASC"
        );

        $statement->execute(['team_id' => $teamId]);
        $members = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($canChange) {
            $statement = $pdo->prepare(
                "SELECT
                    u.user_id,
                    u.name
                 FROM users AS u
                 INNER JOIN club_membership AS cm
                    ON cm.user_id = u.user_id
                 WHERE cm.club_id = :club_id
                   AND cm.status = 'approved'
                   AND u.role = 'student'
                   AND NOT EXISTS (
                       SELECT 1
                       FROM team_members AS tm
                       WHERE tm.team_id = :team_id
                         AND tm.user_id = u.user_id
                   )
                 ORDER BY u.name ASC, u.user_id ASC"
            );

            $statement->execute([
                'club_id' => (int) $team['club_id'],
                'team_id' => $teamId
            ]);

            $students = $statement->fetchAll(
                PDO::FETCH_ASSOC
            );
        }
    } catch (Throwable $error) {
        error_log(
            'Roster display error: ' . $error->getMessage()
        );

        http_response_code(500);
        $pageError = 'Unable to load roster information.';
    }
}

require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">
    <section class="tournaments-container section-container">

        <div class="tournament-view-navigation">
            <a
                href="/tournaments/teams.php"
                class="button button-outline">
                Back to Teams
            </a>
        </div>

        <?php if ($pageError !== null): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Roster unavailable</h2>
                <p><?= rosterEscape($pageError) ?></p>
            </div>

        <?php elseif ($team !== null): ?>

            <header class="tournaments-heading">
                <p class="section-label">
                    NSBM SPORTSHUB / TEAM ROSTER
                </p>

                <h1><?= rosterEscape($team['team_name']) ?></h1>

                <p class="section-description">
                    <?= rosterEscape($team['club_name']) ?>
                    /
                    <?= rosterEscape($team['t_name']) ?>
                </p>
            </header>

            <?php if (is_string($flash)): ?>
                <div class="tournament-form-success" role="status">
                    <?= rosterEscape($flash) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="tournament-form-alert" role="alert">
                    <strong>Unable to complete the action:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= rosterEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!$canChange): ?>
                <div class="tournament-roster-note">
                    This roster is read-only because the
                    tournament has started, is no longer
                    upcoming, or already has fixtures.
                </div>
            <?php endif; ?>

            <div class="tournament-roster-layout">

                <section class="tournament-view-panel">
                    <div class="tournament-roster-heading">
                        <h2>Current roster</h2>
                        <span class="tournament-status">
                            <?= count($members) ?> members
                        </span>
                    </div>

                    <?php if (empty($members)): ?>

                        <p class="tournament-view-muted">
                            No students have been added
                            to this team yet.
                        </p>

                    <?php else: ?>

                        <div class="tournament-roster-list">
                            <?php foreach ($members as $member): ?>

                                <div class="tournament-roster-member">
                                    <div>
                                        <strong>
                                            <?= rosterEscape(
                                                $member['name']
                                            ) ?>
                                        </strong>
                                        <span>
                                            <?= rosterEscape(
                                                ucfirst(
                                                    (string) $member['role']
                                                )
                                            ) ?>
                                        </span>
                                    </div>

                                    <?php if ($canChange): ?>
                                        <form
                                            method="post"
                                            action="/tournaments/team-roster.php?id=<?= $teamId ?>">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= rosterEscape($csrfToken) ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="remove">

                                            <input
                                                type="hidden"
                                                name="member_id"
                                                value="<?= (int) $member['team_mem_id'] ?>">

                                            <button
                                                type="submit"
                                                class="button button-outline">
                                                Remove
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </section>

                <?php if ($canChange): ?>
                    <section class="tournament-view-panel">
                        <h2>Add a student</h2>

                        <?php if (empty($students)): ?>

                            <p class="tournament-view-muted">
                                No additional approved club
                                members are eligible.
                            </p>

                        <?php else: ?>

                            <form
                                method="post"
                                action="/tournaments/team-roster.php?id=<?= $teamId ?>"
                                class="tournament-roster-form">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= rosterEscape($csrfToken) ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="add">

                                <div class="tournament-field">
                                    <label for="student_id">
                                        Approved club member
                                    </label>

                                    <select
                                        id="student_id"
                                        name="student_id"
                                        required>

                                        <option value="">
                                            Select student
                                        </option>

                                        <?php foreach ($students as $student): ?>
                                            <option
                                                value="<?= (int) $student['user_id'] ?>">
                                                <?= rosterEscape(
                                                    $student['name']
                                                ) ?>
                                                (#<?= (int) $student['user_id'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="tournament-field">
                                    <label for="role">
                                        Team role
                                    </label>

                                    <select
                                        id="role"
                                        name="role"
                                        required>
                                        <option value="player">
                                            Player
                                        </option>
                                        <option value="captain">
                                            Captain
                                        </option>
                                    </select>
                                </div>

                                <button
                                    type="submit"
                                    class="button button-accent">
                                    Add to Roster
                                </button>
                            </form>

                        <?php endif; ?>
                    </section>
                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>