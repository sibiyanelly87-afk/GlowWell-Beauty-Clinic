
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Only administrators may manage staff.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$errors = [];

// Process staff registration and status updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';

    } elseif ($action === 'create') {

        // Collect staff registration details.
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $dateHired = trim($_POST['date_hired'] ?? '');
        $password = $_POST['password'] ?? '';

        // Validate the submitted information.
        if ($firstName === '' || strlen($firstName) > 50) {
            $errors[] = 'Enter a first name of no more than 50 characters.';
        }

        if ($lastName === '' || strlen($lastName) > 50) {
            $errors[] = 'Enter a last name of no more than 50 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!preg_match('/^[0-9+\s()\-]{7,20}$/', $phone)) {
            $errors[] = 'Enter a valid phone number.';
        }

        if ($jobTitle === '' || strlen($jobTitle) > 100) {
            $errors[] = 'Enter a valid job title.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'The password must contain at least 8 characters.';
        }

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateHired);

        if (
            !$parsedDate ||
            $parsedDate->format('Y-m-d') !== $dateHired ||
            $dateHired > date('Y-m-d')
        ) {
            $errors[] = 'Enter a valid hiring date that is not in the future.';
        }

        if (empty($errors)) {

            try {

                $pdo->beginTransaction();

                // Create the staff login account.
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $userStmt = $pdo->prepare(
                    "INSERT INTO users
                     (first_name, last_name, email, phone,
                      password_hash, role, account_status)
                     VALUES
                     (:first_name, :last_name, :email, :phone,
                      :password_hash, 'staff', 'active')"
                );

                $userStmt->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => $passwordHash
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                // Create the linked staff profile.
                $staffStmt = $pdo->prepare(
                    "INSERT INTO staff
                     (user_id, job_title, employment_status, date_hired)
                     VALUES
                     (:user_id, :job_title, 'active', :date_hired)"
                );

                $staffStmt->execute([
                    'user_id' => $newUserId,
                    'job_title' => $jobTitle,
                    'date_hired' => $dateHired
                ]);

                $newStaffId = (int)$pdo->lastInsertId();

                $pdo->commit();

                set_flash(
                    'success',
                    'The new staff member and login account were created successfully. Assign services and working hours before accepting bookings.'
                );

                header('Location: staff.php#staff-' . $newStaffId);
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Staff creation error: ' . $e->getMessage());

                $errors[] = 'The staff account could not be created. Check that the email address is not already registered.';
            }
        }

    } elseif ($action === 'update_status') {

        $staffId = filter_var(
            $_POST['staff_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['employment_status'] ?? '';

        if (!$staffId || $staffId < 1) {
            $errors[] = 'Invalid staff member selected.';

        } elseif (!in_array($newStatus, ['active', 'inactive'], true)) {
            $errors[] = 'Invalid employment status selected.';
        }

        if (empty($errors)) {

            try {

                $pdo->beginTransaction();

                // Find the associated staff login.
                $stmt = $pdo->prepare(
                    "SELECT user_id
                     FROM staff
                     WHERE staff_id = :staff_id
                     FOR UPDATE"
                );

                $stmt->execute(['staff_id' => $staffId]);
                $staffUser = $stmt->fetch();

                if (!$staffUser) {

                    $pdo->rollBack();
                    $errors[] = 'The selected staff member could not be found.';

                } else {

                    // Update employment status.
                    $updateStaff = $pdo->prepare(
                        "UPDATE staff
                         SET employment_status = :status
                         WHERE staff_id = :staff_id"
                    );

                    $updateStaff->execute([
                        'status' => $newStatus,
                        'staff_id' => $staffId
                    ]);

                    // Keep login access consistent with employment status.
                    $updateUser = $pdo->prepare(
                        "UPDATE users
                         SET account_status = :account_status,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE user_id = :user_id
                           AND role = 'staff'"
                    );

                    $updateUser->execute([
                        'account_status' => $newStatus,
                        'user_id' => $staffUser['user_id']
                    ]);

                    $pdo->commit();

                    set_flash(
                        'success',
                        'Staff employment and account access status updated successfully.'
                    );

                    header('Location: staff.php#staff-' . $staffId);
                    exit;
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Staff status update error: ' . $e->getMessage());

                $errors[] = 'The staff status could not be updated. Please try again.';
            }
        }

    } else {
        $errors[] = 'Unrecognised staff management action.';
    }
}

// Retrieve staff profiles and their activity.
try {

    $stmt = $pdo->query(
        "SELECT
            st.staff_id,
            st.job_title,
            st.employment_status,
            st.date_hired,
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.account_status,
            (
                SELECT COUNT(*)
                FROM staff_services ss
                WHERE ss.staff_id = st.staff_id
            ) AS service_count,
            (
                SELECT COUNT(*)
                FROM appointments a
                WHERE a.staff_id = st.staff_id
            ) AS appointment_count
         FROM staff st
         JOIN users u ON st.user_id = u.user_id
         ORDER BY st.created_at DESC"
    );

    $staffMembers = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log('Staff retrieval error: ' . $e->getMessage());

    http_response_code(500);
    exit('Staff records could not be retrieved. Please try again later.');
}

$activeCount = 0;
$inactiveCount = 0;

foreach ($staffMembers as $member) {
    if ($member['employment_status'] === 'active') {
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

    <title>Staff Management | GlowWell Beauty Clinic</title>

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
            <p class="eyebrow">GLOWWELL TEAM MANAGEMENT</p>

            <h1>Staff Management</h1>

            <p>
                Manage your beauty professionals, create staff accounts
                and monitor employment status.
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
                <span>Total Staff Members</span>
                <strong><?= count($staffMembers) ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Active Staff</span>
                <strong><?= $activeCount ?></strong>
            </article>

            <article class="customer-summary-card">
                <span>Inactive Staff</span>
                <strong><?= $inactiveCount ?></strong>
            </article>

        </div>

        <section class="staff-registration-panel">

            <div class="admin-section-heading">
                <div>
                    <p class="eyebrow">GROW YOUR TEAM</p>
                    <h2>Register New Staff Member</h2>
                </div>
            </div>

            <form method="POST" action="staff.php" class="staff-registration-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input type="hidden" name="action" value="create">

                <div class="staff-form-grid">

                    <div>
                        <label for="first_name">First Name</label>
                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            maxlength="50"
                            autocomplete="given-name"
                            required
                        >
                    </div>

                    <div>
                        <label for="last_name">Last Name</label>
                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            maxlength="50"
                            autocomplete="family-name"
                            required
                        >
                    </div>

                    <div>
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="100"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div>
                        <label for="phone">Phone Number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="20"
                            autocomplete="tel"
                            required
                        >
                    </div>

                    <div>
                        <label for="job_title">Job Title</label>
                        <input
                            type="text"
                            id="job_title"
                            name="job_title"
                            maxlength="100"
                            placeholder="e.g. Beauty Therapist"
                            required
                        >
                    </div>

                    <div>
                        <label for="date_hired">Date Hired</label>
                        <input
                            type="date"
                            id="date_hired"
                            name="date_hired"
                            max="<?= e(date('Y-m-d')) ?>"
                            required
                        >
                    </div>

                    <div class="staff-password-field">
                        <label for="password">Temporary Login Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                </div>

                <button type="submit" class="btn-action">
                    Create Staff Account
                </button>

            </form>

        </section>

        <div class="admin-section-heading">
            <div>
                <p class="eyebrow">YOUR CLINIC TEAM</p>
                <h2>Registered Staff Members</h2>
            </div>
        </div>

        <?php if (empty($staffMembers)): ?>

            <div class="empty-appointments">
                <h2>No staff profiles registered yet</h2>
                <p>New team members will appear here.</p>
            </div>

        <?php else: ?>

            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>
                        <tr>
                            <th>Staff ID</th>
                            <th>Name</th>
                            <th>Job Title</th>
                            <th>Email</th>
                            <th>Services</th>
                            <th>Appointments</th>
                            <th>Date Hired</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($staffMembers as $member): ?>

                            <?php
                            $isActive = $member['employment_status'] === 'active';

                            $nextStatus = $isActive ? 'inactive' : 'active';

                            $statusClass = $isActive
                                ? 'status-active'
                                : 'status-inactive';
                            ?>

                            <tr id="staff-<?= (int)$member['staff_id'] ?>">

                                <td>#<?= (int)$member['staff_id'] ?></td>

                                <td>
                                    <?= e(
                                        $member['first_name'] . ' ' .
                                        $member['last_name']
                                    ) ?>
                                </td>

                                <td><?= e($member['job_title']) ?></td>

                                <td><?= e($member['email']) ?></td>

                                <td><?= (int)$member['service_count'] ?></td>

                                <td><?= (int)$member['appointment_count'] ?></td>

                                <td>
                                    <?= e(date(
                                        'd M Y',
                                        strtotime($member['date_hired'])
                                    )) ?>
                                </td>

                                <td>
                                    <span class="appointment-status <?= e($statusClass) ?>">
                                        <?= e(ucfirst($member['employment_status'])) ?>
                                    </span>
                                </td>

                                <td>

                                    <form method="POST" action="staff.php">

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
                                            name="staff_id"
                                            value="<?= (int)$member['staff_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="employment_status"
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
