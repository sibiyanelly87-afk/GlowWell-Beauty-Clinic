
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Only logged-in customers may book appointments.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: ../login.php');
    exit;
}

$errors = [];
$timezone = new DateTimeZone('Africa/Johannesburg');

// Get submitted information.
$requestData = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $_POST : $_GET;

$serviceId = filter_var(
    $requestData['service_id'] ?? null,
    FILTER_VALIDATE_INT
);

$selectedDate = trim((string)($requestData['appointment_date'] ?? ''));
$selectedStaffId = filter_var(
    $requestData['staff_id'] ?? null,
    FILTER_VALIDATE_INT
) ?: 0;

// Retrieve the selected active service.
$stmt = $pdo->prepare(
    "SELECT service_id, service_name, description,
            duration_minutes, price
     FROM services
     WHERE service_id = :service_id
       AND service_status = 'active'"
);
$stmt->execute(['service_id' => $serviceId ?: 0]);
$service = $stmt->fetch();

if (!$service) {
    http_response_code(404);
    exit('The selected service could not be found.');
}

// Retrieve staff members qualified to provide this service.
$stmt = $pdo->prepare(
    "SELECT st.staff_id, u.first_name, u.last_name, st.job_title
     FROM staff st
     JOIN users u ON st.user_id = u.user_id
     JOIN staff_services ss ON st.staff_id = ss.staff_id
     WHERE ss.service_id = :service_id
       AND st.employment_status = 'active'
       AND u.account_status = 'active'
       AND u.role = 'staff'
     ORDER BY u.first_name"
);
$stmt->execute(['service_id' => $serviceId]);
$availableStaff = $stmt->fetchAll();

$allowedStaffIds = array_map(
    'intval',
    array_column($availableStaff, 'staff_id')
);

// Validate the requested appointment date.
$minimumDate = date('Y-m-d');
$maximumDate = date('Y-m-d', strtotime('+60 days'));

function glowwellValidBookingDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

// Generate available appointment slots.
function glowwellGetAvailableSlots(
    PDO $pdo,
    int $staffId,
    array $service,
    string $date
): array {

    $weekday = (int)date('N', strtotime($date));
    $duration = (int)$service['duration_minutes'];

    // Retrieve the staff member's working hours.
    $stmt = $pdo->prepare(
        "SELECT start_time, end_time
         FROM staff_availability
         WHERE staff_id = :staff_id
           AND weekday = :weekday
           AND is_available = 1
         ORDER BY start_time"
    );

    $stmt->execute([
        'staff_id' => $staffId,
        'weekday' => $weekday
    ]);

    $workingHours = $stmt->fetchAll();

    if (!$workingHours) {
        return [];
    }

    // Retrieve existing appointments for this staff member.
    $stmt = $pdo->prepare(
        "SELECT start_time, end_time
         FROM appointments
         WHERE staff_id = :staff_id
           AND appointment_date = :appointment_date
           AND booking_status <> 'cancelled'"
    );

    $stmt->execute([
        'staff_id' => $staffId,
        'appointment_date' => $date
    ]);

    $appointments = $stmt->fetchAll();

    $now = new DateTimeImmutable('now',
        new DateTimeZone('Africa/Johannesburg'));

    $slots = [];

    // Generate 30-minute appointment start intervals.
    foreach ($workingHours as $hours) {

        $slot = new DateTimeImmutable(
            $date . ' ' . $hours['start_time'],
            new DateTimeZone('Africa/Johannesburg')
        );

        $closingTime = new DateTimeImmutable(
            $date . ' ' . $hours['end_time'],
            new DateTimeZone('Africa/Johannesburg')
        );

        while (true) {

            $end = $slot->modify('+' . $duration . ' minutes');

            // The service must finish within working hours.
            if ($end > $closingTime) {
                break;
            }

            $startTime = $slot->format('H:i:s');
            $endTime = $end->format('H:i:s');

            // Do not offer appointments in the past.
            if ($slot > $now) {

                $conflict = false;

                // Check for overlapping appointments.
                foreach ($appointments as $appointment) {

                    if (
                        $appointment['start_time'] < $endTime &&
                        $appointment['end_time'] > $startTime
                    ) {
                        $conflict = true;
                        break;
                    }
                }

                if (!$conflict) {
                    $slots[$startTime] = [
                        'start' => $startTime,
                        'end' => $endTime
                    ];
                }
            }

            $slot = $slot->modify('+30 minutes');
        }
    }

    ksort($slots);

    return array_values($slots);
}

