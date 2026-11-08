<?php
// NSBM SportsHub - Student Participation History

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only logged-in students can access this page
$userId = require_login();

if (current_user_role() !== 'student') {
    http_response_code(403);
    exit('Access denied.');
}

// Retrieve the logged-in student's participation summary
$summary = null;
$errorMessage = null;

try {
    $stmt = db()->prepare(
        "SELECT
            student_name,
            email,
            total_clubs_joined,
            training_sessions_attended,
            tournaments_registered,
            facility_bookings_made
         FROM v_student_participation_summary
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

} catch (PDOException $e) {
    error_log('Participation history error: ' . $e->getMessage());
    $errorMessage = 'Unable to load participation history.';
}

 // Retrieve club membership history for this student
$memberships = [];
$membershipError = null;

try {
    $membershipStmt = db()->prepare(
        "SELECT
            sc.club_name,
            sc.sport,
            cm.status AS membership_status,
            cm.joined_date
         FROM club_membership cm
         INNER JOIN sports_clubs sc
             ON cm.club_id = sc.club_id
         WHERE cm.user_id = :user_id
         ORDER BY cm.joined_date DESC,
                  cm.mem_id DESC"
    );

    $membershipStmt->execute([
        ':user_id' => $userId
    ]);

    $memberships = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Membership history error: ' . $e->getMessage());
    $membershipError = 'Unable to load club membership history.';
}

 // Retrieve training attendance records for the logged-in student
$trainingHistory = [];
$trainingError = null;

try {
    $trainingStmt = db()->prepare(
        "SELECT
            ts.title,
            ts.date,
            ts.start_time,
            ts.end_time,
            sc.club_name,
            ta.att_status
         FROM training_attendance ta
         INNER JOIN training_sessions ts
             ON ta.session_id = ts.session_id
         INNER JOIN sports_clubs sc
             ON ts.club_id = sc.club_id
         WHERE ta.user_id = :user_id
         ORDER BY ts.date DESC,
                  ts.start_time DESC,
                  ts.session_id DESC"
    );

    $trainingStmt->execute([
        ':user_id' => $userId
    ]);

    $trainingHistory = $trainingStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Training history error: ' . $e->getMessage());
    $trainingError = 'Unable to load training attendance history.';
}

 // Retrieve tournament registrations for the logged-in student
$tournamentHistory = [];
$tournamentError = null;

try {
    $tournamentStmt = db()->prepare(
        "SELECT
            t.t_name,
            t.sport,
            t.start_date,
            t.end_date,
            t.venue,
            t.status AS tournament_status,
            tr.reg_date,
            tr.registration_status
         FROM tournament_registration tr
         INNER JOIN tournaments t
             ON tr.t_id = t.t_id
         WHERE tr.user_id = :user_id
         ORDER BY tr.reg_date DESC,
                  tr.reg_id DESC"
    );

    $tournamentStmt->execute([
        ':user_id' => $userId
    ]);

    $tournamentHistory =
        $tournamentStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log(
        'Tournament history error: ' . $e->getMessage()
    );

    $tournamentError =
        'Unable to load tournament registration history.';
}

 // Retrieve match registration history for the logged-in student
$matchHistory = [];
$matchError = null;

try {
    $matchStmt = db()->prepare(
        "SELECT
            m.title AS match_title,
            m.home_name,
            m.away_name,
            m.sport,
            m.date AS match_date,
            m.venue,
            m.match_status,
            m.home_score,
            m.away_score,
            mr.registration_status,
            mr.requested_at
         FROM match_registration mr
         INNER JOIN matches m
             ON mr.m_id = m.m_id
         WHERE mr.user_id = :user_id
         ORDER BY mr.requested_at DESC,
                  mr.match_reg_id DESC"
    );

    $matchStmt->execute([
        ':user_id' => $userId
    ]);

    $matchHistory = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log(
        'Match registration history error: ' . $e->getMessage()
    );

    $matchError = 'Unable to load match registration history.';
}

 // Retrieve facility and equipment bookings for this student
$bookingHistory = [];
$bookingError = null;

try {
    $bookingStmt = db()->prepare(
        "SELECT
            r.res_name,
            r.res_type,
            r.location,
            br.date,
            br.starts_at,
            br.ends_at,
            br.purpose,
            br.booking_status
         FROM booking_request br
         INNER JOIN resources r
             ON br.res_id = r.res_id
         WHERE br.user_id = :user_id
         ORDER BY br.date DESC,
                  br.starts_at DESC,
                  br.b_id DESC"
    );

    $bookingStmt->execute([
        ':user_id' => $userId
    ]);

    $bookingHistory = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log(
        'Facility booking history error: ' . $e->getMessage()
    );

    $bookingError = 'Unable to load facility booking history.';
}


?>


<?php require __DIR__ . '/../includes/header.php'; ?>

<link rel="stylesheet" href="/assets/css/student-accounts.css">

<main class="student-history-page">
    <div class="shell history-layout">

        <header class="history-heading">
            <p class="history-eyebrow">STUDENT ACTIVITY</p>

            <h1>My Sports Participation History</h1>

            <p class="history-subtitle">
                Track your sports activities, registrations
                and achievements.
            </p>
        </header>


<p>User ID (Internal): <?= (int) $userId ?></p>

<?php if ($errorMessage): ?>

    <p role="alert">
        <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
    </p>

<?php elseif (!$summary): ?>

    <p>No participation summary is available for your account yet.</p>

<?php else: ?>

    <section class="history-student-card"
         aria-labelledby="history-student-title">

    <h3 id="history-student-title">
        Student Information
    </h3>

    <div class="history-student-grid">

    <p>
        Student Name:
        <?= htmlspecialchars(
            (string) $summary['student_name'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

    <p>
        Email:
        <?= htmlspecialchars(
            (string) $summary['email'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
        </div>

</section>


<section class="history-overview"
         aria-labelledby="participation-overview-title">

    <h3 id="participation-overview-title">
        Participation Overview
    </h3>

    <div class="history-stats-grid">

        <article class="history-stat-card">
            <span class="history-stat-number">
                <?= (int) $summary['total_clubs_joined'] ?>
            </span>
            <p>Clubs Joined</p>
        </article>

        <article class="history-stat-card">
            <span class="history-stat-number">
                <?= (int) $summary['training_sessions_attended'] ?>
            </span>
            <p>Training Sessions Attended</p>
        </article>

        <article class="history-stat-card">
            <span class="history-stat-number">
                <?= (int) $summary['tournaments_registered'] ?>
            </span>
            <p>Tournaments Registered</p>
        </article>

        <article class="history-stat-card">
            <span class="history-stat-number">
                <?= (int) $summary['facility_bookings_made'] ?>
            </span>
            <p>Facility Bookings Made</p>
        </article>

    </div>

</section>


<?php endif; ?>


<section class="history-section history-membership-section"
         aria-labelledby="membership-history-title">

    <h3 id="membership-history-title">
        My Club Membership History
    </h3>

<?php if ($membershipError): ?>
<p class="history-section-error" role="alert">
        <?= htmlspecialchars(
            $membershipError,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

<?php elseif (empty($memberships)): ?>
<div class="history-empty-state">
    <p>You have not applied to any sports clubs yet.</p>

    <p class="history-empty-hint">
        Your club applications will appear here.
    </p>
</div>


<?php else: ?>


<div class="history-table-scroll"
     role="region"
     aria-label="Club membership records"
     tabindex="0">

    <table class="history-table">

        <thead>
            <tr>
                <th>Club Name</th>
                <th>Sport</th>
                <th>Membership Status</th>
                <th>Joined Date</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($memberships as $membership): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars(
                            (string) $membership['club_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $membership['sport'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            ucfirst((string) $membership['membership_status']),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) ($membership['joined_date'] ?? 'Not recorded'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

<?php endif; ?>
</section>


<section class="history-section history-training-section"
         aria-labelledby="training-history-title">

    <h3 id="training-history-title">
        My Training Attendance History
    </h3>


<?php if ($trainingError): ?>

    <p class="history-section-error" role="alert">
        <?= htmlspecialchars($trainingError, ENT_QUOTES, 'UTF-8') ?>
    </p>

<?php elseif (empty($trainingHistory)): ?>


<div class="history-empty-state">
    <p>You have no recorded training attendance yet.</p>

    <p class="history-empty-hint">
        Your training attendance records will appear here.
    </p>
</div>


<?php else: ?>


<div class="history-table-scroll"
     role="region"
     aria-label="Training attendance records"
     tabindex="0">

    <table class="history-table">

        <thead>
            <tr>
                <th>Training Session</th>
                <th>Sports Club</th>
                <th>Date</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Attendance Status</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($trainingHistory as $training): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars(
                            (string) $training['title'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $training['club_name'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $training['date'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $training['start_time'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $training['end_time'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $training['att_status'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
</section>


<section class="history-section history-tournament-section"
         aria-labelledby="tournament-history-title">

    <h3 id="tournament-history-title">
        My Tournament Registration History
    </h3>


<?php if ($tournamentError): ?>

    <p class="history-section-error" role="alert">
        <?= htmlspecialchars(
            $tournamentError,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

<?php elseif (empty($tournamentHistory)): ?>


<div class="history-empty-state">
    <p>You have not registered for any tournaments yet.</p>

    <p class="history-empty-hint">
        Your tournament registrations will appear here.
    </p>
</div>

<?php else: ?>


<div class="history-table-scroll"
     role="region"
     aria-label="Tournament registration records"
     tabindex="0">

    <table class="history-table">

        <thead>
            <tr>
                <th>Tournament Name</th>
                <th>Sport</th>
                <th>Tournament Dates</th>
                <th>Venue</th>
                <th>Registration Date</th>
                <th>Registration Status</th>
                <th>Tournament Status</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($tournamentHistory as $tournament): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['t_name'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['sport'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['start_date']
                            . ' to ' .
                            (string) $tournament['end_date'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['venue'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['reg_date'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            ucfirst((string) $tournament['registration_status']),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $tournament['tournament_status'],
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
</section>



<section class="history-section history-match-section"
         aria-labelledby="match-history-title">

    <h3 id="match-history-title">
        My Match Registration History
    </h3>


<?php if ($matchError): ?>

    <p class="history-section-error" role="alert">
        <?= htmlspecialchars(
            $matchError,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

<?php elseif (empty($matchHistory)): ?>


<div class="history-empty-state">

    <p>You have not registered for any matches yet.</p>

    <p class="history-empty-hint">
        Your match registrations and results will appear here.
    </p>

</div>


<?php else: ?>


<div class="history-table-scroll"
     role="region"
     aria-label="Match registration records"
     tabindex="0">

    <table class="history-table">

        <thead>
            <tr>
                <th>Match Title</th>
                <th>Teams</th>
                <th>Sport</th>
                <th>Date</th>
                <th>Venue</th>
                <th>Registration Date</th>
                <th>Registration Status</th>
                <th>Match Status</th>
                <th>Score</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($matchHistory as $match): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars(
                            (string) $match['match_title'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $match['home_name']
                            . ' vs ' .
                            (string) $match['away_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $match['sport'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $match['match_date'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $match['venue'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string) $match['requested_at'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            ucfirst((string) $match['registration_status']),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            ucfirst((string) $match['match_status']),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?php
                        $status = strtolower(
                            trim((string) $match['match_status'])
                        );
                        ?>

                        <?php if (in_array(
                            $status,
                            ['completed', 'finished'],
                            true
                        )): ?>

                            <?= (int) $match['home_score'] ?>
                            -
                            <?= (int) $match['away_score'] ?>

                        <?php else: ?>

                            Not finalized

                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
   </table>
</div>

<?php endif; ?>
</section>

<section class="history-section history-booking-section"
         aria-labelledby="booking-history-title">

    <h3 id="booking-history-title">
        My Facility and Equipment Booking History
    </h3>

<?php if ($bookingError): ?>

    <p class="history-section-error" role="alert">
        <?= htmlspecialchars(
            $bookingError,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

<?php elseif (empty($bookingHistory)): ?>

    <div class="history-empty-state">

    <p>You have no facility or equipment bookings yet.</p>

    <p class="history-empty-hint">
        Your booking requests and approval statuses will appear here.
    </p>

</div>

<?php else: ?>

   <div class="history-table-scroll"
     role="region"
     aria-label="Facility and equipment booking records"
     tabindex="0">

    <table class="history-table">
        <thead>
            <tr>
                <th>Resource Name</th>
                <th>Resource Type</th>
                <th>Location</th>
                <th>Booking Date</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Purpose</th>
                <th>Booking Status</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($bookingHistory as $booking): ?>
                <tr>
                    <?php
                    $fields = [
                        'res_name',
                        'res_type',
                        'location',
                        'date',
                        'starts_at',
                        'ends_at',
                        'purpose',
                        'booking_status'
                    ];
                    ?>

                    <?php foreach ($fields as $field): ?>
                        <td>
                            <?php
                            $value = $booking[$field] ?? null;

                            if ($value === null || $value === '') {
                                $value = 'Not provided';
                            }

                            echo htmlspecialchars(
                                (string) $value,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</section>


<div class="history-back-action">
    <a href="profile.php" class="history-back-link">
        &larr; Back to My Profile
    </a>
</div>


    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
