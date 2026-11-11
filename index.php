
<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

// Require an authenticated user.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Direct staff and administrators to their own dashboards.
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin/dashboard.php');
    exit;
}

if (($_SESSION['role'] ?? '') === 'staff') {
    header('Location: staff/dashboard.php');
    exit;
}

$customerId = (int)$_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Customer';

try {

    // Retrieve the customer's booking statistics.
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_bookings,
            COALESCE(SUM(booking_status = 'pending'), 0) AS pending_bookings,
            COALESCE(SUM(booking_status = 'confirmed'), 0) AS confirmed_bookings
         FROM appointments
         WHERE customer_id = :customer_id"
    );

    $stmt->execute(['customer_id' => $customerId]);
    $statistics = $stmt->fetch();

    // Retrieve upcoming appointments.
    $stmt = $pdo->prepare(
        "SELECT
            a.appointment_id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.booking_status,
            sv.service_name,
            sv.price,
            su.first_name AS staff_first_name,
            su.last_name AS staff_last_name
         FROM appointments a
         JOIN services sv ON a.service_id = sv.service_id
         JOIN staff st ON a.staff_id = st.staff_id
         JOIN users su ON st.user_id = su.user_id
         WHERE a.customer_id = :customer_id
           AND a.booking_status IN ('pending', 'confirmed')
           AND (
                a.appointment_date > CURDATE()
                OR (
                    a.appointment_date = CURDATE()
                    AND a.end_time > CURTIME()
                )
           )
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT 3"
    );

    $stmt->execute(['customer_id' => $customerId]);
    $upcomingAppointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Customer dashboard error: ' . $e->getMessage());

    http_response_code(500);
    exit('Your dashboard could not be loaded. Please try again later.');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Dashboard | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<header class="site-header">

    <a href="index.php" class="brand">
        Glow<span>Well</span>
    </a>

    <nav class="nav-links">
        <a href="index.php">Dashboard</a>
        <a href="customer/services.php">Our Services</a>
        <a href="customer/appointments.php">My Appointments</a>
        <a href="logout.php">Log Out</a>
    </nav>

</header>

<main class="page-shell">

    <section class="hero-panel">

        <p class="eyebrow">WELCOME TO GLOWWELL BEAUTY CLINIC</p>

        <h1>Welcome back, <?= e($firstName) ?>!</h1>

        <p class="hero-description">
            Your time to relax, refresh and feel your best.
            Explore our treatments and take care of yourself.
        </p>

        <a href="customer/services.php" class="customer-hero-button">
            Explore Our Services
        </a>

    </section>

    <section class="customer-dashboard-content">

        <div class="section-heading">
            <p class="eyebrow">YOUR PERSONAL SPACE</p>
            <h2>Your Booking Overview</h2>
            <p>Keep track of your GlowWell appointments.</p>
        </div>

        <div class="customer-dashboard-stats">

            <article class="customer-dashboard-stat">
                <span>Total Bookings</span>
                <strong>
                    <?= (int)$statistics['total_bookings'] ?>
                </strong>
            </article>

            <article class="customer-dashboard-stat">
                <span>Awaiting Confirmation</span>
                <strong>
                    <?= (int)$statistics['pending_bookings'] ?>
                </strong>
            </article>

            <article class="customer-dashboard-stat">
                <span>Confirmed Appointments</span>
                <strong>
                    <?= (int)$statistics['confirmed_bookings'] ?>
                </strong>
            </article>

        </div>

        <div class="customer-dashboard-actions">

            <a href="customer/services.php" class="customer-action-card">

                <span class="customer-action-number">01</span>

                <strong>Explore Treatments</strong>

                <span>
                    Discover facials, massages, hair treatments
                    and wellness services.
                </span>

            </a>

            <a href="customer/appointments.php" class="customer-action-card">

                <span class="customer-action-number">02</span>

                <strong>Manage Appointments</strong>

                <span>
                    View your bookings, check their status
                    and manage eligible appointments.
                </span>

            </a>

        </div>

        <section class="upcoming-appointments">

            <div class="appointments-heading">

                <div>
                    <p class="eyebrow">WHAT'S NEXT?</p>
                    <h2>Your Upcoming Appointments</h2>
                </div>

                <a href="customer/appointments.php" class="admin-text-link">
                    View All →
                </a>

            </div>

            <?php if (empty($upcomingAppointments)): ?>

                <div class="empty-appointments">
                    <h3>No upcoming appointments</h3>

                    <p>
                        Your next moment of relaxation
                        is just a booking away.
                    </p>

                    <a href="customer/services.php" class="btn btn-primary">
                        Discover Our Services
                    </a>
                </div>

            <?php else: ?>

                <div class="appointments-grid">

                    <?php foreach ($upcomingAppointments as $appointment): ?>

                        <?php
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
                                </p>

                                <p>
                                    <strong>Therapist</strong>

                                    <?= e(
                                        $appointment['staff_first_name'] . ' ' .
                                        $appointment['staff_last_name']
                                    ) ?>
                                </p>

                                <p>
                                    <strong>Price</strong>

                                    R<?= number_format(
                                        (float)$appointment['price'],
                                        2
                                    ) ?>
                                </p>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

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
