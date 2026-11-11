
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the reports page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

try {

    // Overall appointment statistics.
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_appointments,
            SUM(booking_status = 'pending') AS pending_appointments,
            SUM(booking_status = 'confirmed') AS confirmed_appointments,
            SUM(booking_status = 'completed') AS completed_appointments,
            SUM(booking_status = 'cancelled') AS cancelled_appointments,
            COALESCE(
                SUM(
                    CASE
                        WHEN booking_status <> 'cancelled' THEN s.price
                        ELSE 0
                    END
                ), 0
            ) AS booking_value
         FROM appointments a
         JOIN services s ON a.service_id = s.service_id"
    );

    $overview = $stmt->fetch();

    // Recorded payment information.
    $stmt = $pdo->query(
        "SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'paid' THEN amount
                        WHEN payment_status = 'refunded' THEN -amount
                        ELSE 0
                    END
                ), 0
            ) AS net_recorded_payments,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'pending' THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS pending_payments
         FROM payments"
    );

    $paymentSummary = $stmt->fetch();

    // Service popularity and booking value.
    $stmt = $pdo->query(
        "SELECT
            s.service_id,
            s.service_name,
            s.category,
            s.price,
            COUNT(a.appointment_id) AS booking_count,
            COALESCE(
                SUM(
                    CASE
                        WHEN a.booking_status <> 'cancelled' THEN s.price
                        ELSE 0
                    END
                ), 0
            ) AS booking_value
         FROM services s
         LEFT JOIN appointments a ON s.service_id = a.service_id
         GROUP BY
            s.service_id,
            s.service_name,
            s.category,
            s.price
         ORDER BY booking_count DESC, s.service_name ASC"
    );

    $serviceReport = $stmt->fetchAll();

    // Retrieve monthly appointment activity for the latest six months.
    $startMonth = new DateTimeImmutable('first day of this month');
    $startMonth = $startMonth->modify('-5 months');

    $startDate = $startMonth->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(appointment_date, '%Y-%m') AS month_key,
            COUNT(*) AS total_bookings
         FROM appointments
        WHERE appointment_date >= :start_date
  AND appointment_date < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
         GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
         ORDER BY month_key ASC"
    );

    $stmt->execute(['start_date' => $startDate]);
    $monthlyResults = $stmt->fetchAll();

    // Create all six month entries, including months with zero bookings.
    $monthlyActivity = [];

    for ($i = 0; $i < 6; $i++) {

        $month = $startMonth->modify("+{$i} months");
        $key = $month->format('Y-m');

        $monthlyActivity[$key] = [
            'label' => $month->format('M Y'),
            'total' => 0
        ];
    }

    foreach ($monthlyResults as $result) {

        if (isset($monthlyActivity[$result['month_key']])) {

            $monthlyActivity[$result['month_key']]['total'] =
                (int)$result['total_bookings'];
        }
    }

    $monthlyActivity = array_values($monthlyActivity);

    // Calculate chart scales safely.
    $maxMonthlyBookings = max(
        array_column($monthlyActivity, 'total') ?: [0]
    );

    $maxServiceBookings = max(
        array_map(
            static fn($service) => (int)$service['booking_count'],
            $serviceReport
        ) ?: [0]
    );

    // Avoid division by zero when there are no bookings.
    if ($maxMonthlyBookings === 0) {
        $maxMonthlyBookings = 1;
    }

    if ($maxServiceBookings === 0) {
        $maxServiceBookings = 1;
    }

} catch (PDOException $e) {

    error_log('Business reports error: ' . $e->getMessage());

    http_response_code(500);
    exit('Business reports could not be generated. Please try again later.');
}

// Format report figures.
$totalAppointments = (int)($overview['total_appointments'] ?? 0);
$pendingAppointments = (int)($overview['pending_appointments'] ?? 0);
$confirmedAppointments = (int)($overview['confirmed_appointments'] ?? 0);
$completedAppointments = (int)($overview['completed_appointments'] ?? 0);
$cancelledAppointments = (int)($overview['cancelled_appointments'] ?? 0);

