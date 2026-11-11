
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the service management page.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$errors = [];
$editingService = null;

// Process service management actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($token)) {

        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif ($action === 'create' || $action === 'update') {

        $serviceId = filter_var(
            $_POST['service_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $serviceName = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $duration = filter_var(
            $_POST['duration_minutes'] ?? null,
            FILTER_VALIDATE_INT
        );
        $priceInput = trim($_POST['price'] ?? '');

        // Validate service information.
        if ($serviceName === '' || strlen($serviceName) > 100) {
            $errors[] = 'Service name is required and must not exceed 100 characters.';
        }

        if (strlen($description) > 5000) {
            $errors[] = 'The service description is too long.';
        }

        if ($category === '' || strlen($category) > 60) {
            $errors[] = 'Category is required and must not exceed 60 characters.';
        }

        if ($duration === false || $duration < 1 || $duration > 1440) {
            $errors[] = 'Duration must be between 1 and 1440 minutes.';
        }

        if (
            $priceInput === '' ||
            !is_numeric($priceInput) ||
            (float)$priceInput < 0 ||
            (float)$priceInput > 99999999.99
        ) {
            $errors[] = 'Enter a valid non-negative service price.';
        }

        if ($action === 'update' && (!$serviceId || $serviceId < 1)) {
            $errors[] = 'Invalid service selected for editing.';
        }

        if (empty($errors)) {

            try {

                if ($action === 'create') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO services
                         (service_name, description, category,
                          duration_minutes, price, service_status)
                         VALUES
                         (:service_name, :description, :category,
                          :duration, :price, 'active')"
                    );

                    $stmt->execute([
                        'service_name' => $serviceName,
                        'description' => $description,
                        'category' => $category,
                        'duration' => $duration,
                        'price' => number_format((float)$priceInput, 2, '.', '')
                    ]);

                    $newServiceId = (int)$pdo->lastInsertId();

                    set_flash('success', 'New service added successfully.');

                    header('Location: services.php#service-' . $newServiceId);
                    exit;

                } else {

                    $stmt = $pdo->prepare(
                        "UPDATE services
                         SET service_name = :service_name,
                             description = :description,
                             category = :category,
                             duration_minutes = :duration,
                             price = :price
                         WHERE service_id = :service_id"
                    );

                    $stmt->execute([
                        'service_name' => $serviceName,
                        'description' => $description,
                        'category' => $category,
                        'duration' => $duration,
                        'price' => number_format((float)$priceInput, 2, '.', ''),
                        'service_id' => $serviceId
                    ]);

                    set_flash('success', 'Service details updated successfully.');

                    header('Location: services.php#service-' . $serviceId);
                    exit;
                }

            } catch (PDOException $e) {

                error_log('Service management error: ' . $e->getMessage());

                if ($e->getCode() === '23000') {
                    $errors[] = 'A service with this name already exists.';
                } else {
                    $errors[] = 'The service could not be saved. Please try again.';
                }
            }
        }

        // Keep submitted details available if validation fails.
        if ($action === 'update') {
            $editingService = [
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'description' => $description,
                'category' => $category,
                'duration_minutes' => $duration,
                'price' => $priceInput
            ];
        }

    } elseif ($action === 'update_status') {

        $serviceId = filter_var(
            $_POST['service_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['service_status'] ?? '';

        if (!$serviceId || $serviceId < 1) {
            $errors[] = 'Invalid service selected.';

        } elseif (!in_array($newStatus, ['active', 'inactive'], true)) {
            $errors[] = 'Invalid service status selected.';
        }

        if (empty($errors)) {

            try {

                $stmt = $pdo->prepare(
                    "UPDATE services
                     SET service_status = :service_status
                     WHERE service_id = :service_id"
                );

                $stmt->execute([
                    'service_status' => $newStatus,
                    'service_id' => $serviceId
                ]);

                if ($stmt->rowCount() > 0) {

                    set_flash('success', 'Service availability updated successfully.');

                    header('Location: services.php#service-' . $serviceId);
                    exit;

                } else {

                    $check = $pdo->prepare(
                        "SELECT service_id
                         FROM services
                         WHERE service_id = :service_id
                           AND service_status = :service_status"
                    );

                    $check->execute([
                        'service_id' => $serviceId,
                        'service_status' => $newStatus
                    ]);

                    if ($check->fetch()) {
                        $errors[] = 'The service already has this status.';
                    } else {
                        $errors[] = 'The selected service could not be found.';
                    }
                }

            } catch (PDOException $e) {

                error_log('Service status update error: ' . $e->getMessage());

                $errors[] = 'Service availability could not be updated.';
            }
        }

    } else {
        $errors[] = 'Unrecognised service management action.';
    }
}

// Retrieve the service selected for editing.
if ($editingService === null && isset($_GET['edit_id'])) {

    $editId = filter_var($_GET['edit_id'], FILTER_VALIDATE_INT);

    if (!$editId || $editId < 1) {
        http_response_code(400);
        exit('Invalid service ID.');
    }

    $stmt = $pdo->prepare(
        "SELECT service_id, service_name, description,
                category, duration_minutes, price
         FROM services
         WHERE service_id = :service_id"
    );

    $stmt->execute(['service_id' => $editId]);
    $editingService = $stmt->fetch();

    if (!$editingService) {
        http_response_code(404);
        exit('The selected service could not be found.');
    }
}

// Retrieve all services.
try {

    $stmt = $pdo->query(
        "SELECT
            s.service_id,
            s.service_name,
            s.description,
            s.category,
            s.duration_minutes,
            s.price,
            s.service_status,
            (
                SELECT COUNT(*)
                FROM appointments a
                WHERE a.service_id = s.service_id
            ) AS appointment_count
         FROM services s
         ORDER BY s.category, s.service_name"
    );

    $services = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Service retrieval error: ' . $e->getMessage());

    http_response_code(500);
    exit('Service records could not be retrieved.');
}

$activeCount = 0;
$inactiveCount = 0;

foreach ($services as $service) {

    if ($service['service_status'] === 'active') {
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

    <title>Service Management | GlowWell Beauty Clinic</title>

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
            <p class="eyebrow">GLOWWELL TREATMENT MANAGEMENT</p>

            <h1>Service Management</h1>

            <p>
                Maintain your clinic's treatments, categories,
                prices and service availability.
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
                <span>Total Services</span>
                <strong><?= count($services) ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Active Services</span>
                <strong><?= $activeCount ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Inactive Services</span>
                <strong><?= $inactiveCount ?></strong>
            </article>

        </div>

        <section class="service-management-panel">

            <div class="admin-section-heading">

                <div>
                    <p class="eyebrow">CLINIC TREATMENTS</p>

                    <h2>
                        <?= $editingService ? 'Edit Service' : 'Add New Service' ?>
                    </h2>
                </div>

                <?php if ($editingService): ?>
                    <a href="services.php" class="admin-text-link">
                        Cancel Editing
                    </a>
                <?php endif; ?>

            </div>

            <form method="POST" action="services.php<?= $editingService ? '?edit_id=' . (int)$editingService['service_id'] : '' ?>"
                  class="service-management-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="<?= $editingService ? 'update' : 'create' ?>"
                >

                <?php if ($editingService): ?>
                    <input
                        type="hidden"
                        name="service_id"
                        value="<?= (int)$editingService['service_id'] ?>"
                    >
                <?php endif; ?>

                <div class="service-form-grid">

                    <div>
                        <label for="service_name">Service Name</label>

                        <input
                            type="text"
                            id="service_name"
                            name="service_name"
                            maxlength="100"
                            value="<?= e($editingService['service_name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="category">Category</label>

                        <input
                            type="text"
                            id="category"
                            name="category"
                            maxlength="60"
                            placeholder="e.g. Facials, Massages"
                            value="<?= e($editingService['category'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="duration_minutes">Duration (minutes)</label>

                        <input
                            type="number"
                            id="duration_minutes"
                            name="duration_minutes"
                            min="1"
                            max="1440"
                            value="<?= e($editingService['duration_minutes'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="price">Price (R)</label>

                        <input
                            type="number"
                            id="price"
                            name="price"
                            min="0"
                            max="99999999.99"
                            step="0.01"
                            value="<?= e($editingService['price'] ?? '') ?>"
                            required
                        >
                    </div>

                </div>

                <label for="description">Service Description</label>

                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    maxlength="5000"
                    placeholder="Describe the treatment and what customers can expect..."
                ><?= e($editingService['description'] ?? '') ?></textarea>

                <button type="submit" class="btn-action">
                    <?= $editingService ? 'Save Service Changes' : 'Add Service' ?>
                </button>

            </form>

        </section>

        <div class="admin-section-heading">

            <div>
                <p class="eyebrow">YOUR CLINIC OFFERINGS</p>
                <h2>All Services</h2>
            </div>

        </div>

        <?php if (empty($services)): ?>

            <div class="empty-appointments">
                <h2>No services have been added yet.</h2>
            </div>

        <?php else: ?>

            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>
                        <tr>
                            <th>Service ID</th>
                            <th>Service Name</th>
                            <th>Category</th>
                            <th>Duration</th>
                            <th>Price</th>
                            <th>Appointments</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($services as $service): ?>

                            <?php
                            $isActive = $service['service_status'] === 'active';

                            $nextStatus = $isActive ? 'inactive' : 'active';

                            $statusClass = $isActive
                                ? 'status-active'
                                : 'status-inactive';
                            ?>

                            <tr id="service-<?= (int)$service['service_id'] ?>">

                                <td>#<?= (int)$service['service_id'] ?></td>

                                <td>
                                    <strong><?= e($service['service_name']) ?></strong>
                                </td>

                                <td><?= e($service['category']) ?></td>

                                <td>
                                    <?= (int)$service['duration_minutes'] ?> minutes
                                </td>

                                <td>
                                    R<?= number_format((float)$service['price'], 2) ?>
                                </td>

                                <td>
                                    <?= (int)$service['appointment_count'] ?>
                                </td>

                                <td>
                                    <span class="appointment-status <?= e($statusClass) ?>">
                                        <?= e(ucfirst($service['service_status'])) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="service-action-group">

                                        <a
                                            href="services.php?edit_id=<?= (int)$service['service_id'] ?>"
                                            class="service-edit-link"
                                        >
                                            Edit
                                        </a>

                                        <form method="POST" action="services.php">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="update_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="service_id"
                                                value="<?= (int)$service['service_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="service_status"
                                                value="<?= e($nextStatus) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="customer-status-button <?= $isActive ? 'deactivate-button' : 'activate-button' ?>"
                                            >
                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>

                                        </form>

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
