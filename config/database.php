<?php
// Database connection. Edit for your environment (XAMPP defaults shown).
// Tip: create a dedicated MySQL user with rights only on this database instead of using root.
const DB_HOST = 'localhost';
const DB_NAME = 'rtu_equipment_hub';
const DB_USER = 'root';
const DB_PASS = '';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
    ]);
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.']);
    exit(1);
}
