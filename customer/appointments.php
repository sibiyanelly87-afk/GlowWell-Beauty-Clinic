
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Only logged-in customers may access this page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: ../login.php');
    exit;
}

$customerId = (int) $_SESSION['user_id'];
$errors = [];
$timezone = new DateTimeZone('Africa/Johannesburg');

// Process appointment cancellation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $appointmentId = filter_var(
        $_POST['appointment_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    } elseif (!$appointmentId || $appointmentId < 1) {
        $errors[] = 'Invalid appointment selected.';
    }

    if (empty($errors)) {

        try {
            $pdo->beginTransaction();

            // Lock and retrieve only this customer's appointment.
            $stmt = $pdo->prepare(
                "SELECT appointment_id, appointment_date,
                        start_time, booking_status
                 FROM appointments
                 WHERE appointment_id = :appointment_id
                   AND customer_id = :customer_id
                 FOR UPDATE"
            );

            $stmt->execute([
                'appointment_id' => $appointmentId,
                'customer_id' => $customerId
            ]);

            $appointment = $stmt->fetch();

            if (!$appointment) {

                $pdo->rollBack();
                $errors[] = 'The selected appointment could not be found.';

            } else {

                $appointmentDateTime = new DateTimeImmutable(
                    $appointment['appointment_date'] . ' ' .
                    $appointment['start_time'],
                    $timezone
                );

                $now = new DateTimeImmutable('now', $timezone);

                if (
                    !in_array(
                        $appointment['booking_status'],
                        ['pending', 'confirmed'],
                        true
                    )
                ) {

                    $pdo->rollBack();
                    $errors[] = 'This appointment can no longer be cancelled.';

                } elseif ($appointmentDateTime <= $now) {

                    $pdo->rollBack();
                    $errors[] = 'Past appointments cannot be cancelled.';

                } else {

                    // Update only this customer's eligible appointment.
                    $update = $pdo->prepare(
                        "UPDATE appointments
                         SET booking_status = 'cancelled',
                             updated_at = CURRENT_TIMESTAMP
                         WHERE appointment_id = :appointment_id
                           AND customer_id = :customer_id
                           AND booking_status IN ('pending', 'confirmed')"
                    );

                    $update->execute([
                        'appointment_id' => $appointmentId,
                        'customer_id' => $customerId
                    ]);

                    $pdo->commit();

                    set_flash(
                        'success',
                        'Your appointment has been cancelled successfully.'
                    );

                    header('Location: appointments.php');
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Appointment cancellation error: ' . $e->getMessage());
            $errors[] = 'We could not cancel your appointment. Please try again.';
        }
    }
}

// Retrieve the customer's appointments.
try {

    $stmt = $pdo->prepare(
        "SELECT
            a.appointment_id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.booking_status,
            sv.service_name,
            sv.duration_minutes,
            sv.price,
            u.first_name AS staff_first_name,
            u.last_name AS staff_last_name
         FROM appointments a
         JOIN services sv
            ON a.service_id = sv.service_id
         JOIN staff st
            ON a.staff_id = st.staff_id
         JOIN users u
            ON st.user_id = u.user_id
         WHERE a.customer_id = :customer_id
         ORDER BY a.appointment_date DESC, a.start_time DESC"
    );

    $stmt->execute(['customer_id' => $customerId]);
    $appointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Customer appointments retrieval error: ' . $e->getMessage());
    $appointments = [];
    $databaseError = true;
}

$flash = get_flash();
$now = new DateTimeImmutable('now', $timezone);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Appointments | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

<header class="site-header">

    <a href="../index.php" class="brand">Glow<span>Well</span></a>

    <nav class="nav-links">
        <a href="../index.php">Dashboard</a>
        <a href="services.php">Our Services</a>
        <a href="appointments.php">My Appointments</a>
        <a href="../logout.php">Log Out</a>
    </nav>

</header>

<main class="page-shell">

    <section class="booking-hero">
        <p class="eyebrow">YOUR GLOWWELL EXPERIENCE</p>

        <h1>My Appointments</h1>

        <p>
            Keep track of your beauty and wellness appointments
            in one convenient place.
        </p>
    </section>

    <section class="appointments-section">

        <?php if ($flash): ?>
            <div class="message-success" role="status">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="message-error" role="alert">
                <strong>Please note:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($databaseError)): ?>

            <div class="message-error" role="alert">
                We could not retrieve your appointments.
                Please try again later.
            </div>

        <?php elseif (empty($appointments)): ?>

            <div class="empty-appointments">
                <h2>No appointments yet</h2>

                <p>
                    Your next moment of relaxation is just a
                    booking away.
                </p>

                <a href="services.php" class="btn btn-primary">
                    Explore Our Services
                </a>
            </div>

        <?php else: ?>

            <div class="appointments-heading">
                <div>
                    <p class="eyebrow">YOUR BOOKING HISTORY</p>
                    <h2>Your Appointments</h2>
                </div>

                <a href="services.php" class="btn btn-primary">
                    Book Another Service
                </a>
            </div>

            <div class="appointments-grid">

                <?php foreach ($appointments as $appointment): ?>

                    <?php
                    $appointmentDateTime = new DateTimeImmutable(
                        $appointment['appointment_date'] . ' ' .
                        $appointment['start_time'],
                        $timezone
                    );

                    $canCancel =
                        in_array(
                            $appointment['booking_status'],
                            ['pending', 'confirmed'],
                            true
                        ) && $appointmentDateTime > $now;

                    $statusClass = 'status-' .
                        $appointment['booking_status'];
                    ?>

                    <article class="appointment-card">

                        <div class="appointment-card-top">

                            <span class="appointment-label">
                                BOOKING #<?= (int)$appointment['appointment_id'] ?>
                            </span>

                            <span class="appointment-status <?= e($statusClass) ?>">
                                <?= e(ucfirst($appointment['booking_status'])) ?>
                            </span>

                        </div>

                        <h3><?= e($appointment['service_name']) ?></h3>

                        <p class="appointment-staff">
                            Your therapist:
                            <strong>
                                <?= e(
                                    $appointment['staff_first_name'] . ' ' .
                                    $appointment['staff_last_name']
                                ) ?>
                            </strong>
                        </p>

                        <div class="appointment-details">

                            <p>
                                <strong>Date</strong>
                                <?= e(date(
                                    'l, d F Y',
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
                                <?= (int)$appointment['duration_minutes'] ?>
                                minutes
                            </p>

                            <p>
                                <strong>Price</strong>
                                R<?= number_format(
                                    (float)$appointment['price'],
                                    2
                                ) ?>
                            </p>

                        </div>

                        <?php if ($canCancel): ?>

                            <form method="POST"
                                  action="appointments.php"
                                  class="cancel-form"
                                  onsubmit="return confirm('Are you sure you want to cancel this appointment?');">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= e(csrf_token()) ?>">

                                <input type="hidden"
                                       name="appointment_id"
                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                <button type="submit"
                                        class="btn-cancel">
                                    Cancel Appointment
                                </button>

                            </form>

                        <?php else: ?>

                            <p class="cancellation-note">
                                This appointment cannot be cancelled.
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
