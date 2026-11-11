
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the staff dashboard.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: ../login.php');
    exit;
}

// Retrieve the staff profile.
$stmt = $pdo->prepare(
    "SELECT staff_id, job_title, employment_status
     FROM staff
     WHERE user_id = :user_id
     LIMIT 1"
);

$stmt->execute(['user_id' => $_SESSION['user_id']]);
$staff = $stmt->fetch();

if (!$staff || $staff['employment_status'] !== 'active') {
    http_response_code(403);
    exit('Your staff profile is inactive or unavailable.');
}

$staffId = (int) $staff['staff_id'];
$errors = [];
$timezone = new DateTimeZone('Africa/Johannesburg');

// Process appointment status updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    $appointmentId = filter_var(
        $_POST['appointment_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $newStatus = $_POST['booking_status'] ?? '';

    // Validate the submitted information.
    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif (!$appointmentId || $appointmentId < 1) {
        $errors[] = 'Invalid appointment selected.';

    } elseif (!in_array($newStatus, ['confirmed', 'completed'], true)) {
        $errors[] = 'Invalid appointment status selected.';
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            // Retrieve only appointments assigned to this staff member.
            $stmt = $pdo->prepare(
                "SELECT appointment_id, appointment_date,
                        end_time, booking_status
                 FROM appointments
                 WHERE appointment_id = :appointment_id
                   AND staff_id = :staff_id
                 FOR UPDATE"
            );

            $stmt->execute([
                'appointment_id' => $appointmentId,
                'staff_id' => $staffId
            ]);

            $appointment = $stmt->fetch();

            if (!$appointment) {

                $pdo->rollBack();
                $errors[] = 'The selected appointment was not found.';

            } else {

                $allowed = false;

                // Staff may confirm a pending appointment.
                if (
                    $newStatus === 'confirmed' &&
                    $appointment['booking_status'] === 'pending'
                ) {
                    $allowed = true;
                }

                // Staff may complete an appointment only
                // after its scheduled end time.
                if (
                    $newStatus === 'completed' &&
                    $appointment['booking_status'] === 'confirmed'
                ) {

                    $appointmentEnd = new DateTimeImmutable(
                        $appointment['appointment_date'] . ' ' .
                        $appointment['end_time'],
                        $timezone
                    );

                    $now = new DateTimeImmutable('now', $timezone);

                    if ($appointmentEnd <= $now) {
                        $allowed = true;
                    }
                }

                if (!$allowed) {

                    $pdo->rollBack();

                    $errors[] =
                        'This appointment cannot be changed to the selected status yet.';

                } else {

                    // Update the appointment status.
                    $update = $pdo->prepare(
                        "UPDATE appointments
                         SET booking_status = :new_status,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE appointment_id = :appointment_id
                           AND staff_id = :staff_id"
                    );

                    $update->execute([
                        'new_status' => $newStatus,
                        'appointment_id' => $appointmentId,
                        'staff_id' => $staffId
                    ]);

                    $pdo->commit();

                    set_flash(
                        'success',
                        'Appointment status updated successfully.'
                    );

                    // Return directly to the updated appointment.
                    header(
                        'Location: dashboard.php#appointment-' . $appointmentId
                    );
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Staff appointment update error: ' . $e->getMessage()
            );

            $errors[] =
                'We could not update the appointment. Please try again.';
        }
    }
}

// Retrieve the staff member's working schedule.
$stmt = $pdo->prepare(
    "SELECT weekday, start_time, end_time
     FROM staff_availability
     WHERE staff_id = :staff_id
       AND is_available = 1
     ORDER BY weekday"
);

$stmt->execute(['staff_id' => $staffId]);
$workingDays = $stmt->fetchAll();

$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

// Retrieve appointments assigned to this staff member.
$stmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.booking_status,
        a.notes,
        sv.service_name,
        sv.duration_minutes,
        sv.price,
        u.first_name AS customer_first_name,
        u.last_name AS customer_last_name
     FROM appointments a
     JOIN services sv ON a.service_id = sv.service_id
     JOIN users u ON a.customer_id = u.user_id
     WHERE a.staff_id = :staff_id
     ORDER BY a.appointment_date ASC, a.start_time ASC"
);

$stmt->execute(['staff_id' => $staffId]);
$appointments = $stmt->fetchAll();

$flash = get_flash();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Dashboard | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

<header class="site-header">

    <a href="dashboard.php" class="brand">
        Glow<span>Well</span>
    </a>

    <nav class="nav-links">
        <a href="dashboard.php">My Dashboard</a>
        <a href="../logout.php">Log Out</a>
    </nav>

</header>

