
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Protect the customer services page.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: ../index.php');
    exit;
}

// Retrieve active services from the database.
try {
    $stmt = $pdo->prepare(
        "SELECT service_id, service_name, description,
                category, duration_minutes, price
         FROM services
         WHERE service_status = 'active'
         ORDER BY category, service_name"
    );

    $stmt->execute();
    $services = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Services retrieval error: ' . $e->getMessage());
    $services = [];
    $database_error = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Services | GlowWell Beauty Clinic</title>
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

    <section class="hero-panel">
        <p class="eyebrow">GLOWWELL BEAUTY CLINIC</p>

        <h1>Discover Your Glow</h1>

        <p class="hero-description">
            Take time for yourself. Explore our beauty and wellness
            treatments, thoughtfully designed to help you relax,
            refresh and feel your best.
        </p>
    </section>

    <section class="catalogue-section">

        <div class="section-heading">
            <p class="eyebrow">YOUR WELLNESS JOURNEY</p>
            <h2>Explore Our Services</h2>
            <p>Choose a treatment that suits your needs.</p>
        </div>

        <?php if (!empty($database_error)): ?>

            <div class="message-error" role="alert">
                Our services are temporarily unavailable.
                Please try again later.
            </div>

        <?php elseif (empty($services)): ?>

            <div class="message-info">
                No active services are currently available.
                Please check again later.
            </div>

        <?php else: ?>

            <div class="service-grid">

                <?php foreach ($services as $service): ?>

                    <article class="service-card">

                        <span class="service-category">
                            <?= e($service['category']) ?>
                        </span>

                        <h3><?= e($service['service_name']) ?></h3>

                        <p class="service-description">
                            <?= e($service['description']) ?>
                        </p>

                        <div class="service-meta">
                            <span>
                                <?= (int) $service['duration_minutes'] ?>
                                minutes
                            </span>

                            <strong>
                                R<?= number_format((float) $service['price'], 2) ?>
                            </strong>
                        </div>

                        <a class="btn btn-primary"
                           href="book.php?service_id=<?= (int) $service['service_id'] ?>">
                            Book This Service
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> GlowWell Beauty Clinic.
       Your beauty, our passion.</p>
</footer>

</body>
</html>
