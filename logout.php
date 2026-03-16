<?php
session_start();

// Include database configuration
require_once 'config/database.php';

// Log logout activity if user was logged in
if (isset($_SESSION['user_id'])) {
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, created_by) 
            VALUES (?, 2, 'User logged out', ?)
        ");
        $logStmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    } catch (Exception $e) {
        // Log table might not exist or other error - ignore
        error_log("Logout logging error: " . $e->getMessage());
    }
}

// Clear remember me cookie if set
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/');
}

// Destroy session
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect to login page with success message
header("Location: login.php?logout=success");
exit();
?>