<main class="page-shell">

    <section class="staff-hero">

        <p class="eyebrow">GLOWWELL STAFF PORTAL</p>

        <h1>Welcome, <?= e($_SESSION['first_name']) ?>!</h1>

        <p>
            Your staff workspace for managing appointments
            and keeping your daily schedule organised.
        </p>

        <span class="staff-job-title">
            <?= e($staff['job_title']) ?>
        </span>

    </section>

    <section class="staff-content">

        <?php if ($flash): ?>

            <div class="message-success" role="status">
                <?= e($flash['message']) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="message-error" role="alert">

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>

            </div>

        <?php endif; ?>

        <div class="staff-section-heading">
            <div>
                <p class="eyebrow">YOUR WEEKLY HOURS</p>
                <h2>Working Schedule</h2>
            </div>
        </div>

        <div class="working-hours-grid">

            <?php if (empty($workingDays)): ?>

                <p>No working hours have been assigned.</p>

            <?php else: ?>

                <?php foreach ($workingDays as $day): ?>

                    <div class="working-day-card">

                        <strong>
                            <?= e($dayNames[(int) $day['weekday']]) ?>
                        </strong>

                        <span>
                            <?= e(date(
                                'g:i A',
                                strtotime($day['start_time'])
                            )) ?>

                            –

                            <?= e(date(
                                'g:i A',
                                strtotime($day['end_time'])
                            )) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

                <div class="working-day-card closed-day">
                    <strong>Sunday</strong>
                    <span>Not scheduled</span>
                </div>

            <?php endif; ?>

        </div>

        <div class="staff-section-heading">
            <div>
                <p class="eyebrow">YOUR DAILY RESPONSIBILITIES</p>
                <h2>Assigned Appointments</h2>
            </div>
        </div>

        <?php if (empty($appointments)): ?>

            <div class="empty-appointments">
                <h2>No appointments assigned</h2>
                <p>Your assigned appointments will appear here.</p>
            </div>

        <?php else: ?>

            <div class="appointments-grid">

                <?php foreach ($appointments as $appointment): ?>

                    <?php

                    $appointmentEnd = new DateTimeImmutable(
                        $appointment['appointment_date'] . ' ' .
                        $appointment['end_time'],
                        $timezone
                    );

                    $now = new DateTimeImmutable('now', $timezone);

                    $canComplete =
                        $appointment['booking_status'] === 'confirmed'
                        && $appointmentEnd <= $now;

                    $statusClass = 'status-' .
                        $appointment['booking_status'];

                    ?>

                    <article
                        class="appointment-card"
                        id="appointment-<?= (int) $appointment['appointment_id'] ?>"
                    >

                        <div class="appointment-card-top">

                            <span class="appointment-label">
                                BOOKING #<?= (int) $appointment['appointment_id'] ?>
                            </span>

                            <span class="appointment-status <?= e($statusClass) ?>">
                                <?= e(ucfirst($appointment['booking_status'])) ?>
                            </span>

                        </div>

                        <h3>
                            <?= e($appointment['service_name']) ?>
                        </h3>

                        <p class="appointment-staff">

                            Customer:

                            <strong>
                                <?= e(
                                    $appointment['customer_first_name'] . ' ' .
                                    $appointment['customer_last_name']
                                ) ?>
                            </strong>

                        </p>

                        <div class="appointment-details">

                            <p>
                                <strong>Date</strong>

                                <?= e(date(
                                    'd F Y',
                                    strtotime($appointment['appointment_date'])
                                )) ?>
                            </p>

                            <p>
                                <strong>Time</strong>

                                <?= e(date(
                                    'g:i A',
                                    strtotime($appointment['start_time'])
                                )) ?>

                                –

                                <?= e(date(
                                    'g:i A',
                                    strtotime($appointment['end_time'])
                                )) ?>
                            </p>

                            <p>
                                <strong>Duration</strong>

                                <?= (int) $appointment['duration_minutes'] ?>
                                minutes
                            </p>

                            <p>
                                <strong>Price</strong>

                                R<?= number_format(
                                    (float) $appointment['price'],
                                    2
                                ) ?>
                            </p>

                        </div>

                        <?php if (!empty($appointment['notes'])): ?>

                            <p>
                                Customer notes:
                                <?= e($appointment['notes']) ?>
                            </p>

                        <?php endif; ?>

                        <?php if ($appointment['booking_status'] === 'pending'): ?>

                            <form method="POST" action="dashboard.php">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $appointment['appointment_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="booking_status"
                                    value="confirmed"
                                >

                                <button type="submit" class="btn-action">
                                    Confirm Appointment
                                </button>

                            </form>

                        <?php elseif ($canComplete): ?>

                            <form method="POST" action="dashboard.php">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $appointment['appointment_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="booking_status"
                                    value="completed"
                                >

                                <button type="submit" class="btn-action">
                                    Mark as Completed
                                </button>

                            </form>

                        <?php elseif ($appointment['booking_status'] === 'confirmed'): ?>

                            <p class="cancellation-note">
                                This appointment is confirmed.
                            </p>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="site-footer">

    <p>
        &copy; <?= date('Y') ?> GlowWell Beauty Clinic.
        Your beauty, our passion.
    </p>

</footer>

</body>
</html>