// Process a booking submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $startTime = trim((string)($_POST['start_time'] ?? ''));

    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh the page.';
    }

    if (
        !glowwellValidBookingDate($selectedDate) ||
        $selectedDate < $minimumDate ||
        $selectedDate > $maximumDate
    ) {
        $errors[] = 'Please select a valid appointment date within the next 60 days.';
    }

    if (!in_array($selectedStaffId, $allowedStaffIds, true)) {
        $errors[] = 'Please select a staff member qualified for this service.';
    }

    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $startTime)) {
        $errors[] = 'Please select a valid appointment time.';
    }

    if (empty($errors)) {

        try {
            // Lock the staff record to help prevent simultaneous bookings.
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                "SELECT staff_id FROM staff
                 WHERE staff_id = :staff_id
                 FOR UPDATE"
            );

            $lock->execute(['staff_id' => $selectedStaffId]);

            if (!$lock->fetch()) {
                throw new RuntimeException('The selected staff member is unavailable.');
            }

            // Recheck availability while holding the staff record lock.
            $freshSlots = glowwellGetAvailableSlots(
                $pdo,
                $selectedStaffId,
                $service,
                $selectedDate
            );

            $selectedSlot = null;

            foreach ($freshSlots as $slot) {
                if ($slot['start'] === $startTime) {
                    $selectedSlot = $slot;
                    break;
                }
            }

            if (!$selectedSlot) {
                $pdo->rollBack();
                $errors[] = 'That time is no longer available. Please choose another time.';

            } else {

                // Save the appointment as pending confirmation.
                $insert = $pdo->prepare(
                    "INSERT INTO appointments
                     (customer_id, staff_id, service_id,
                      appointment_date, start_time, end_time,
                      booking_status)
                     VALUES
                     (:customer_id, :staff_id, :service_id,
                      :appointment_date, :start_time, :end_time,
                      'pending')"
                );

                $insert->execute([
                    'customer_id' => $_SESSION['user_id'],
                    'staff_id' => $selectedStaffId,
                    'service_id' => $serviceId,
                    'appointment_date' => $selectedDate,
                    'start_time' => $selectedSlot['start'],
                    'end_time' => $selectedSlot['end']
                ]);

                $pdo->commit();

                set_flash(
                    'success',
                    'Your appointment request has been submitted successfully. It is awaiting confirmation.'
                );

                header('Location: book.php?service_id=' . $serviceId);
                exit;
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Appointment booking error: ' . $e->getMessage());
            $errors[] = 'We could not complete your booking. Please try again.';
        }
    }
}

// Calculate available times when a date and staff member are selected.
$slots = [];

$dateIsValid = glowwellValidBookingDate($selectedDate)
    && $selectedDate >= $minimumDate
    && $selectedDate <= $maximumDate;

if (
    $dateIsValid &&
    in_array($selectedStaffId, $allowedStaffIds, true)
) {
    $slots = glowwellGetAvailableSlots(
        $pdo,
        $selectedStaffId,
        $service,
        $selectedDate
    );
}

$flash = get_flash();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Book Appointment | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

<header class="site-header">
    <a href="../index.php" class="brand">Glow<span>Well</span></a>

    <nav class="nav-links">
        <a href="../index.php">Dashboard</a>
        <a href="services.php">Our Services</a>
        <a href="../logout.php">Log Out</a>
    </nav>
