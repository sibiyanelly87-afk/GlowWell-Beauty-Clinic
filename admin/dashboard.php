
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the administrator dashboard.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

try {
    // Count registered customers.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'customer'"
    );
    $totalCustomers = (int)$stmt->fetchColumn();

    // Count active staff members.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM staff WHERE employment_status = 'active'"
    );
    $totalStaff = (int)$stmt->fetchColumn();

    // Count active services.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM services WHERE service_status = 'active'"
    );
    $totalServices = (int)$stmt->fetchColumn();

    // Count all appointments.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM appointments"
    );
    $totalAppointments = (int)$stmt->fetchColumn();

    // Count appointments awaiting confirmation.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM appointments
         WHERE booking_status = 'pending'"
    );
    $pendingAppointments = (int)$stmt->fetchColumn();

    // Count confirmed appointments.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM appointments
         WHERE booking_status = 'confirmed'"
    );
    $confirmedAppointments = (int)$stmt->fetchColumn();

    // Retrieve the latest appointments.
    $stmt = $pdo->query(
        "SELECT
            a.appointment_id,
            a.appointment_date,
            a.start_time,
            a.booking_status,
            cu.first_name AS customer_first_name,
            cu.last_name AS customer_last_name,
            sv.service_name,
            su.first_name AS staff_first_name,
            su.last_name AS staff_last_name
         FROM appointments a
         JOIN users cu ON a.customer_id = cu.user_id
         JOIN services sv ON a.service_id = sv.service_id
         JOIN staff st ON a.staff_id = st.staff_id
         JOIN users su ON st.user_id = su.user_id
         ORDER BY a.created_at DESC
         LIMIT 5"
    );

    $recentAppointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Admin dashboard error: ' . $e->getMessage());

    http_response_code(500);
    exit('The administrator dashboard could not load. Please try again later.');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | GlowWell Beauty Clinic</title>

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
            <p class="eyebrow">GLOWWELL MANAGEMENT PORTAL</p>

            <h1>
                Welcome, <?= e($_SESSION['first_name'] ?? 'Administrator') ?>!
            </h1>

            <p>
                Manage your clinic, monitor appointments and
                keep your beauty and wellness business organised.
            </p>
        </div>

        <div class="admin-date">
            <?= e(date('l, d F Y')) ?>
        </div>

    </section>

    <section class="admin-content">

        <div class="admin-section-heading">
            <div>
                <p class="eyebrow">YOUR CLINIC AT A GLANCE</p>
                <h2>Business Overview</h2>
            </div>
        </div>

        <div class="admin-stats-grid">

            <article class="admin-stat-card">
                <span class="stat-label">Total Customers</span>
                <strong><?= $totalCustomers ?></strong>
                <span class="stat-description">Registered customer accounts</span>
            </article>

            <article class="admin-stat-card">
                <span class="stat-label">Active Staff</span>
                <strong><?= $totalStaff ?></strong>
                <span class="stat-description">Available staff profiles</span>
            </article>

            <article class="admin-stat-card">
                <span class="stat-label">Active Services</span>
                <strong><?= $totalServices ?></strong>
                <span class="stat-description">Services offered by the clinic</span>
            </article>

            <article class="admin-stat-card">
                <span class="stat-label">Total Appointments</span>
                <strong><?= $totalAppointments ?></strong>
                <span class="stat-description">All recorded bookings</span>
            </article>

        </div>

        <div class="admin-section-heading">
            <div>
                <p class="eyebrow">APPOINTMENT MONITORING</p>
                <h2>Booking Overview</h2>
            </div>
        </div>

        <div class="booking-summary-grid">

            <div class="booking-summary-card pending-summary">
                <span>Awaiting Confirmation</span>
                <strong><?= $pendingAppointments ?></strong>
            </div>

            <div class="booking-summary-card confirmed-summary">
                <span>Confirmed Appointments</span>
                <strong><?= $confirmedAppointments ?></strong>
            </div>

        </div>

        <div class="admin-section-heading">
            <div>
                <p class="eyebrow">LATEST ACTIVITY</p>
                <h2>Recent Appointments</h2>
            </div>

            <a href="appointments.php" class="admin-text-link">
                View All Appointments →
            </a>
        </div>

        <?php if (empty($recentAppointments)): ?>

            <div class="empty-appointments">
                <h3>No appointments recorded yet.</h3>
                <p>New customer bookings will appear here.</p>
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
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recentAppointments as $appointment): ?>

                            <?php
                            $statusClass = 'status-' .
                                $appointment['booking_status'];
                            ?>

                            <tr>

                                <td>
                                    #<?= (int)$appointment['appointment_id'] ?>
                                </td>

                                <td>
                                    <?= e(
                                        $appointment['customer_first_name'] . ' ' .
                                        $appointment['customer_last_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($appointment['service_name']) ?>
                                </td>

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
                                </td>

                                <td>
                                    <span class="appointment-status <?= e($statusClass) ?>">
                                        <?= e(ucfirst($appointment['booking_status'])) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

        <div class="admin-section-heading">
            <div>
                <p class="eyebrow">CLINIC OPERATIONS</p>
                <h2>Management Centre</h2>
            </div>
        </div>

        <div class="admin-management-grid">

            <a href="customers.php" class="admin-management-card">
                <span class="management-icon">01</span>
                <strong>Customer Management</strong>
                <span>View and manage customer accounts.</span>
            </a>

            <a href="staff.php" class="admin-management-card">
                <span class="management-icon">02</span>
                <strong>Staff Management</strong>
                <span>Manage staff profiles and availability.</span>
            </a>

            <a href="services.php" class="admin-management-card">
                <span class="management-icon">03</span>
                <strong>Service Management</strong>
                <span>Maintain clinic services and pricing.</span>
            </a>

            <a href="appointments.php" class="admin-management-card">
                <span class="management-icon">04</span>
                <strong>Appointment Management</strong>
                <span>Review and manage customer bookings.</span>
            </a>

            <a href="reports.php" class="admin-management-card">
                <span class="management-icon">05</span>
                <strong>Business Reports</strong>
                <span>View booking statistics and business activity.</span>
            </a>

        </div>

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
