<?php

declare(strict_types=1);

/*
 * NSBM SportsHub
 * Member 06 - Match Editing and Result Management
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

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

function matchEditEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function matchEditInput(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function matchEditValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

/*
 * Retrieve a match only when the current user
 * is authorized to manage its tournament club.
 */
function matchEditFind(
    PDO $pdo,
    int $matchId,
    int $userId,
    string $userRole,
    bool $lock = false
): ?array {

    $scope = '';
    $params = ['match_id' => $matchId];

    if ($userRole !== 'admin') {
        $scope = "
            AND EXISTS (
                SELECT 1
                FROM sport_staff AS ss
                WHERE ss.club_id = t.club_id
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
            m.m_id,
            m.title,
            m.date,
            m.venue,
            m.match_status,
            m.home_score,
            m.away_score,
            t.t_id,
            t.t_name,
            t.sport,
            t.start_date,
            t.end_date,
            t.status AS tournament_status,
            home.team_name AS home_team,
            away.team_name AS away_team
        FROM matches AS m
        INNER JOIN tournaments AS t
            ON t.t_id = m.t_id
        INNER JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id
           AND sc.status = 'active'
        INNER JOIN teams AS home
            ON home.team_id = m.home_team_id
           AND home.t_id = t.t_id
           AND home.club_id = t.club_id
        INNER JOIN teams AS away
            ON away.team_id = m.away_team_id
           AND away.t_id = t.t_id
           AND away.club_id = t.club_id
        WHERE m.m_id = :match_id
          AND m.home_team_id <> m.away_team_id
          $scope
        LIMIT 1
    ";

    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $match = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($match) ? $match : null;
}

/* Session-based CSRF protection. */
if (
    !isset($_SESSION['match_edit_csrf']) ||
    !is_string($_SESSION['match_edit_csrf'])
) {
    $_SESSION['match_edit_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['match_edit_csrf'];

$matchId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

$match = null;
$errors = [];
$loadError = false;

$submittedAction = $method === 'POST'
    ? matchEditInput('action')
    : '';

$saved = $_GET['saved'] ?? '';
$saved = is_string($saved) ? $saved : '';

if (!is_int($matchId)) {
    http_response_code(400);
} else {
    try {
        $pdo = db();

        if ($method === 'POST') {

            if (
                !hash_equals(
                    $csrfToken,
                    matchEditInput('csrf_token')
                )
            ) {
                $errors[] = 'Invalid form token. Refresh and try again.';
            } else {

                $pdo->beginTransaction();

                // Lock the match before changing its data.
                $lockedMatch = matchEditFind(
                    $pdo,
                    $matchId,
                    $userId,
                    $userRole,
                    true
                );

                if ($lockedMatch === null) {
                    $pdo->rollBack();
                    http_response_code(404);
                } else {

                    $status = strtolower(trim(
                        (string) $lockedMatch['match_status']
                    ));

                    $scheduled = in_array(
                        $status,
                        ['scheduled', 'upcoming'],
                        true
                    );

                    $completed = in_array(
                        $status,
                        ['completed', 'finished'],
                        true
                    );

                    if ($submittedAction === 'fixture') {

                        if (!$scheduled) {
                            $errors[] =
                                'Only scheduled fixtures can be edited.';
                        }

                        $tournamentStatus = strtolower(trim(
                            (string) $lockedMatch['tournament_status']
                        ));

                        if (!in_array(
                            $tournamentStatus,
                            ['upcoming', 'ongoing'],
                            true
                        )) {
                            $errors[] =
                                'This tournament is not accepting fixture changes.';
                        }

                        $title = matchEditInput('title');
                        $date = matchEditInput('date');
                        $venue = matchEditInput('venue');

                        $titleLength = function_exists('mb_strlen')
                            ? mb_strlen($title, 'UTF-8')
                            : strlen($title);

                        $venueLength = function_exists('mb_strlen')
                            ? mb_strlen($venue, 'UTF-8')
                            : strlen($venue);

                        if ($titleLength < 3 || $titleLength > 100) {
                            $errors[] =
                                'Match title must contain 3–100 characters.';
                        }

                        if ($venueLength < 2 || $venueLength > 255) {
                            $errors[] =
                                'Venue must contain 2–255 characters.';
                        }

                        if (!matchEditValidDate($date)) {
                            $errors[] = 'Enter a valid match date.';
                        } elseif (
                            $date < $today ||
                            $date < $lockedMatch['start_date'] ||
                            $date > $lockedMatch['end_date']
                        ) {
                            $errors[] =
                                'Match date must be upcoming and within the tournament dates.';
                        }

                        if ($errors === []) {
                            $update = $pdo->prepare(
                                "UPDATE matches
                                 SET title = :title,
                                     date = :match_date,
                                     venue = :venue
                                 WHERE m_id = :match_id"
                            );

                            $update->execute([
                                'title' => $title,
                                'match_date' => $date,
                                'venue' => $venue,
                                'match_id' => $matchId
                            ]);
                        }
                    } elseif ($submittedAction === 'result') {

                        if (!$scheduled && !$completed) {
                            $errors[] =
                                'Results cannot be recorded for this match status.';
                        }

                        if (
                            (string) $lockedMatch['date'] > $today
                        ) {
                            $errors[] =
                                'Results can only be entered on or after the match date.';
                        }

                        $homeScore = filter_var(
                            matchEditInput('home_score'),
                            FILTER_VALIDATE_INT,
                            [
                                'options' => [
                                    'min_range' => 0,
                                    'max_range' => 999
                                ]
                            ]
                        );

                        $awayScore = filter_var(
                            matchEditInput('away_score'),
                            FILTER_VALIDATE_INT,
                            [
                                'options' => [
                                    'min_range' => 0,
                                    'max_range' => 999
                                ]
                            ]
                        );

                        if (
                            $homeScore === false ||
                            $awayScore === false
                        ) {
                            $errors[] =
                                'Enter valid non-negative scores (0–999).';
                        }

                        if ($errors === []) {
                            $update = $pdo->prepare(
                                "UPDATE matches
                                 SET home_score = :home_score,
                                     away_score = :away_score,
                                     match_status = 'completed'
                                 WHERE m_id = :match_id"
                            );

                            $update->execute([
                                'home_score' => $homeScore,
                                'away_score' => $awayScore,
                                'match_id' => $matchId
                            ]);
                        }
                    } else {
                        $errors[] = 'Invalid management action.';
                    }

                    if ($errors === []) {
                        $pdo->commit();

                        $savedAction = $submittedAction === 'fixture'
                            ? 'fixture'
                            : 'result';

                        header(
                            'Location: /matches/edit.php?id=' .
                                $matchId .
                                '&saved=' . $savedAction
                        );
                        exit;
                    }

                    $pdo->rollBack();
                }
            }
        }

        // Load the current authorized record for display.
        $match = matchEditFind(
            $pdo,
            $matchId,
            $userId,
            $userRole
        );

        if ($match === null) {
            http_response_code(404);
        }
    } catch (Throwable $error) {

        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Match edit error: ' . $error->getMessage()
        );

        http_response_code(500);
        $loadError = true;
    }
}

$status = $match !== null
    ? strtolower(trim((string) $match['match_status']))
    : '';

$isScheduled = in_array(
    $status,
    ['scheduled', 'upcoming'],
    true
);

$isCompleted = in_array(
    $status,
    ['completed', 'finished'],
    true
);

$tournamentActive = $match !== null
    && in_array(
        strtolower(trim((string) $match['tournament_status'])),
        ['upcoming', 'ongoing'],
        true
    );

$canEditFixture = $isScheduled && $tournamentActive;

$canRecordResult = $match !== null
    && ($isScheduled || $isCompleted)
    && (string) $match['date'] <= $today;

$pageStylesheets = ['/assets/css/matches.css'];

include __DIR__ . '/../includes/header.php';
?>

<main class="matches-page">
    <section class="section-container matches-section">

        <div class="matches-header">
            <p class="section-label">
                NSBM SPORTSHUB / MATCH MANAGEMENT
            </p>

            <h1>Manage fixture.</h1>

            <p class="section-description">
                Update match details and record tournament results.
            </p>
        </div>

        <div class="match-management-actions">
            <a
                href="/matches/manage.php"
                class="button button-outline">
                Back to match management
            </a>

            <?php if ($match !== null): ?>
                <a
                    href="/matches/view.php?id=<?= (int) $match['m_id'] ?>"
                    class="button button-outline">
                    View public match details
                </a>
            <?php endif; ?>
        </div>

        <?php if ($loadError): ?>

            <div class="matches-empty">
                <h2>Unable to load match information.</h2>
                <p>Please try again later.</p>
            </div>

        <?php elseif ($match === null): ?>

            <div class="matches-empty">
                <h2>Match unavailable.</h2>
                <p>
                    The match does not exist or you do not
                    have permission to manage it.
                </p>
            </div>

        <?php else: ?>

            <div class="match-edit-summary">
                <p class="section-label">
                    <?= matchEditEscape($match['t_name']) ?>
                </p>

                <h2>
                    <?= matchEditEscape($match['title']) ?>
                </h2>

                <p>
                    <?= matchEditEscape($match['home_team']) ?>
                    vs
                    <?= matchEditEscape($match['away_team']) ?>
                </p>

                <p>
                    <?= matchEditEscape($match['sport']) ?>
                    ·
                    <?= matchEditEscape($match['date']) ?>
                    ·
                    <?= matchEditEscape($match['venue']) ?>
                </p>

                <p>
                    Status:
                    <strong>
                        <?= matchEditEscape(ucfirst($status)) ?>
                    </strong>
                </p>
            </div>

            <?php if ($saved === 'fixture'): ?>
                <div class="match-edit-success" role="status">
                    Match fixture updated successfully.
                </div>
            <?php elseif ($saved === 'result'): ?>
                <div class="match-edit-success" role="status">
                    Match result saved successfully.
                    Tournament standings will reflect the result.
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="match-create-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= matchEditEscape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canEditFixture): ?>

                <div class="match-edit-heading">
                    <h2>Edit fixture details</h2>
                    <p>
                        Modify the title, match date or venue.
                        The participating teams remain unchanged.
                    </p>
                </div>

                <form
                    method="post"
                    action="/matches/edit.php?id=<?= (int) $matchId ?>"
                    class="match-create-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= matchEditEscape($csrfToken) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="fixture">

                    <label for="edit_match_title">
                        Match title
                    </label>

                    <input
                        id="edit_match_title"
                        name="title"
                        type="text"
                        maxlength="100"
                        required
                        value="<?= matchEditEscape(
                                    $submittedAction === 'fixture'
                                        ? matchEditInput('title')
                                        : $match['title']
                                ) ?>">

                    <label for="edit_match_date">
                        Match date
                    </label>

                    <input
                        id="edit_match_date"
                        name="date"
                        type="date"
                        required
                        min="<?= matchEditEscape($today) ?>"
                        value="<?= matchEditEscape(
                                    $submittedAction === 'fixture'
                                        ? matchEditInput('date')
                                        : $match['date']
                                ) ?>">

                    <label for="edit_match_venue">
                        Venue
                    </label>

                    <input
                        id="edit_match_venue"
                        name="venue"
                        type="text"
                        maxlength="255"
                        required
                        value="<?= matchEditEscape(
                                    $submittedAction === 'fixture'
                                        ? matchEditInput('venue')
                                        : $match['venue']
                                ) ?>">

                    <button
                        type="submit"
                        class="button button-accent">
                        Save fixture changes
                    </button>
                </form>

            <?php endif; ?>

            <div class="match-edit-heading">
                <h2>Match result</h2>
                <p>
                    Enter both scores to complete the fixture.
                    Completed results can also be corrected.
                </p>
            </div>

            <?php if ($canRecordResult): ?>

                <form
                    method="post"
                    action="/matches/edit.php?id=<?= (int) $matchId ?>"
                    class="match-create-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= matchEditEscape($csrfToken) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="result">

                    <label for="home_score">
                        <?= matchEditEscape($match['home_team']) ?>
                        score
                    </label>

                    <input
                        id="home_score"
                        name="home_score"
                        type="number"
                        min="0"
                        max="999"
                        step="1"
                        required
                        value="<?= matchEditEscape(
                                    $submittedAction === 'result'
                                        ? matchEditInput('home_score')
                                        : $match['home_score']
                                ) ?>">

                    <label for="away_score">
                        <?= matchEditEscape($match['away_team']) ?>
                        score
                    </label>

                    <input
                        id="away_score"
                        name="away_score"
                        type="number"
                        min="0"
                        max="999"
                        step="1"
                        required
                        value="<?= matchEditEscape(
                                    $submittedAction === 'result'
                                        ? matchEditInput('away_score')
                                        : $match['away_score']
                                ) ?>">

                    <button
                        type="submit"
                        class="button button-accent">
                        <?= $isCompleted
                            ? 'Update match result'
                            : 'Save completed result' ?>
                    </button>

                </form>

            <?php else: ?>

                <div class="match-edit-notice">
                    <?php if ($isScheduled): ?>
                        Results can be entered on or after
                        the scheduled match date.
                    <?php else: ?>
                        Result editing is unavailable
                        for this match status.
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>