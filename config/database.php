<?php
// config/database.php

// Force Indian Standard Time (UTC +05:30)
date_default_timezone_set('Asia/Kolkata');

// Database configuration
define('DB_HOST', 'srv1740.hstgr.io');
define('DB_NAME', 'u966043993_vkm');
define('DB_USER', 'u966043993_vkm');
define('DB_PASS', 'Vkm@2026');

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

    // Force MySQL session timezone to IST
    $pdo->exec("SET time_zone = '+05:30'");

} catch (PDOException $e) {

    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your configuration and try again.");

}

// Return PDO object
return $pdo;

?>