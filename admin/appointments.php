
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the appointment management page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

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

    if (!verify_csrf_token($token)) {

        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif (!$appointmentId || $appointmentId < 1) {

        $errors[] = 'Invalid appointment selected.';

    } elseif (!in_array($newStatus, ['confirmed', 'cancelled', 'completed'], true)) {

        $errors[] = 'Invalid appointment status selected.';
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            // Retrieve and lock the selected appointment.
            $stmt = $pdo->prepare(
                "SELECT appointment_id, appointment_date,
                        end_time, booking_status
                 FROM appointments
                 WHERE appointment_id = :appointment_id
                 FOR UPDATE"
            );

            $stmt->execute([
                'appointment_id' => $appointmentId
            ]);

            $appointment = $stmt->fetch();

            if (!$appointment) {

                $pdo->rollBack();
                $errors[] = 'The selected appointment could not be found.';

            } else {

                $currentStatus = $appointment['booking_status'];
                $allowed = false;

                // A pending booking may be confirmed or cancelled.
                if (
                    $currentStatus === 'pending' &&
                    in_array($newStatus, ['confirmed', 'cancelled'], true)
                ) {
                    $allowed = true;
                }

                // A confirmed booking may be cancelled or completed.
                if (
                    $currentStatus === 'confirmed' &&
                    in_array($newStatus, ['cancelled', 'completed'], true)
                ) {

                    if ($newStatus === 'cancelled') {
                        $allowed = true;
                    }

                    if ($newStatus === 'completed') {

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
                }

                if (!$allowed) {

                    $pdo->rollBack();

                    $errors[] =
                        'This appointment cannot be changed to the selected status.';

                } else {

                    $update = $pdo->prepare(
                        "UPDATE appointments
                         SET booking_status = :new_status,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE appointment_id = :appointment_id"
                    );

                    $update->execute([
                        'new_status' => $newStatus,
                        'appointment_id' => $appointmentId
                    ]);

                    $pdo->commit();

                    set_flash(
                        'success',
                        'Appointment status updated successfully.'
                    );

                    header(
                        'Location: appointments.php#appointment-' . $appointmentId
                    );
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Admin appointment update error: ' . $e->getMessage());

            $errors[] =
                'The appointment could not be updated. Please try again.';
        }
    }
}

// Retrieve all appointments with customer, service and staff details.
try {

    $stmt = $pdo->query(
        "SELECT
            a.appointment_id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.booking_status,
            a.notes,
            a.created_at,
            cu.first_name AS customer_first_name,
            cu.last_name AS customer_last_name,
            cu.email AS customer_email,
            sv.service_name,
            sv.duration_minutes,
            sv.price,
            su.first_name AS staff_first_name,
            su.last_name AS staff_last_name
         FROM appointments a
         JOIN users cu ON a.customer_id = cu.user_id
         JOIN services sv ON a.service_id = sv.service_id
         JOIN staff st ON a.staff_id = st.staff_id
         JOIN users su ON st.user_id = su.user_id
         ORDER BY a.appointment_date DESC, a.start_time DESC"
    );

    $appointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Admin appointment retrieval error: ' . $e->getMessage());

    http_response_code(500);
    exit('Appointment records could not be retrieved. Please try again later.');
}

// Calculate appointment status totals.
$statusCounts = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($appointments as $appointment) {

    if (isset($statusCounts[$appointment['booking_status']])) {
        $statusCounts[$appointment['booking_status']]++;
    }
}

$flash = get_flash();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Appointment Management | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

<header class="site-header">

    <a href="dashboard.php" class="brand">
        Glow<span>Well</span>
    </a>

    <nav class="nav-links">
        <a href="dashboard.php">Overview</a>
        <a href="customers.php">Customers</a>
        <a href="staff.php">Staff</a>
        <a href="services.php">Services</a>
        <a href="appointments.php">Appointments</a>
        <a href="reports.php">Reports</a>
        <a href="../logout.php">Log Out</a>
    </nav>

</header>

<main class="page-shell">

    <section class="admin-hero">

        <div>
            <p class="eyebrow">GLOWWELL BOOKING CONTROL</p>

            <h1>Appointment Management</h1>

            <p>
                Review customer bookings, monitor appointment status
                and manage the clinic's daily schedule.
            </p>
        </div>

        <div class="admin-date">
            <?= e(date('l, d F Y')) ?>
        </div>

    </section>

    <section class="admin-content">

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

        <div class="appointment-summary-grid">

            <article class="appointment-summary-card">
                <span>Total Appointments</span>
                <strong><?= count($appointments) ?></strong>
            </article>

            <article class="appointment-summary-card pending-summary">
                <span>Pending</span>
                <strong><?= $statusCounts['pending'] ?></strong>
            </article>

            <article class="appointment-summary-card confirmed-summary">
                <span>Confirmed</span>
                <strong><?= $statusCounts['confirmed'] ?></strong>
            </article>

            <article class="appointment-summary-card completed-summary">
                <span>Completed</span>
                <strong><?= $statusCounts['completed'] ?></strong>
            </article>

            <article class="appointment-summary-card cancelled-summary">
                <span>Cancelled</span>
                <strong><?= $statusCounts['cancelled'] ?></strong>
            </article>

        </div>

        <div class="admin-section-heading">

            <div>
                <p class="eyebrow">ALL CUSTOMER BOOKINGS</p>
                <h2>Appointment Records</h2>
            </div>

        </div>

        <?php if (empty($appointments)): ?>

            <div class="empty-appointments">
                <h2>No appointments recorded yet.</h2>
                <p>Customer bookings will appear here.</p>
            </div>

        <?php else: ?>

            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Staff Member</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($appointments as $appointment): ?>

                            <?php
                            $status = $appointment['booking_status'];

                            $statusClass = 'status-' . $status;

                            $appointmentEnd = new DateTimeImmutable(
                                $appointment['appointment_date'] . ' ' .
                                $appointment['end_time'],
                                $timezone
                            );

                            $now = new DateTimeImmutable('now', $timezone);

                            $canComplete =
                                $status === 'confirmed' &&
                                $appointmentEnd <= $now;
                            ?>

                            <tr id="appointment-<?= (int)$appointment['appointment_id'] ?>">

                                <td>
                                    #<?= (int)$appointment['appointment_id'] ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= e(
                                            $appointment['customer_first_name'] . ' ' .
                                            $appointment['customer_last_name']
                                        ) ?>
                                    </strong>
                                    <br>
                                    <small><?= e($appointment['customer_email']) ?></small>
                                </td>

                                <td><?= e($appointment['service_name']) ?></td>

                                <td>
                                    <?= e(
                                        $appointment['staff_first_name'] . ' ' .
                                        $appointment['staff_last_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'd M Y',
                                        strtotime($appointment['appointment_date'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'g:i A',
                                        strtotime($appointment['start_time'])
                                    )) ?>
                                    –
                                    <?= e(date(
                                        'g:i A',
                                        strtotime($appointment['end_time'])
                                    )) ?>
                                </td>

                                <td>
                                    R<?= number_format(
                                        (float)$appointment['price'],
                                        2
                                    ) ?>
                                </td>

                                <td>
                                    <span class="appointment-status <?= e($statusClass) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="appointment-action-group">

                                        <?php if ($status === 'pending'): ?>

                                            <form method="POST" action="appointments.php"
                                                  class="inline-action-form">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden" name="appointment_id"
                                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                                <input type="hidden" name="booking_status"
                                                       value="confirmed">

                                                <button type="submit" class="customer-status-button activate-button">
                                                    Confirm
                                                </button>

                                            </form>

                                            <form method="POST" action="appointments.php"
                                                  class="inline-action-form"
                                                  onsubmit="return confirm('Cancel this appointment?');">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden" name="appointment_id"
                                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                                <input type="hidden" name="booking_status"
                                                       value="cancelled">

                                                <button type="submit" class="customer-status-button deactivate-button">
                                                    Cancel
                                                </button>

                                            </form>

                                        <?php elseif ($status === 'confirmed' && $canComplete): ?>

                                            <form method="POST" action="appointments.php"
                                                  class="inline-action-form">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden" name="appointment_id"
                                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                                <input type="hidden" name="booking_status"
                                                       value="completed">

                                                <button type="submit" class="customer-status-button activate-button">
                                                    Complete
                                                </button>

                                            </form>

                                            <form method="POST" action="appointments.php"
                                                  class="inline-action-form"
                                                  onsubmit="return confirm('Cancel this appointment?');">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden" name="appointment_id"
                                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                                <input type="hidden" name="booking_status"
                                                       value="cancelled">

                                                <button type="submit" class="customer-status-button deactivate-button">
                                                    Cancel
                                                </button>

                                            </form>

                                        <?php elseif ($status === 'confirmed'): ?>

                                            <span class="cancellation-note">
                                                Scheduled
                                            </span>

                                            <form method="POST" action="appointments.php"
                                                  class="inline-action-form"
                                                  onsubmit="return confirm('Cancel this appointment?');">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(csrf_token()) ?>">

                                                <input type="hidden" name="appointment_id"
                                                       value="<?= (int)$appointment['appointment_id'] ?>">

                                                <input type="hidden" name="booking_status"
                                                       value="cancelled">

                                                <button type="submit" class="customer-status-button deactivate-button">
                                                    Cancel
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="cancellation-note">
                                                No actions
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

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
