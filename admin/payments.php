
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Only administrators may record payments.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$errors = [];

// Process payment recording and updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    $appointmentId = filter_var(
        $_POST['appointment_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $paymentMethod = $_POST['payment_method'] ?? '';
    $paymentStatus = $_POST['payment_status'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    } elseif (!$appointmentId || $appointmentId < 1) {
        $errors[] = 'Please select a valid appointment.';
    } elseif (!in_array($paymentMethod, ['cash', 'card', 'eft'], true)) {
        $errors[] = 'Please select a valid payment method.';
    } elseif (!in_array($paymentStatus, ['pending', 'paid', 'refunded'], true)) {
        $errors[] = 'Please select a valid payment status.';
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            // Retrieve and lock the appointment and existing payment.
            $stmt = $pdo->prepare(
                "SELECT
                    a.appointment_id,
                    a.booking_status AS appointment_status,
                    s.price,
                    p.payment_status AS existing_payment_status,
                    p.payment_date AS existing_payment_date
                 FROM appointments a
                 JOIN services s ON a.service_id = s.service_id
                 LEFT JOIN payments p
                    ON a.appointment_id = p.appointment_id
                 WHERE a.appointment_id = :appointment_id
                 FOR UPDATE"
            );

            $stmt->execute([
                'appointment_id' => $appointmentId
            ]);

            $appointment = $stmt->fetch();

            if (!$appointment) {

                $pdo->rollBack();
                $errors[] = 'The selected appointment could not be found.';

            } elseif (!in_array(
                $appointment['appointment_status'],
                ['confirmed', 'completed'],
                true
            )) {

                $pdo->rollBack();

                $errors[] =
                    'Only confirmed or completed appointments can have payments recorded.';

            } else {

                $existingStatus = $appointment['existing_payment_status'];

                // Enforce sensible payment status transitions.
                $allowedStatuses = [];

                if ($existingStatus === null || $existingStatus === 'pending') {
                    $allowedStatuses = ['pending', 'paid'];

                } elseif ($existingStatus === 'paid') {
                    $allowedStatuses = ['paid', 'refunded'];

                } elseif ($existingStatus === 'refunded') {
                    $allowedStatuses = ['refunded'];
                }

                if (!in_array($paymentStatus, $allowedStatuses, true)) {

                    $pdo->rollBack();

                    $errors[] =
                        'The selected payment status is not permitted for this payment record.';

                } else {

                    $amount = (float)$appointment['price'];

                    // Save the actual payment date when paid.
                    // Preserve the original payment date when refunded.
                    if ($paymentStatus === 'paid') {

                        $paymentDate = date('Y-m-d H:i:s');

                    } elseif ($paymentStatus === 'refunded') {

                        $paymentDate = $appointment['existing_payment_date'];

                    } else {

                        $paymentDate = null;
                    }

                    // Insert a payment or update the existing payment.
                    $sql = "INSERT INTO payments
                            (
                                appointment_id,
                                amount,
                                payment_method,
                                payment_status,
                                payment_date
                            )
                            VALUES
                            (
                                :appointment_id,
                                :amount,
                                :payment_method,
                                :payment_status,
                                :payment_date
                            )
                            ON DUPLICATE KEY UPDATE
                                amount = :update_amount,
                                payment_method = :update_method,
                                payment_status = :update_status,
                                payment_date = :update_date";

                    $save = $pdo->prepare($sql);

                    $save->execute([
                        'appointment_id' => $appointmentId,
                        'amount' => number_format($amount, 2, '.', ''),
                        'payment_method' => $paymentMethod,
                        'payment_status' => $paymentStatus,
                        'payment_date' => $paymentDate,
                        'update_amount' => number_format($amount, 2, '.', ''),
                        'update_method' => $paymentMethod,
                        'update_status' => $paymentStatus,
                        'update_date' => $paymentDate
                    ]);

                    $pdo->commit();

                    set_flash(
                        'success',
                        'Payment record saved successfully.'
                    );

                    header('Location: payments.php#payment-' . $appointmentId);
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Payment recording error: ' . $e->getMessage());

            $errors[] =
                'The payment could not be saved. Please try again.';
        }
    }
}

// Retrieve payment totals.
try {

    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_payment_records,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'paid' THEN amount
                        WHEN payment_status = 'refunded' THEN -amount
                        ELSE 0
                    END
                ), 0
            ) AS net_paid,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'pending' THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS pending_amount,
            SUM(payment_status = 'refunded') AS refunded_count
         FROM payments"
    );

    $paymentSummary = $stmt->fetch();

    // Retrieve confirmed and completed appointments for payment recording.
    $stmt = $pdo->query(
        "SELECT
            a.appointment_id,
            a.appointment_date,
            a.booking_status AS appointment_status,
            cu.first_name AS customer_first_name,
            cu.last_name AS customer_last_name,
            s.service_name,
            s.price,
            p.payment_id,
            p.amount AS payment_amount,
            p.payment_method,
            p.payment_status,
            p.payment_date
         FROM appointments a
         JOIN users cu ON a.customer_id = cu.user_id
         JOIN services s ON a.service_id = s.service_id
         LEFT JOIN payments p ON a.appointment_id = p.appointment_id
         WHERE a.booking_status IN ('confirmed', 'completed')
         ORDER BY a.appointment_date DESC, a.appointment_id DESC"
    );

    $paymentAppointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Payment page retrieval error: ' . $e->getMessage());

    http_response_code(500);
    exit('Payment information could not be retrieved.');
}

$flash = get_flash();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Management | GlowWell Beauty Clinic</title>

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
        <a href="payments.php">Payments</a>
        <a href="reports.php">Reports</a>
        <a href="../logout.php">Log Out</a>
    </nav>

