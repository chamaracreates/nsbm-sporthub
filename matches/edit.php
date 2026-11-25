<?php

declare(strict_types=1);

/*
 * NSBM SportsHub
 * Match editing and result management.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

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

    // Independent fixtures do not require tournament or team references.
    // Staff scope below excludes standalone records; admin may access both.
    $sql = "
        SELECT
            m.m_id, m.title, m.date, m.venue, m.match_status,
            m.home_score, m.away_score, m.description, m.image_path,
            m.home_wickets, m.away_wickets, m.home_balls, m.away_balls,
            t.t_id, COALESCE(t.t_name, 'Standalone match') AS t_name,
            m.sport, t.start_date, t.end_date,
            t.status AS tournament_status,
            m.home_name AS home_team, m.away_name AS away_team
        FROM matches AS m
        LEFT JOIN tournaments AS t ON t.t_id = m.t_id
        LEFT JOIN sports_clubs AS sc
            ON sc.club_id = t.club_id AND sc.status = 'active'
        LEFT JOIN teams AS home
            ON home.team_id = m.home_team_id
           AND home.t_id = t.t_id AND home.club_id = t.club_id
        LEFT JOIN teams AS away
            ON away.team_id = m.away_team_id
           AND away.t_id = t.t_id AND away.club_id = t.club_id
        WHERE m.m_id = :match_id
          AND (
              (m.t_id IS NULL
               AND m.home_team_id IS NULL AND m.away_team_id IS NULL
               AND m.home_u_id IS NULL AND m.away_u_id IS NULL)
              OR
              (m.t_id IS NOT NULL AND sc.club_id IS NOT NULL
               AND home.team_id IS NOT NULL AND away.team_id IS NOT NULL
               AND m.home_team_id <> m.away_team_id)
          )
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
    ? sportsHubInput('action')
    : '';

$mode = $_GET['mode'] ?? '';
$mode = in_array($mode, ['fixture', 'result', 'details'], true) ? $mode : '';

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
                    sportsHubInput('csrf_token')
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

                        $isStandalone = $lockedMatch['t_id'] === null;
                        $tournamentStatus = strtolower(trim(
                            (string) $lockedMatch['tournament_status']
                        ));

                        if (!$isStandalone && !in_array(
                            $tournamentStatus,
                            ['upcoming', 'ongoing'],
                            true
                        )) {
                            $errors[] =
                                'This tournament is not accepting fixture changes.';
                        }

                        $title = sportsHubInput('title');
                        $date = sportsHubInput('date');
                        $venue = sportsHubInput('venue');
                        $homeName = sportsHubInput('home_name');
                        $awayName = sportsHubInput('away_name');
                        $sport = sportsHubInput('sport');

                        $titleLength = sportsHubLength($title);

                        $venueLength = sportsHubLength($venue);

                        if ($titleLength < 3 || $titleLength > 100) {
                            $errors[] =
                                'Match title must contain 3–100 characters.';
                        }

                        if ($venueLength < 2 || $venueLength > 255) {
                            $errors[] =
                                'Venue must contain 2–255 characters.';
                        }

                        if ($isStandalone) {
                            foreach ([
                                ['Home team', $homeName, 2, 255],
                                ['Away team', $awayName, 2, 255],
                                ['Sport', $sport, 2, 255],
                            ] as [$label, $value, $minimum, $maximum]) {
                                $size = sportsHubLength($value);
                                if ($size < $minimum || $size > $maximum) {
                                    $errors[] = sprintf('%s must contain %d–%d characters.', $label, $minimum, $maximum);
                                }
                            }
                            if (strcasecmp($homeName, $awayName) === 0) {
                                $errors[] = 'Home and away teams must differ.';
                            }
                        }

                        if (!sportsHubValidDate($date)) {
                            $errors[] = 'Enter a valid match date.';
                        } elseif (
                            $date < $today ||
                            (!$isStandalone && (
                                $date < $lockedMatch['start_date'] ||
                                $date > $lockedMatch['end_date']
                            ))
                        ) {
                            $errors[] = $isStandalone
                                ? 'Match date cannot be in the past.'
                                : 'Match date must be upcoming and within the tournament dates.';
                        }

                        if ($errors === []) {
                            if ($isStandalone) {
                                $update = $pdo->prepare(
                                    "UPDATE matches
                                     SET title = :title, date = :match_date,
                                         venue = :venue, home_name = :home_name,
                                         away_name = :away_name, sport = :sport
                                     WHERE m_id = :match_id AND t_id IS NULL"
                                );
                                $update->execute([
                                    'title' => $title,
                                    'match_date' => $date,
                                    'venue' => $venue,
                                    'home_name' => $homeName,
                                    'away_name' => $awayName,
                                    'sport' => $sport,
                                    'match_id' => $matchId,
                                ]);
                            } else {
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
                        }
                    } elseif ($submittedAction === 'details') {
                        $title = sportsHubInput('title');
                        $description = sportsHubInput('description');
                        $image = sportsHubInput('image_path');
                        if (sportsHubLength($title) < 3 || sportsHubLength($title) > 100 || sportsHubLength($description) > 255) {
                            $errors[] = 'Enter a title of 3–100 characters and a description of up to 255 characters.';
                        }
                        if (!array_key_exists($image, sportsHubCoverLibrary())) $errors[] = 'Choose a cover from the image library.';
                        if ($errors === []) {
                            $update = $pdo->prepare('UPDATE matches SET title = :title, description = :description, image_path = :image_path WHERE m_id = :match_id');
                            $update->execute(['title' => $title, 'description' => $description === '' ? null : $description, 'image_path' => $image ?: null, 'match_id' => $matchId]);
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
                            sportsHubInput('home_score'),
                            FILTER_VALIDATE_INT,
                            [
                                'options' => [
                                    'min_range' => 0,
                                    'max_range' => 999
                                ]
                            ]
                        );

                        $awayScore = filter_var(
                            sportsHubInput('away_score'),
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

                        $cricket = sportsHubIsCricket((string) $lockedMatch['sport']);
                        $extras = ['home_wickets' => null, 'away_wickets' => null, 'home_balls' => null, 'away_balls' => null];
                        if ($cricket) {
                            foreach (['home', 'away'] as $side) {
                                $wickets = sportsHubInput($side . '_wickets');
                                $extras[$side . '_wickets'] = $wickets === '' ? null : filter_var($wickets, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10]]);
                                $extras[$side . '_balls'] = sportsHubOversBalls(sportsHubInput($side . '_overs'));
                                if ($extras[$side . '_wickets'] === false || $extras[$side . '_balls'] === false) $errors[] = 'Enter wickets from 0 to 10 and overs using legal balls (for example, 19.5).';
                            }
                        }
                        if ($errors === []) {
                            $update = $pdo->prepare(
                                "UPDATE matches
                                 SET home_score = :home_score,
                                     away_score = :away_score,
                                     home_wickets = :home_wickets, away_wickets = :away_wickets,
                                     home_balls = :home_balls, away_balls = :away_balls,
                                     match_status = 'completed'
                                 WHERE m_id = :match_id"
                            );

                            $update->execute([
                                'home_score' => $homeScore,
                                'away_score' => $awayScore,
                                'match_id' => $matchId
                            ] + $extras);
                        }
                    } else {
                        $errors[] = 'Invalid management action.';
                    }

                    if ($errors === []) {
                        $pdo->commit();

                        $savedAction = $submittedAction;

                        header(
                            'Location: /matches/edit.php?id=' .
                                $matchId .
                                '&mode=' . $savedAction . '&saved=' . $savedAction
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
    && ($match['t_id'] === null && $userRole === 'admin'
        || in_array(
            strtolower(trim((string) $match['tournament_status'])),
            ['upcoming', 'ongoing'],
            true
        ));

$canEditFixture = $isScheduled && $tournamentActive;

$canRecordResult = $match !== null
    && ($isScheduled || $isCompleted)
    && (string) $match['date'] <= $today;

$pageStylesheets = ['/assets/css/matches.css'];

include __DIR__ . '/../includes/header.php';
?>

<main class="matches-page sports-hub-workflow match-management-page match-editor-page">
    <section class="section-container matches-section">

        <div class="matches-header">
            <p class="section-label">
                NSBM SPORTSHUB / MATCH MANAGEMENT
            </p>

            <h1><?= $mode === 'result' ? ($isCompleted ? 'Edit result.' : 'Record result.') : ($mode === 'fixture' ? 'Edit fixture.' : 'Edit match details.') ?></h1>

            <p class="section-description">
                <?= $mode === 'result' ? 'Enter or correct the scores for this match.' : 'Update fixture details and manage match information.' ?>
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

            <div class="match-editor-layout">
            <div class="match-edit-summary">
                <p class="section-label">
                    <?= sportsHubEscape($match['t_name']) ?>
                </p>

                <h2>
                    <?= sportsHubEscape($match['title']) ?>
                </h2>

                <p>
                    <?= sportsHubEscape($match['home_team']) ?>
                    vs
                    <?= sportsHubEscape($match['away_team']) ?>
                </p>

                <p>
                    <?= sportsHubEscape($match['sport']) ?>
                    ·
                    <?= sportsHubEscape($match['date']) ?>
                    ·
                    <?= sportsHubEscape($match['venue']) ?>
                </p>

                <p>
                    Status:
                    <strong>
                        <?= sportsHubEscape(ucfirst($status)) ?>
                    </strong>
                </p>
                <?php if ($isCompleted): ?>
                    <p class="match-editor-score"><?= sportsHubEscape(sportsHubMatchScore($match, 'home', true)) ?> – <?= sportsHubEscape(sportsHubMatchScore($match, 'away', true)) ?></p>
                <?php endif; ?>
                <a href="/matches/edit.php?id=<?= (int) $matchId ?>&amp;mode=details" class="button button-outline">Edit match details</a>
            </div>
            <div class="match-editor-panel">

            <?php if ($saved === 'details'): ?>
                <div class="match-edit-success" role="status">Match details saved successfully.</div>
            <?php elseif ($saved === 'fixture'): ?>
                <div class="match-edit-success" role="status">
                    Match fixture updated successfully.
                </div>
            <?php elseif ($saved === 'result'): ?>
                <div class="match-edit-success" role="status">
                    Match result saved successfully.
                    <?php if ($match['t_id'] !== null): ?>Tournament standings will reflect the result.<?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="match-create-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= sportsHubEscape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'details'): ?>
                <div class="match-edit-heading"><h2>Match details</h2><p>Update public content without changing teams, dates or scores.</p></div>
                <form method="post" action="/matches/edit.php?id=<?= (int) $matchId ?>&amp;mode=details" class="match-create-form">
                    <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="details">
                    <label for="match-title">Match title</label>
                    <input id="match-title" name="title" required maxlength="100" value="<?= sportsHubEscape($submittedAction === 'details' ? sportsHubInput('title') : $match['title']) ?>">
                    <label for="match-description">Description</label>
                    <textarea id="match-description" name="description" rows="4" maxlength="255"><?= sportsHubEscape($submittedAction === 'details' ? sportsHubInput('description') : $match['description']) ?></textarea>
                    <label for="match-cover">Cover image</label>
                    <select id="match-cover" name="image_path"><?= sportsHubCoverOptions($submittedAction === 'details' ? sportsHubInput('image_path') : (string) $match['image_path']) ?></select>
                    <button type="submit" class="button button-accent">Save match details</button>
                </form>
            <?php endif; ?>

            <?php if ($canEditFixture && $mode !== 'result' && $mode !== 'details'): ?>

                <div class="match-edit-heading">
                    <h2>Edit fixture details</h2>
                    <p>
                        Update title, date and venue.
                        Tournament team selections stay unchanged.
                    </p>
                </div>

                <form
                    method="post"
                    action="/matches/edit.php?id=<?= (int) $matchId ?>&amp;mode=fixture"
                    class="match-create-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= sportsHubEscape($csrfToken) ?>">

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
                        value="<?= sportsHubEscape(
                                    $submittedAction === 'fixture'
                                        ? sportsHubInput('title')
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
                        min="<?= sportsHubEscape($today) ?>"
                        value="<?= sportsHubEscape(
                                    $submittedAction === 'fixture'
                                        ? sportsHubInput('date')
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
                        value="<?= sportsHubEscape(
                                    $submittedAction === 'fixture'
                                        ? sportsHubInput('venue')
                                        : $match['venue']
                                ) ?>">

                    <?php if ($match['t_id'] === null && $userRole === 'admin'): ?>
                        <label for="standalone_edit_home">Home team</label>
                        <input id="standalone_edit_home" name="home_name" maxlength="255" required
                               value="<?= sportsHubEscape(
                                   $submittedAction === 'fixture' ? sportsHubInput('home_name') : $match['home_team']
                               ) ?>">
                        <label for="standalone_edit_away">Away team</label>
                        <input id="standalone_edit_away" name="away_name" maxlength="255" required
                               value="<?= sportsHubEscape(
                                   $submittedAction === 'fixture' ? sportsHubInput('away_name') : $match['away_team']
                               ) ?>">
                        <label for="standalone_edit_sport">Sport</label>
                        <input id="standalone_edit_sport" name="sport" maxlength="255" required
                               value="<?= sportsHubEscape(
                                   $submittedAction === 'fixture' ? sportsHubInput('sport') : $match['sport']
                               ) ?>">
                    <?php endif; ?>

                    <button
                        type="submit"
                        class="button button-accent">
                        Save fixture changes
                    </button>
                </form>

            <?php endif; ?>

            <?php if ($mode !== 'details' && ($mode !== 'fixture' || !$canEditFixture)): ?>
            <div class="match-edit-heading" id="match-result">
                <h2>Match result</h2>
                <p>
                    Enter both scores to complete the fixture.
                    Completed results can also be corrected.
                </p>
            </div>

            <?php if ($canRecordResult): ?>

                <form
                    method="post"
                    action="/matches/edit.php?id=<?= (int) $matchId ?>&amp;mode=result"
                    class="match-create-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= sportsHubEscape($csrfToken) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="result">

                    <?php $cricket = sportsHubIsCricket((string) $match['sport']); ?>
                    <?php if ($cricket): ?>
                        <p class="section-description">Single-innings cricket: enter total runs and optionally wickets lost and overs bowled (e.g. 19.5 means 19 overs and 5 balls). Results use total runs.</p>
                    <?php endif; ?>
                    <div class="match-result-fields">
                        <?php foreach (['home', 'away'] as $side): ?>
                            <div class="match-result-team">
                                <label for="<?= $side ?>_score"><?= sportsHubEscape($match[$side . '_team']) ?> · <?= $cricket ? 'Runs' : (strcasecmp((string) $match['sport'], 'Volleyball') === 0 ? 'Sets' : 'Score') ?></label>
                                <input id="<?= $side ?>_score" name="<?= $side ?>_score" type="number" min="0" max="999" step="1" required value="<?= sportsHubEscape($submittedAction === 'result' ? sportsHubInput($side . '_score') : ($isCompleted ? $match[$side . '_score'] : '')) ?>">
                                <?php if ($cricket): ?>
                                    <label for="<?= $side ?>_wickets">Wickets lost (optional)</label>
                                    <input id="<?= $side ?>_wickets" name="<?= $side ?>_wickets" type="number" min="0" max="10" step="1" value="<?= sportsHubEscape($submittedAction === 'result' ? sportsHubInput($side . '_wickets') : $match[$side . '_wickets']) ?>">
                                    <label for="<?= $side ?>_overs">Overs bowled (optional)</label>
                                    <input id="<?= $side ?>_overs" name="<?= $side ?>_overs" type="text" inputmode="decimal" maxlength="5" pattern="[0-9]{1,3}([.][0-5])?" value="<?= sportsHubEscape($submittedAction === 'result' ? sportsHubInput($side . '_overs') : sportsHubOversText($match[$side . '_balls'])) ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

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
                    <?php if ($isScheduled || $isCompleted): ?>
                        Results can be entered on or after
                        the scheduled match date.
                    <?php else: ?>
                        Result editing is unavailable
                        for this match status.
                    <?php endif; ?>
                </div>

            <?php endif; ?>
            <?php endif; ?>
            </div>
            </div>

        <?php endif; ?>

    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>