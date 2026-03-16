<?php
// config/database.php

// Database configuration
define('DB_HOST', 'srv1740.hstgr.io');
define('DB_NAME', 'u966043993_vkm');
define('DB_USER', 'u966043993_vkm');
define('DB_PASS', 'Vkm@2026');

try {
    // Create PDO connection directly (not as a class)
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your configuration and try again.");
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Return the PDO object (optional)
return $pdo;
?>