</header>

<main class="page-shell">

    <section class="admin-hero">

        <div>
            <p class="eyebrow">GLOWWELL FINANCIAL RECORDS</p>

            <h1>Payment Management</h1>

            <p>
                Record customer payments, monitor outstanding amounts
                and maintain payment and refund records.
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

        <div class="payment-summary-grid">

            <article class="payment-summary-card">
                <span>Payment Records</span>
                <strong><?= (int)$paymentSummary['total_payment_records'] ?></strong>
            </article>

            <article class="payment-summary-card">
                <span>Net Recorded Payments</span>
                <strong>R<?= number_format((float)$paymentSummary['net_paid'], 2) ?></strong>
            </article>

            <article class="payment-summary-card">
                <span>Pending Amount</span>
                <strong>R<?= number_format((float)$paymentSummary['pending_amount'], 2) ?></strong>
            </article>

            <article class="payment-summary-card">
                <span>Refunded Records</span>
                <strong><?= (int)($paymentSummary['refunded_count'] ?? 0) ?></strong>
            </article>

        </div>

        <div class="admin-section-heading">

            <div>
                <p class="eyebrow">CUSTOMER TRANSACTIONS</p>
                <h2>Record and Manage Payments</h2>
            </div>

        </div>

        <div class="payment-information">
            Payment amounts are taken from the selected service price.
            Only confirmed or completed appointments can have payment
            records. Selecting Paid records the payment date; selecting
            Refunded records a refund against a previously paid transaction.
            This page records payments manually and does not process cards
            through a payment gateway.
        </div>

        <?php if (empty($paymentAppointments)): ?>

            <div class="empty-appointments">
                <h2>No eligible appointments</h2>
                <p>Confirmed or completed appointments will appear here.</p>
            </div>

        <?php else: ?>

            <div class="payment-record-list">

                <?php foreach ($paymentAppointments as $item): ?>

                    <?php
                    $existingStatus = $item['payment_status'] ?? null;

                    if ($existingStatus === 'paid') {
                        $allowedStatuses = ['paid', 'refunded'];
                    } elseif ($existingStatus === 'refunded') {
                        $allowedStatuses = ['refunded'];
                    } else {
                        $allowedStatuses = ['pending', 'paid'];
                    }

                    $displayStatus = $existingStatus ?? 'not recorded';
                    ?>

                    <article
                        class="payment-record-card"
                        id="payment-<?= (int)$item['appointment_id'] ?>"
                    >

                        <div class="payment-record-heading">

                            <div>
                                <span class="appointment-label">
                                    APPOINTMENT #<?= (int)$item['appointment_id'] ?>
                                </span>

                                <h3><?= e($item['service_name']) ?></h3>

                                <p>
                                    Customer:
                                    <strong>
                                        <?= e(
                                            $item['customer_first_name'] . ' ' .
                                            $item['customer_last_name']
                                        ) ?>
                                    </strong>
                                </p>
                            </div>

                            <span class="appointment-status status-<?= e(
                                $existingStatus ?? 'pending'
                            ) ?>">
                                <?= e(ucfirst($displayStatus)) ?>
                            </span>

                        </div>

                        <div class="payment-record-details">

                            <p>
                                <strong>Appointment Date</strong>
                                <?= e(date(
                                    'd M Y',
                                    strtotime($item['appointment_date'])
                                )) ?>
                            </p>

                            <p>
                                <strong>Service Price</strong>
                                R<?= number_format((float)$item['price'], 2) ?>
                            </p>

                            <p>
                                <strong>Payment Method</strong>
                                <?= e(
                                    $item['payment_method']
                                        ? strtoupper($item['payment_method'])
                                        : 'Not recorded'
                                ) ?>
                            </p>

                            <p>
                                <strong>Payment Date</strong>
                                <?= $item['payment_date']
                                    ? e(date(
                                        'd M Y, g:i A',
                                        strtotime($item['payment_date'])
                                    ))
                                    : 'Not recorded' ?>
                            </p>

                        </div>

                        <form method="POST" action="payments.php"
                              class="payment-entry-form">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e(csrf_token()) ?>"
                            >

                            <input
                                type="hidden"
                                name="appointment_id"
                                value="<?= (int)$item['appointment_id'] ?>"
                            >

                            <div>
                                <label for="method-<?= (int)$item['appointment_id'] ?>">
                                    Payment Method
                                </label>

                                <select
                                    id="method-<?= (int)$item['appointment_id'] ?>"
                                    name="payment_method"
                                    required
                                >

                                    <option value="cash"
                                        <?= ($item['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>
                                        Cash
                                    </option>

                                    <option value="card"
                                        <?= ($item['payment_method'] ?? '') === 'card' ? 'selected' : '' ?>>
                                        Card
                                    </option>

                                    <option value="eft"
                                        <?= ($item['payment_method'] ?? '') === 'eft' ? 'selected' : '' ?>>
                                        EFT
                                    </option>

                                </select>
                            </div>

                            <div>
                                <label for="status-<?= (int)$item['appointment_id'] ?>">
                                    Payment Status
                                </label>

                                <select
                                    id="status-<?= (int)$item['appointment_id'] ?>"
                                    name="payment_status"
                                    required
                                >

                                    <?php foreach ($allowedStatuses as $statusOption): ?>

                                        <option
                                            value="<?= e($statusOption) ?>"
                                            <?= ($existingStatus ?? 'pending') === $statusOption
                                                ? 'selected' : '' ?>
                                        >
                                            <?= e(ucfirst($statusOption)) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <button type="submit" class="btn-action">
                                Save Payment Record
                            </button>

                        </form>

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
