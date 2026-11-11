
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Only administrators may manage customer accounts.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$errors = [];

// Process customer account status updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    $customerId = filter_var(
        $_POST['customer_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $newStatus = $_POST['account_status'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif (!$customerId || $customerId < 1) {
        $errors[] = 'Invalid customer selected.';

    } elseif (!in_array($newStatus, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid account status selected.';
    }

    if (empty($errors)) {

        try {

            // Update only customer accounts, never staff or administrators.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = :account_status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :customer_id
                   AND role = 'customer'"
            );

            $stmt->execute([
                'account_status' => $newStatus,
                'customer_id' => $customerId
            ]);

            if ($stmt->rowCount() > 0) {

                set_flash(
                    'success',
                    'Customer account status updated successfully.'
                );

                header('Location: customers.php#customer-' . $customerId);
                exit;

            } else {

                // Check whether the customer exists and already has that status.
                $check = $pdo->prepare(
                    "SELECT user_id
                     FROM users
                     WHERE user_id = :customer_id
                       AND role = 'customer'
                       AND account_status = :account_status"
                );

                $check->execute([
                    'customer_id' => $customerId,
                    'account_status' => $newStatus
                ]);

                if ($check->fetch()) {

                    $errors[] = 'The customer already has this account status.';

                } else {

                    $errors[] = 'The selected customer could not be found.';
                }
            }

        } catch (PDOException $e) {

            error_log('Customer account update error: ' . $e->getMessage());

            $errors[] = 'Unable to update the customer account. Please try again.';
        }
    }
}

// Retrieve all customer accounts and their appointment totals.
try {

    $stmt = $pdo->query(
        "SELECT
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.account_status,
            u.created_at,
            (
                SELECT COUNT(*)
                FROM appointments a
                WHERE a.customer_id = u.user_id
            ) AS appointment_count
         FROM users u
         WHERE u.role = 'customer'
         ORDER BY u.created_at DESC"
    );

    $customers = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Customer retrieval error: ' . $e->getMessage());

    http_response_code(500);
    exit('Customer records could not be retrieved. Please try again later.');
}

$activeCount = 0;
$inactiveCount = 0;

foreach ($customers as $customer) {

    if ($customer['account_status'] === 'active') {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

$flash = get_flash();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Management | GlowWell Beauty Clinic</title>

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
            <p class="eyebrow">GLOWWELL CUSTOMER CARE</p>

            <h1>Customer Management</h1>

            <p>
                View registered customers, monitor their appointment
                activity and manage account access.
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

        <div class="customer-summary-grid">

            <article class="customer-summary-card">
                <span>Total Customers</span>
                <strong><?= count($customers) ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Active Accounts</span>
                <strong><?= $activeCount ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Inactive Accounts</span>
                <strong><?= $inactiveCount ?></strong>
            </article>

        </div>

        <div class="admin-section-heading">

            <div>
                <p class="eyebrow">CUSTOMER RECORDS</p>
                <h2>Registered Customers</h2>
            </div>

        </div>

        <?php if (empty($customers)): ?>

            <div class="empty-appointments">
                <h2>No customers registered yet</h2>
                <p>New customer accounts will appear here.</p>
            </div>

        <?php else: ?>

            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>
                        <tr>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Email Address</th>
                            <th>Phone Number</th>
                            <th>Appointments</th>
                            <th>Account Status</th>
                            <th>Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($customers as $customer): ?>

                            <?php
                            $isActive = $customer['account_status'] === 'active';

                            $statusClass = $isActive
                                ? 'status-active'
                                : 'status-inactive';

                            $nextStatus = $isActive
                                ? 'inactive'
                                : 'active';
                            ?>

                            <tr id="customer-<?= (int)$customer['user_id'] ?>">

                                <td>
                                    #<?= (int)$customer['user_id'] ?>
                                </td>

                                <td>
                                    <?= e(
                                        $customer['first_name'] . ' ' .
                                        $customer['last_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($customer['email']) ?>
                                </td>

                                <td>
                                    <?= e($customer['phone']) ?>
                                </td>

                                <td>
                                    <?= (int)$customer['appointment_count'] ?>
                                </td>

                                <td>
                                    <span class="appointment-status <?= e($statusClass) ?>">
                                        <?= e(ucfirst($customer['account_status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e(date(
                                        'd M Y',
                                        strtotime($customer['created_at'])
                                    )) ?>
                                </td>

                                <td>

                                    <form method="POST" action="customers.php">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(csrf_token()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="customer_id"
                                            value="<?= (int)$customer['user_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="account_status"
                                            value="<?= e($nextStatus) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="customer-status-button <?= $isActive ? 'deactivate-button' : 'activate-button' ?>"
                                        >
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>

                                    </form>

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
