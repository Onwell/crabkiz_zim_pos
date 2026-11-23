<?php
// db.php
declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'crabkidz_zim_db';
$DB_USER = 'root';          // change to your MySQL user
$DB_PASS = '';              // change to your MySQL password
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log this instead of echoing
    http_response_code(500);
    exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}