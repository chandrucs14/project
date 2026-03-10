<?php
// Database configuration
define('DB_HOST', 'srv1740.hstgr.io');
define('DB_NAME', 'u966043993_vkm');
define('DB_USER', 'u966043993_vkm'); // Your database username
define('DB_PASS', 'Vkm@2026'); // Your database password

// Create connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed: Unable to connect to the database. Please try again later.");
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');
?>