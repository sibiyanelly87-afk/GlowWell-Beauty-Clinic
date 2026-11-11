
<?php
declare(strict_types=1);

// Example local database configuration.
// Copy this file to db.php and adjust credentials for your environment.

$host = 'localhost';
$dbname = 'glowwell_db';
$username = 'root';
$password = ''; // Set your own local database password if required.
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please check your local database configuration.');
}
