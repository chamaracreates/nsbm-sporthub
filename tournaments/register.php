<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';

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


$tournamentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))
    ->format('Y-m-d');
$isUpcomingTournament = static fn(array $event): bool =>
    strtolower(trim((string) $event['status'])) === 'upcoming'
    && (int) ($event['is_historical'] ?? 1) === 0
    && (string) $event['start_date'] > $today;

if (
    !isset($_SESSION['tournament_register_csrf'])
    || !is_string($_SESSION['tournament_register_csrf'])
) {
    $_SESSION['tournament_register_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['tournament_register_csrf'];

$tournament = null;
$registration = null;
$profile = null;
$experienceLevels = ['Beginner', 'Intermediate', 'Advanced'];
$experienceDurations = ['No prior experience', 'Less than 1 year', '1-2 years', '3-5 years', 'More than 5 years'];
$competitionLevels = ['No previous competition', 'School / club', 'University', 'District / provincial', 'National / international'];
$experience = '';
$duration = '';
$competition = '';
$preferredEvent = '';
$achievements = '';
$conductConfirmed = false;
$notes = '';
$confirmed = false;
$rosterMember = false;
$isOpen = false;
$canDelete = false;
$pageError = null;
$errors = [];
$successMessage = null;
$withdrawMessage = null;
$withdrawSucceeded = false;
$pdo = null;

if (is_int($tournamentId)) {
    if (($_SESSION['tournament_register_success'] ?? null) === $tournamentId) {
        $successMessage = 'Registration submitted. Your application is awaiting review.';
        unset($_SESSION['tournament_register_success']);
    }

    if (($_SESSION['tournament_delete_success'] ?? null) === $tournamentId) {
        $successMessage = 'Your registration has been permanently deleted.';
        unset($_SESSION['tournament_delete_success']);
    }

    $withdrawFlash = $_SESSION['tournament_withdraw_flash'] ?? null;
    if (
        is_array($withdrawFlash)
        && ($withdrawFlash['tournament_id'] ?? null) === $tournamentId
    ) {
        $withdrawMessage = is_string($withdrawFlash['message'] ?? null)
            ? $withdrawFlash['message']
            : '';
        $withdrawSucceeded = ($withdrawFlash['success'] ?? false) === true;
        unset($_SESSION['tournament_withdraw_flash']);
    }
}

if (!is_int($tournamentId)) {
    http_response_code(400);
    $pageError = 'Invalid tournament ID.';
} else {
    try {
        $pdo = db();

        $statement = $pdo->prepare(
            "SELECT t.t_id, t.t_name, t.sport, t.venue, t.status,
                    t.reg_open_at, t.reg_end_at, t.start_date, t.is_historical, COALESCE(NULLIF(t.organizer_name, ''), sc.club_name, 'Organizer not published') AS club_name
             FROM tournaments AS t
             LEFT JOIN sports_clubs AS sc ON sc.club_id = t.club_id
             WHERE t.t_id = :tournament_id AND (sc.status = 'active' OR t.is_historical = 1)
                 AND LOWER(TRIM(t.status)) <> 'draft'
             LIMIT 1"
        );
        $statement->execute(['tournament_id' => $tournamentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            http_response_code(404);
            $pageError = 'Tournament not found.';
        } else {
            $tournament = $row;
            $isOpen = sportsHubRegistrationOpen($tournament, $today);

            $statement = $pdo->prepare(
                "SELECT reg_id, registration_status, reg_date, experience_level, participation_notes,
                        experience_duration, competition_level, preferred_event, sporting_achievements, declaration_accepted_at
                 FROM tournament_registration
                 WHERE t_id = :tournament_id AND user_id = :user_id
                 LIMIT 1"
            );
            $statement->execute([
                'tournament_id' => $tournamentId,
                'user_id' => $userId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $registration = is_array($row) ? $row : null;
            $experience = (string) ($registration['experience_level'] ?? '');
            $notes = (string) ($registration['participation_notes'] ?? '');
            $duration = (string) ($registration['experience_duration'] ?? '');
            $competition = (string) ($registration['competition_level'] ?? '');
            $preferredEvent = (string) ($registration['preferred_event'] ?? '');
            $achievements = (string) ($registration['sporting_achievements'] ?? '');

            $statement = $pdo->prepare(
                "SELECT u.name, u.email, sp.student_number, sp.study_program, sp.academic_year, sp.phone
                 FROM users AS u LEFT JOIN student_profiles AS sp ON sp.user_id = u.user_id
                 WHERE u.user_id = ? AND u.role = 'student' LIMIT 1"
            );
            $statement->execute([$userId]);
            $profile = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($registration !== null) {
                $statement = $pdo->prepare(
                    "SELECT 1
                     FROM team_members AS member
                     INNER JOIN teams AS team ON team.team_id = member.team_id
                     WHERE member.user_id = :user_id AND team.t_id = :tournament_id
                     LIMIT 1"
                );
                $statement->execute([
                    'user_id' => $userId,
                    'tournament_id' => $tournamentId,
                ]);
                $rosterMember = $statement->fetchColumn() !== false;
                $canDelete = !$rosterMember
                    && in_array($registration['registration_status'], ['withdrawn', 'rejected'], true)
                    && $isUpcomingTournament($tournament);
            }
        }
    } catch (Throwable $error) {
        error_log('Registration page error: ' . $error->getMessage());
        http_response_code(500);
        $pageError = 'Unable to load tournament registration.';
    }
}

if (
    $method === 'POST' && $pageError === null
    && $tournament !== null && $pdo instanceof PDO
) {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $action = $_POST['action'] ?? 'register';
    if ($action === 'register') {
        $experience = sportsHubInput('experience');
        $notes = sportsHubInput('participation_notes');
        $confirmed = ($_POST['confirm_details'] ?? null) === '1';
        $duration = sportsHubInput('experience_duration');
        $competition = sportsHubInput('competition_level');
        $preferredEvent = sportsHubInput('preferred_event');
        $achievements = sportsHubInput('sporting_achievements');
        $conductConfirmed = ($_POST['confirm_conduct'] ?? null) === '1';
    }
    $deleteId = filter_var($_POST['registration_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        $errors[] = 'Invalid form session. Refresh the page and try again.';
    } elseif (!in_array($action, ['register', 'delete'], true)) {
        http_response_code(400);
        $errors[] = 'Invalid registration action.';
    } elseif ($action === 'delete' && (!$canDelete || $deleteId !== (int) $registration['reg_id'])) {
        $errors[] = 'Only an unassigned withdrawn or rejected request for an upcoming tournament can be deleted.';
    } elseif ($action === 'register' && $registration !== null && (!in_array($registration['registration_status'], ['withdrawn', 'rejected'], true) || $rosterMember)) {
        $errors[] = 'A registration record already exists for this tournament.';
    } elseif ($action === 'register' && !$isOpen) {
        $errors[] = 'Registration is not currently open.';
    } elseif ($action === 'register' && ($profile === null || trim((string) ($profile['student_number'] ?? '')) === '')) {
        $errors[] = 'Complete your student ID in your profile before applying.';
    } elseif ($action === 'register' && !in_array($experience, $experienceLevels, true)) {
        $errors[] = 'Select your experience level.';
    } elseif ($action === 'register' && !in_array($duration, $experienceDurations, true)) {
        $errors[] = 'Select how long you have played this sport.';
    } elseif ($action === 'register' && !in_array($competition, $competitionLevels, true)) {
        $errors[] = 'Select your highest previous competition level.';
    } elseif ($action === 'register' && (preg_match('//u', $preferredEvent) !== 1 || sportsHubLength($preferredEvent) > 100 || (isset($_POST['preferred_event']) && !is_string($_POST['preferred_event'])))) {
        $errors[] = 'Preferred event or position must contain at most 100 characters.';
    } elseif ($action === 'register' && (preg_match('//u', $achievements) !== 1 || sportsHubLength($achievements) > 500 || (isset($_POST['sporting_achievements']) && !is_string($_POST['sporting_achievements'])))) {
        $errors[] = 'Sporting achievements must contain at most 500 characters.';
    } elseif ($action === 'register' && (preg_match('//u', $notes) !== 1 || sportsHubLength($notes) > 500 || (isset($_POST['participation_notes']) && !is_string($_POST['participation_notes'])))) {
        $errors[] = 'Participation notes must contain at most 500 characters.';
    } elseif ($action === 'register' && !$confirmed) {
        $errors[] = 'Confirm your student details and availability before applying.';
    } elseif ($action === 'register' && !$conductConfirmed) {
        $errors[] = 'Accept the tournament rules and application review declaration.';
    } else {
        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                "SELECT t.status, t.reg_open_at, t.reg_end_at, t.start_date, t.is_historical
                 FROM tournaments AS t
                 INNER JOIN sports_clubs AS sc ON sc.club_id = t.club_id
                 WHERE t.t_id = :tournament_id AND sc.status = 'active'
                 FOR UPDATE"
            );
            $statement->execute(['tournament_id' => $tournamentId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($current)) {
                throw new DomainException('Tournament is no longer available.');
            }
            if ($action === 'register' && !sportsHubRegistrationOpen($current, $today)) {
                throw new DomainException('The registration window is no longer open.');
            }
            if ($action === 'delete' && !$isUpcomingTournament($current)) {
                throw new DomainException('Deletion is no longer available for this tournament.');
            }

            $statement = $pdo->prepare('SELECT reg_id, registration_status FROM tournament_registration WHERE t_id = ? AND user_id = ? FOR UPDATE');
            $statement->execute([$tournamentId, $userId]);
            $existing = $statement->fetch();
            if ($existing) {
                if (!in_array($existing['registration_status'], ['withdrawn', 'rejected'], true)) {
                    throw new DomainException('A registration is already active.');
                }
                $statement = $pdo->prepare('SELECT 1 FROM team_members tm JOIN teams t ON t.team_id = tm.team_id WHERE t.t_id = ? AND tm.user_id = ?');
                $statement->execute([$tournamentId, $userId]);
                if ($statement->fetchColumn()) {
                    throw new DomainException('Contact tournament staff about your existing team.');
                }
            }

            if ($action === 'delete') {
                if (!$existing || (int) $existing['reg_id'] !== $deleteId) {
                    throw new DomainException('The registration has changed. Refresh and try again.');
                }
                $statement = $pdo->prepare("DELETE FROM tournament_registration WHERE reg_id = ? AND t_id = ? AND user_id = ? AND registration_status IN ('withdrawn', 'rejected')");
                $statement->execute([$existing['reg_id'], $tournamentId, $userId]);
                if ($statement->rowCount() !== 1) {
                    throw new DomainException('The registration has changed. Refresh and try again.');
                }
            } else {
                $acceptedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))->format('Y-m-d H:i:s');
                $application = [$experience, $duration, $competition, $preferredEvent !== '' ? $preferredEvent : null, $achievements !== '' ? $achievements : null, $notes !== '' ? $notes : null, $acceptedAt];
                if ($existing) {
                    $statement = $pdo->prepare("UPDATE tournament_registration SET registration_status = 'pending', reg_date = ?, experience_level = ?, experience_duration = ?, competition_level = ?, preferred_event = ?, sporting_achievements = ?, participation_notes = ?, declaration_accepted_at = ?, reviewed_by = NULL, reviewed_at = NULL WHERE reg_id = ? AND user_id = ?");
                    $statement->execute([$today, ...$application, $existing['reg_id'], $userId]);
                } else {
                    $statement = $pdo->prepare("INSERT INTO tournament_registration (t_id, user_id, reg_date, registration_status, experience_level, experience_duration, competition_level, preferred_event, sporting_achievements, participation_notes, declaration_accepted_at) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)");
                    $statement->execute([$tournamentId, $userId, $today, ...$application]);
                }
            }
            $pdo->commit();

            $_SESSION[$action === 'delete' ? 'tournament_delete_success' : 'tournament_register_success'] = $tournamentId;
            header('Location: /tournaments/register.php?id=' . $tournamentId, true, 303);
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$error instanceof DomainException) {
                error_log('Registration action error: ' . $error->getMessage());
            }
            $errors[] = $error instanceof DomainException ? $error->getMessage() : 'Unable to update registration. Refresh and try again.';
        }
    }
}

$pageTitle = 'Tournament registration';
$pageStylesheets = ['/assets/css/tournaments.css?v=20261127-registration6'];
require __DIR__ . '/../includes/header.php';
?>


<main class="tournaments-page sports-hub-workflow">
    <section class="tournaments-container section-container">
        <header class="tournaments-heading">
            <h1>Tournament registration</h1>
            <p class="section-description">Submit and manage your tournament registration.</p>
        </header>

        <div class="tournament-view-navigation">
            <a href="/tournaments/index.php" class="button button-outline">Back to tournaments</a>
            <a href="/student/registrations.php" class="button button-accent">My registrations</a>
        </div>
        <?php if ($pageError !== null): ?>
            <div class="tournaments-notice" role="alert">
                <h2>Registration unavailable</h2>
                <p><?= sportsHubEscape($pageError) ?></p>
            </div>
        <?php elseif ($tournament !== null): ?>
            <div class="membership-workflow registration-overview">
                <article class="tournament-view-panel tournament-form">
                    <div class="tournament-card-top">
                        <span class="tournament-sport"><?= sportsHubEscape($tournament['sport']) ?></span>
                        <span class="tournament-status"><?= sportsHubEscape($tournament['status']) ?></span>
                    </div>

                    <h2><?= sportsHubEscape($tournament['t_name']) ?></h2>
                    <p class="tournament-view-description">
                        Organized by <?= sportsHubEscape($tournament['club_name']) ?>
                    </p>

                    <dl class="tournament-view-facts">
                        <div>
                            <dt>Registration opens</dt>
                            <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_open_at'])) ?></dd>
                        </div>
                        <div>
                            <dt>Registration closes</dt>
                            <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['reg_end_at'])) ?></dd>
                        </div>
                        <div>
                            <dt>Tournament starts</dt>
                            <dd><?= sportsHubEscape(sportsHubTournamentDate($tournament['start_date'])) ?></dd>
                        </div>
                        <div>
                            <dt>Venue</dt>
                            <dd><?= sportsHubEscape($tournament['venue']) ?></dd>
                        </div>
                    </dl>

                    <?php if ($successMessage !== null || $withdrawMessage !== null || $errors !== []): ?>
                        <div class="registration-feedback">
                            <?php if ($successMessage !== null): ?>
                                <div class="tournament-form-success" role="status">
                                    <?= sportsHubEscape($successMessage) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($withdrawMessage !== null): ?>
                                <div class="<?= $withdrawSucceeded ? 'tournament-form-success' : 'tournament-form-alert' ?>"
                                    role="<?= $withdrawSucceeded ? 'status' : 'alert' ?>">
                                    <?= sportsHubEscape($withdrawMessage) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($errors !== []): ?>
                                <div class="tournament-form-alert" role="alert">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= sportsHubEscape($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($registration !== null): ?>
                        <div class="registration-state">
                            <span>Your registration status</span>
                            <span class="tournament-status registration-status-<?= sportsHubEscape($registration['registration_status']) ?>"><?= sportsHubEscape(ucfirst($registration['registration_status'])) ?></span>
                        </div>

                        <?php
                        $canWithdraw = in_array(
                            (string) $registration['registration_status'],
                            ['pending', 'approved'],
                            true
                        ) && $isUpcomingTournament($tournament);
                        ?>

                        <?php if ($canWithdraw && $rosterMember): ?>
                            <p class="tournament-view-muted">
                                You are assigned to a tournament team. Contact tournament staff to withdraw.
                            </p>
                        <?php elseif ($canWithdraw): ?>
                            <form method="post" action="/tournaments/withdraw.php"
                                class="tournament-form-actions"
                                onsubmit="return confirm('Withdraw this registration?');">
                                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                                <input type="hidden" name="tournament_id" value="<?= (int) $tournamentId ?>">
                                <button type="submit" class="button button-outline">Withdraw Registration</button>
                            </form>
                            <p class="tournament-view-muted">
                                You may register again while registration is open.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php $canRegister = $isOpen && !$rosterMember && ($registration === null || in_array($registration['registration_status'], ['withdrawn', 'rejected'], true)); ?>
                    <?php if ($canDelete): ?>
                        <form method="post" action="/tournaments/register.php?id=<?= (int) $tournamentId ?>"
                            class="tournament-form-actions"
                            onsubmit="return confirm('Permanently delete this registration? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                            <input type="hidden" name="registration_id" value="<?= (int) $registration['reg_id'] ?>">
                            <button type="submit" name="action" value="delete" class="button button-danger-outline">Delete registration</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isOpen): ?>
                        <p class="tournament-view-muted">Registration is not currently open.</p>
                    <?php endif; ?>
                </article>
                <?php if ($profile !== null && ($canRegister || $registration !== null)): ?>
                    <article class="tournament-form">
                        <div class="membership-card-heading"><h2>Student details</h2></div>
                        <dl class="tournament-form-grid membership-profile-facts">
                            <?php foreach (['name'=>'Name', 'student_number'=>'Student ID', 'email'=>'Email', 'study_program'=>'Degree programme', 'academic_year'=>'Academic year', 'phone'=>'Contact number'] as $key => $label): ?>
                                <div<?= in_array($key, ['email', 'study_program'], true) ? ' class="tournament-field-full"' : '' ?>>
                                    <dt><?= sportsHubEscape($label) ?></dt>
                                    <dd><?= sportsHubEscape($profile[$key] ?: 'Not provided') ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <div class="membership-card-actions"><a class="button button-accent" href="/student/edit-profile.php">Edit profile</a></div>
                    </article>
                <?php endif; ?>
            </div>
            <?php if ($profile !== null && ($canRegister || $registration !== null)): ?>
                <?php if ($canRegister && trim((string) ($profile['student_number'] ?? '')) !== ''): ?>
                    <form method="post" action="/tournaments/register.php?id=<?= (int) $tournamentId ?>" class="tournament-form registration-application">
                        <div class="membership-card-heading"><h2>Participation application</h2></div>
                        <p class="tournament-view-muted">Tell tournament staff about your sporting background and participation preferences. Fields marked * are required.</p>
                        <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="register">
                        <fieldset class="registration-fieldset">
                            <legend>Sporting background</legend>
                            <div class="tournament-form-grid">
                                <?php foreach ([
                                    ['experience', 'Experience level', $experienceLevels, $experience],
                                    ['experience_duration', 'Time playing this sport', $experienceDurations, $duration],
                                    ['competition_level', 'Highest previous competition level', $competitionLevels, $competition]
                                ] as [$field, $label, $options, $selected]): ?>
                                    <div class="tournament-field">
                                        <label for="<?= sportsHubEscape($field) ?>"><?= sportsHubEscape($label) ?> *</label>
                                        <select id="<?= sportsHubEscape($field) ?>" name="<?= sportsHubEscape($field) ?>" required>
                                            <option value="">Select an option</option>
                                            <?php foreach ($options as $option): ?>
                                                <option value="<?= sportsHubEscape($option) ?>"<?= $selected === $option ? ' selected' : '' ?>><?= sportsHubEscape($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                                <div class="tournament-field">
                                    <label for="preferred_event">Preferred event, discipline or position (optional)</label>
                                    <input id="preferred_event" name="preferred_event" type="text" maxlength="100" value="<?= sportsHubEscape($preferredEvent) ?>" aria-describedby="event-help" placeholder="e.g. Singles, doubles, defender">
                                    <small id="event-help">A preference only. Staff will confirm available events and team assignments.</small>
                                </div>
                                <div class="tournament-field tournament-field-full">
                                    <label for="sporting_achievements">Previous teams, competitions and achievements (optional)</label>
                                    <textarea id="sporting_achievements" name="sporting_achievements" maxlength="500" rows="3" aria-describedby="achievements-help"><?= sportsHubEscape($achievements) ?></textarea>
                                    <small id="achievements-help">Include relevant results, awards or teams represented. Maximum 500 characters.</small>
                                </div>
                            </div>
                        </fieldset>
                        <fieldset class="registration-fieldset">
                            <legend>Participation information</legend>
                            <div class="tournament-form-grid">
                                <div class="tournament-field tournament-field-full">
                                    <label for="participation_notes">Additional participation notes (optional)</label>
                                    <textarea id="participation_notes" name="participation_notes" maxlength="500" rows="3" aria-describedby="notes-help"><?= sportsHubEscape($notes) ?></textarea>
                                    <small id="notes-help">Mention scheduling or equipment arrangements, or other relevant information. Do not include sensitive medical information. Maximum 500 characters.</small>
                                </div>
                            </div>
                        </fieldset>
                        <fieldset class="registration-fieldset">
                            <legend>Applicant declarations</legend>
                            <div class="registration-declarations">
                                <label class="registration-confirmation">
                                    <input type="checkbox" name="confirm_details" value="1" required<?= $confirmed ? ' checked' : '' ?>>
                                    <span>I confirm that my student and sporting details are accurate and that I am available to participate on the published dates. *</span>
                                </label>
                                <label class="registration-confirmation">
                                    <input type="checkbox" name="confirm_conduct" value="1" required<?= $conductConfirmed ? ' checked' : '' ?>>
                                    <span>I agree to follow tournament rules and fair-play standards, and understand that authorized tournament staff will review this application. *</span>
                                </label>
                                <p class="tournament-view-muted">Submitting an application does not guarantee a place. Check My registrations for the review decision.</p>
                            </div>
                        </fieldset>
                        <div class="membership-card-actions">
                            <button type="submit" class="button button-accent"><?= $registration === null ? 'Submit application' : 'Register again' ?></button>
                        </div>
                    </form>
                <?php elseif ($canRegister): ?>
                    <article class="tournament-form registration-application">
                        <div class="membership-card-heading"><h2>Complete your profile</h2></div>
                        <p class="tournament-view-muted">Add your student ID before submitting an application.</p>
                    </article>
                <?php else: ?>
                    <article class="tournament-form registration-application">
                        <div class="membership-card-heading"><h2>Submitted application</h2></div>
                        <dl class="tournament-form-grid membership-profile-facts">
                            <?php foreach (['Experience level'=>$experience, 'Time playing this sport'=>$duration, 'Highest previous competition level'=>$competition, 'Preferred event or position'=>$preferredEvent, 'Previous teams, competitions and achievements'=>$achievements, 'Participation notes'=>$notes, 'Declarations accepted (Sri Lanka time)'=>(string) ($registration['declaration_accepted_at'] ?? '')] as $label => $value): ?>
                                <div class="tournament-field-full"><dt><?= sportsHubEscape($label) ?></dt><dd><?= sportsHubEscape($value !== '' ? $value : 'Not recorded') ?></dd></div>
                            <?php endforeach; ?>
                            <div class="tournament-field-full"><dt>Submitted on</dt><dd><?= sportsHubEscape(sportsHubTournamentDate($registration['reg_date'])) ?></dd></div>
                        </dl>
                    </article>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>