$bookingValue = (float)($overview['booking_value'] ?? 0);
$netPayments = (float)($paymentSummary['net_recorded_payments'] ?? 0);
$pendingPayments = (float)($paymentSummary['pending_payments'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Business Reports | GlowWell Beauty Clinic</title>

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
            <p class="eyebrow">GLOWWELL BUSINESS INSIGHTS</p>

            <h1>Reports & Analytics</h1>

            <p>
                Monitor appointment activity, service popularity
                and recorded payment performance.
            </p>
        </div>

        <div class="admin-date">
            <?= e(date('l, d F Y')) ?>
        </div>

    </section>

    <section class="admin-content">

        <div class="report-heading">

            <div>
                <p class="eyebrow">CLINIC PERFORMANCE</p>
                <h2>Appointment Overview</h2>
            </div>

            <button type="button"
                    class="report-print-button"
                    onclick="window.print()">
                Print Report
            </button>

        </div>

        <div class="report-stat-grid">

            <article class="report-stat-card">
                <span>Total Appointments</span>
                <strong><?= $totalAppointments ?></strong>
            </article>

            <article class="report-stat-card">
                <span>Pending</span>
                <strong><?= $pendingAppointments ?></strong>
            </article>

            <article class="report-stat-card">
                <span>Confirmed</span>
                <strong><?= $confirmedAppointments ?></strong>
            </article>

            <article class="report-stat-card">
                <span>Completed</span>
                <strong><?= $completedAppointments ?></strong>
            </article>

            <article class="report-stat-card">
                <span>Cancelled</span>
                <strong><?= $cancelledAppointments ?></strong>
            </article>

        </div>

        <div class="report-section">

            <div class="report-heading">
                <div>
                    <p class="eyebrow">BOOKING ACTIVITY</p>
                    <h2>Monthly Appointment Trends</h2>
                </div>
            </div>

            <p class="report-description">
                Number of appointments recorded in each of the latest six months.
            </p>

            <div class="report-chart">

                <?php foreach ($monthlyActivity as $month): ?>

                    <?php
                    $barWidth = round(
                        ((int)$month['total'] / $maxMonthlyBookings) * 100
                    );
                    ?>

                    <div class="report-bar-row">

                        <span class="report-bar-label">
                            <?= e($month['label']) ?>
                        </span>

                        <div class="report-bar-track">

                            <div class="report-bar-fill"
                                 style="width: <?= $barWidth ?>%;">

                            </div>

                        </div>

                        <strong class="report-bar-value">
                            <?= (int)$month['total'] ?>
                        </strong>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div class="report-section">

            <div class="report-heading">
                <div>
                    <p class="eyebrow">CUSTOMER PREFERENCES</p>
                    <h2>Service Popularity</h2>
                </div>
            </div>

            <p class="report-description">
                Compare how frequently each GlowWell service has been booked.
            </p>

            <div class="service-report-list">

                <?php foreach ($serviceReport as $service): ?>

                    <?php
                    $serviceBarWidth = round(
                        ((int)$service['booking_count'] / $maxServiceBookings) * 100
                    );
                    ?>

                    <div class="service-report-item">

                        <div class="service-report-info">

                            <div>
                                <strong><?= e($service['service_name']) ?></strong>

                                <span><?= e($service['category']) ?></span>
                            </div>

                            <strong>
                                <?= (int)$service['booking_count'] ?>
                                bookings
                            </strong>

                        </div>

                        <div class="report-bar-track">

                            <div class="report-bar-fill"
                                 style="width: <?= $serviceBarWidth ?>%;">

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div class="report-section">

            <div class="report-heading">
                <div>
                    <p class="eyebrow">FINANCIAL OVERVIEW</p>
                    <h2>Payment Summary</h2>
                </div>
            </div>

            <div class="financial-report-grid">

                <article class="financial-report-card">
                    <span>Net Recorded Payments</span>

                    <strong>
                        R<?= number_format($netPayments, 2) ?>
                    </strong>

                    <p>
                        Paid transactions less recorded refunds.
                    </p>
                </article>

                <article class="financial-report-card">
                    <span>Pending Payments</span>

                    <strong>
                        R<?= number_format($pendingPayments, 2) ?>
                    </strong>

                    <p>
                        Transactions recorded as awaiting payment.
                    </p>
                </article>

                <article class="financial-report-card">
                    <span>Non-cancelled Booking Value</span>

                    <strong>
                        R<?= number_format($bookingValue, 2) ?>
                    </strong>

                    <p>
                        Service prices for bookings not cancelled.
                        This is not proof of payment.
                    </p>
                </article>

            </div>

            <p class="report-disclaimer">
                Financial figures are calculated from records in the
                payments and appointments tables. A booking is not
                automatically treated as a paid transaction.
            </p>

        </div>

        <div class="report-footer-note">
            <p>
                Report generated on
                <strong><?= e(date('d F Y, g:i A')) ?></strong>.
            </p>

            <p>
                GlowWell Beauty Clinic — Business Performance Report
            </p>
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
