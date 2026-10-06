<?php
require_once __DIR__ . '/app.php';

// XAMPP default settings. Edit if your MySQL username/password is different.
$host = 'localhost';
$dbname = 'chama_system_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Small automatic update for existing installations:
    // adds account approval status if the project was installed before registration was added.
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status'");
        $hasAccountStatus = (int)$stmt->fetchColumn();
        if ($hasAccountStatus === 0) {
            $pdo->exec("ALTER TABLE users ADD account_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER role");
        }
    } catch (Throwable $ignored) {
        // Ignore this when tables have not yet been imported.
    }
} catch (PDOException $e) {
    die('Database connection failed. Check config/db.php and import database.sql. Error: ' . $e->getMessage());
}
