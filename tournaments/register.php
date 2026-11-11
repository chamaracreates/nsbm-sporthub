<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/* Student-only tournament registration. */
$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

function tournamentRegisterEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function tournamentRegistrationOpen(
    array $tournament,
    string $today
): bool {
    return strtolower(
        trim((string) $tournament['status'])
    ) === 'upcoming'
        && (string) $tournament['reg_open_at'] <= $today
        && (string) $tournament['reg_end_at'] >= $today
        && (string) $tournament['start_date'] > $today;
}

$tournamentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Colombo')
))->format('Y-m-d');

/* Prepare CSRF protection. */
if (
    !isset($_SESSION['tournament_register_csrf']) ||
    !is_string($_SESSION['tournament_register_csrf'])
) {
    $_SESSION['tournament_register_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tournament_register_csrf'];

$tournament = null;
$registration = null;
$pageError = null;
$errors = [];
$isOpen = false;
$success = false;
$pdo = null;

if (!is_int($tournamentId)) {
    http_response_code(400);
    $pageError = 'Invalid tournament ID.';
} else {
    try {
        $pdo = db();

        /* Load an official tournament. */
        $statement = $pdo->prepare(
            "SELECT
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
             WHERE t.t_id = :tournament_id
               AND sc.status = 'active'
             LIMIT 1"
        );

        $statement->execute([
            'tournament_id' => $tournamentId
        ]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($result)) {
            http_response_code(404);
            $pageError = 'Tournament not found.';
        } else {
            $tournament = $result;

            $isOpen = tournamentRegistrationOpen(
                $tournament,
                $today
            );

            /* Check the current student's registration. */
            $statement = $pdo->prepare(
                "SELECT
                    reg_id,
                    registration_status,
                    reg_date
                 FROM tournament_registration
                 WHERE t_id = :tournament_id
                   AND user_id = :user_id
                 LIMIT 1"
            );

            $statement->execute([
                'tournament_id' => $tournamentId,
                'user_id' => $userId
            ]);

            $existing = $statement->fetch(PDO::FETCH_ASSOC);

            if (is_array($existing)) {
                $registration = $existing;
            }

            if (
                ($_SESSION['tournament_register_success'] ?? null)
                === $tournamentId
            ) {
                $success = true;
                unset($_SESSION['tournament_register_success']);
            }
        }
    } catch (Throwable $error) {
        error_log(
            'Tournament registration loading: ' .
                $error->getMessage()
        );

        http_response_code(500);
        $pageError = 'Unable to load registration information.';
    }
}

/* Process the registration request. */
if (
    $method === 'POST' &&
    $tournament !== null &&
    $pdo instanceof PDO
) {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($csrfToken, $token)
    ) {
        http_response_code(403);
        $errors[] = 'Invalid form session. Please refresh.';
    } elseif ($registration !== null) {
        $errors[] =
            'You already have a registration for this tournament.';
    } elseif (!$isOpen) {
        $errors[] =
            'Tournament registration is not currently open.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the tournament row while validating
             * its registration window and status.
             */
            $statement = $pdo->prepare(
                "SELECT
                    t.t_id,
                    t.status,
                    t.reg_open_at,
                    t.reg_end_at,
                    t.start_date
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc
                    ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id
                   AND sc.status = 'active'
                 LIMIT 1
                 FOR UPDATE"
            );

            $statement->execute([
                'tournament_id' => $tournamentId
            ]);

            $current = $statement->fetch(PDO::FETCH_ASSOC);

            if (
                !is_array($current) ||
                !tournamentRegistrationOpen($current, $today)
            ) {
                $pdo->rollBack();

                $errors[] =
                    'Registration availability has changed.';
            } else {
                /*
                 * The database has a unique constraint
                 * on (t_id, user_id). This prevents
                 * duplicate registrations even if two
                 * submissions occur simultaneously.
                 */
                $insert = $pdo->prepare(
                    "INSERT INTO tournament_registration (
                        t_id,
                        user_id,
                        reg_date,
                        registration_status
                    ) VALUES (
                        :tournament_id,
                        :user_id,
                        :reg_date,
                        'pending'
                    )"
                );

                $insert->execute([
                    'tournament_id' => $tournamentId,
                    'user_id' => $userId,
                    'reg_date' => $today
                ]);

                $pdo->commit();

                $_SESSION['tournament_register_success'] =
                    $tournamentId;

                header(
                    'Location: /tournaments/register.php?id=' .
                        $tournamentId,
                    true,
                    303
                );
                exit;
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Tournament registration submission: ' .
                    $error->getMessage()
            );

            $errors[] =
                'Registration could not be completed. ' .
                'Please check your existing registration ' .
                'or try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';

?>

<link rel="stylesheet" href="/assets/css/tournaments.css">

<main class="tournaments-page">
    <section class="tournaments-container section-container">

        <div class="tournament-view-navigation">
            <a
                href="/tournaments/index.php"
                class="button button-outline">
                All Tournaments
            </a>
        </div>

        <header class="tournaments-heading">
            <p class="section-label">
                NSBM SPORTSHUB / REGISTRATION
            </p>

            <h1>Register for a tournament.</h1>

            <p class="section-description">
                Submit your participation request
                for an official NSBM sports tournament.
            </p>
        </header>

        <?php if ($pageError !== null): ?>

            <div class="tournaments-notice" role="alert">
                <h2>Registration unavailable</h2>
                <p><?= tournamentRegisterEscape($pageError) ?></p>
            </div>

        <?php elseif ($tournament !== null): ?>

            <article class="tournament-view-panel">

                <div class="tournament-card-top">
                    <span class="tournament-sport">
                        <?= tournamentRegisterEscape(
                            $tournament['sport']
                        ) ?>
                    </span>

                    <span class="tournament-status">
                        <?= tournamentRegisterEscape(
                            $tournament['status']
                        ) ?>
                    </span>
                </div>

                <h2>
                    <?= tournamentRegisterEscape(
                        $tournament['t_name']
                    ) ?>
                </h2>

                <p class="tournament-view-description">
                    Organized by
                    <?= tournamentRegisterEscape(
                        $tournament['club_name']
                    ) ?>
                </p>

                <dl class="tournament-view-facts">
                    <div>
                        <dt>Registration opens</dt>
                        <dd>
                            <?= tournamentRegisterEscape(
                                $tournament['reg_open_at']
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Registration closes</dt>
                        <dd>
                            <?= tournamentRegisterEscape(
                                $tournament['reg_end_at']
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Tournament starts</dt>
                        <dd>
                            <?= tournamentRegisterEscape(
                                $tournament['start_date']
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>Venue</dt>
                        <dd>
                            <?= tournamentRegisterEscape(
                                $tournament['venue']
                            ) ?>
                        </dd>
                    </div>
                </dl>

                <?php if ($success): ?>
                    <div
                        class="tournament-form-success"
                        role="status">
                        Registration submitted successfully.
                        Your application is awaiting review.
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div
                        class="tournament-form-alert"
                        role="alert">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li>
                                    <?= tournamentRegisterEscape(
                                        $error
                                    ) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($registration !== null): ?>

                    <div class="tournament-form-success">
                        <strong>Your registration status:</strong>
                        <?= tournamentRegisterEscape(
                            ucfirst(
                                (string) $registration['registration_status']
                            )
                        ) ?>
                    </div>

                    <p class="tournament-view-muted">
                        You already have a registration
                        record for this tournament.
                    </p>

                <?php elseif ($isOpen): ?>

                    <form
                        method="post"
                        action="/tournaments/register.php?id=<?= $tournamentId ?>"
                        class="tournament-form-actions">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= tournamentRegisterEscape(
                                        $csrfToken
                                    ) ?>">

                        <button
                            type="submit"
                            class="button button-accent">
                            Submit Registration
                        </button>

                    </form>

                <?php else: ?>

                    <p class="tournament-view-muted">
                        Registration is not currently open
                        for this tournament.
                    </p>

                <?php endif; ?>

            </article>

        <?php endif; ?>

    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>