</header>

<main class="page-shell">

    <section class="booking-hero">
        <p class="eyebrow">YOUR WELLNESS JOURNEY</p>
        <h1>Book Your Appointment</h1>
        <p>Choose a convenient date and time for your treatment.</p>
    </section>

    <section class="booking-panel">

        <div class="selected-service">
            <p class="eyebrow">YOUR SELECTED TREATMENT</p>

            <h2><?= e($service['service_name']) ?></h2>

            <p><?= e($service['description']) ?></p>

            <div class="service-meta">
                <span><?= (int)$service['duration_minutes'] ?> minutes</span>
                <strong>R<?= number_format((float)$service['price'], 2) ?></strong>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="message-success" role="status">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="message-error" role="alert">
                <strong>Please check the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <h2>Select Your Appointment Details</h2>

        <?php if (empty($availableStaff)): ?>

            <p>No staff members are currently assigned to this service.</p>

        <?php else: ?>

            <form method="GET" action="book.php#available-times" class="booking-form">

                <input type="hidden" name="service_id"
                       value="<?= (int)$serviceId ?>">

                <label for="staff_id">Choose Your Beauty Therapist</label>

                <select id="staff_id" name="staff_id" required>
                    <option value="">Select a staff member</option>

                    <?php foreach ($availableStaff as $staff): ?>
                        <option value="<?= (int)$staff['staff_id'] ?>"
                            <?= $selectedStaffId === (int)$staff['staff_id']
                                ? 'selected' : '' ?>>

                            <?= e($staff['first_name'] . ' ' . $staff['last_name']) ?>
                            — <?= e($staff['job_title']) ?>

                        </option>
                    <?php endforeach; ?>

                </select>

                <label for="appointment_date">Appointment Date</label>

                <input
                    type="date"
                    id="appointment_date"
                    name="appointment_date"
                    min="<?= e($minimumDate) ?>"
                    max="<?= e($maximumDate) ?>"
                    value="<?= e($selectedDate) ?>"
                    required
                >

                <button type="submit">Check Available Times</button>

            </form>

            <?php if ($selectedDate !== '' && $selectedStaffId > 0): ?>

                <?php if ($dateIsValid && !empty($slots)): ?>

                    <div class="available-times" id="available-times">

                        <h2>Available Appointment Times</h2>

                        <p>
                            Choose a time for
                            <?= e($service['service_name']) ?>.
                        </p>

                        <form method="POST" action="book.php"
                              class="booking-form">

                            <input type="hidden" name="csrf_token"
                                   value="<?= e(csrf_token()) ?>">

                            <input type="hidden" name="service_id"
                                   value="<?= (int)$serviceId ?>">

                            <input type="hidden" name="staff_id"
                                   value="<?= (int)$selectedStaffId ?>">

                            <input type="hidden" name="appointment_date"
                                   value="<?= e($selectedDate) ?>">

                            <label for="start_time">Choose a Time</label>

                            <select id="start_time" name="start_time" required>
                                <option value="">Select an available time</option>

                                <?php foreach ($slots as $slot): ?>
                                    <option value="<?= e($slot['start']) ?>">
                                        <?= e(date('g:i A', strtotime($slot['start']))) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <button type="submit">Confirm Appointment</button>

                        </form>

                    </div>

                <?php elseif ($dateIsValid): ?>

                    <div class="message-info">
                        No appointment times are available for this staff
                        member on the selected date. Please choose another date.
                    </div>

                <?php else: ?>

                    <div class="message-info">
                        Please select a valid date within the next 60 days.
                    </div>

                <?php endif; ?>

            <?php endif; ?>

        <?php endif; ?>

        <p class="back-link">
            <a href="services.php">← Back to Services</a>
        </p>

    </section>

</main>

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> GlowWell Beauty Clinic.
       Your beauty, our passion.</p>
</footer>

</body>
</html>
