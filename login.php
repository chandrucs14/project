<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Check if user needs to select shop (admin with multiple shops)
    if (isset($_SESSION['needs_shop_selection']) && $_SESSION['needs_shop_selection'] === true) {
        header("Location: select_shop.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$error = '';
$success = '';

// Check for session timeout message
if (isset($_SESSION['timeout_message'])) {
    $error = $_SESSION['timeout_message'];
    unset($_SESSION['timeout_message']);
}

// Check for registration success message
if (isset($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's a login request or password reset request
    if (isset($_POST['reset_email'])) {
        // Handle password reset request
        $email = trim($_POST['reset_email'] ?? '');
        
        if ($email === '') {
            $error = "Please enter your email address.";
        } else {
            try {
                // Check if email exists in database
                $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Store token in database (you'll need to add these columns to users table)
                    // For now, we'll simulate this. You may need to add reset_token and reset_token_expires columns
                    /*
                    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
                    $stmt->execute([$reset_token, $expires_at, $user['id']]);
                    
                    // Create reset link
                    $reset_link = "https://ecommer.in/billing/trading/reset_password.php?token=" . $reset_token;
                    
                    // Send email logic here
                    */
                    
                    $success = "Password reset instructions have been sent to your email.";
                } else {
                    $error = "No account found with this email address.";
                }
            } catch (Exception $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error = "Failed to process reset request. Please try again.";
            }
        }
    } else {
        // Handle login request
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) ? true : false;
        
        if ($username === '' || $password === '') {
            $error = "Please enter both username and password.";
        } else {
            try {
                // Get user by username or email
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password_hash, full_name, phone, role, is_active, 
                           last_login, created_at, shop_id, business_id
                    FROM users 
                    WHERE (username = ? OR email = ?) AND is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Password is correct
                    
                    // Set session variables
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    
                    // Set business and shop if available
                    if (isset($user['business_id']) && $user['business_id']) {
                        $_SESSION['business_id'] = (int)$user['business_id'];
                    }
                    
                    if (isset($user['shop_id']) && $user['shop_id']) {
                        $_SESSION['shop_id'] = (int)$user['shop_id'];
                    }
                    
                    // Handle "Remember Me" functionality
                    if ($remember) {
                        $selector = bin2hex(random_bytes(12));
                        $token = random_bytes(32);
                        $hashed_token = hash('sha256', $token);
                        
                        // Set expiry date (30 days from now)
                        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        // Delete any existing tokens for this user
                        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
                        $stmt->execute([$user['id']]);
                        
                        // Store new token (you'll need to create auth_tokens table)
                        /*
                        $stmt = $pdo->prepare("
                            INSERT INTO auth_tokens (user_id, selector, hashed_token, expires_at) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$user['id'], $selector, $hashed_token, $expiry]);
                        
                        // Set cookies
                        setcookie('remember', $selector . ':' . bin2hex($token), time() + (86400 * 30), '/', '', true, true);
                        */
                    }
                    
                    // Update last login
                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    // Log the login activity
                    $activity_stmt = $pdo->prepare("
                        INSERT INTO activity_logs (
                            user_id, activity_type_id, description, activity_data, created_at
                        ) VALUES (?, 1, ?, ?, NOW())
                    ");
                    
                    $activity_data = json_encode([
                        'username' => $user['username'],
                        'login_method' => isset($_POST['username']) ? 'username' : 'email',
                        'ip_address' => $_SERVER['REMOTE_ADDR'],
                        'user_agent' => $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $activity_stmt->execute([
                        $user['id'],
                        'User logged in successfully',
                        $activity_data
                    ]);
                    
                    // Check user role and redirect accordingly
                    if ($user['role'] === 'admin') {
                        // Admin might have multiple shops to select from
                        // Check if admin has multiple shops assigned
                        $shopStmt = $pdo->prepare("SELECT COUNT(*) FROM shops WHERE is_active = 1");
                        $shopStmt->execute();
                        $shopCount = $shopStmt->fetchColumn();
                        
                        if ($shopCount > 1) {
                            $_SESSION['needs_shop_selection'] = true;
                            header("Location: select_shop.php");
                            exit();
                        } else {
                            // Get the single shop
                            $shopStmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE is_active = 1 LIMIT 1");
                            $shopStmt->execute();
                            $shop = $shopStmt->fetch();
                            
                            if ($shop) {
                                $_SESSION['shop_id'] = $shop['id'];
                                $_SESSION['shop_name'] = $shop['shop_name'];
                                header("Location: dashboard.php");
                                exit();
                            } else {
                                $error = "No active shops found in the system.";
                            }
                        }
                    } elseif ($user['role'] === 'sales') {
                        // Sales staff - check if they have a shop assigned
                        if ($user['shop_id']) {
                            // Get shop details
                            $shopStmt = $pdo->prepare("SELECT shop_name FROM shops WHERE id = ? AND is_active = 1");
                            $shopStmt->execute([$user['shop_id']]);
                            $shop = $shopStmt->fetch();
                            
                            if ($shop) {
                                $_SESSION['shop_id'] = $user['shop_id'];
                                $_SESSION['shop_name'] = $shop['shop_name'];
                                header("Location: dashboard.php");
                                exit();
                            } else {
                                $error = "Your assigned shop is not active or doesn't exist.";
                            }
                        } else {
                            $error = "No shop assigned to your account. Please contact administrator.";
                        }
                    } else {
                        $error = "Invalid user role.";
                    }
                } else {
                    // Log failed login attempt
                    $failed_stmt = $pdo->prepare("
                        INSERT INTO activity_logs (
                            activity_type_id, description, activity_data, created_at
                        ) VALUES (1, ?, ?, NOW())
                    ");
                    
                    $failed_data = json_encode([
                        'attempted_username' => $username,
                        'ip_address' => $_SERVER['REMOTE_ADDR'],
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        'reason' => 'Invalid credentials'
                    ]);
                    
                    $failed_stmt->execute([
                        'Failed login attempt',
                        $failed_data
                    ]);
                    
                    $error = "Invalid username or password.";
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

// Check for remember me cookie
/*
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember'])) {
    list($selector, $token) = explode(':', $_COOKIE['remember']);
    $token = hex2bin($token);
    
    $stmt = $pdo->prepare("
        SELECT at.*, u.id as user_id, u.username, u.full_name, u.role, u.email, u.shop_id 
        FROM auth_tokens at 
        JOIN users u ON at.user_id = u.id 
        WHERE at.selector = ? AND at.expires_at > NOW() AND u.is_active = 1
    ");
    $stmt->execute([$selector]);
    $authToken = $stmt->fetch();
    
    if ($authToken && hash_equals($authToken['hashed_token'], hash('sha256', $token))) {
        $_SESSION['user_id'] = (int)$authToken['user_id'];
        $_SESSION['username'] = $authToken['username'];
        $_SESSION['full_name'] = $authToken['full_name'];
        $_SESSION['email'] = $authToken['email'];
        $_SESSION['role'] = $authToken['role'];
        $_SESSION['shop_id'] = $authToken['shop_id'];
        $_SESSION['login_time'] = time();
        
        // Redirect to dashboard
        header("Location: dashboard.php");
        exit();
    }
}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecommer - Login</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="assets/favicon/site.webmanifest">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-dark: #3651d4;
            --secondary-color: #7209b7;
            --accent-color: #f72585;
            --success-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-text: #2b2d42;
            --gray-text: #6c757d;
            --border-color: #e2e8f0;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 20px 50px rgba(67, 97, 238, 0.15);
            --radius: 16px;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark-text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.1;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            z-index: 1;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .login-header {
            text-align: center;
            padding: 40px 40px 20px;
        }
        
        .login-header .logo-big img {
            width: 120px;
            height: auto;
            margin-bottom: 20px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }
        
        .login-header h2 {
            color: var(--dark-text);
            font-weight: 700;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .login-header p {
            color: var(--gray-text);
            font-size: 14px;
        }
        
        .login-body {
            padding: 30px 40px 40px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-text);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .form-label i {
            color: var(--primary-color);
            font-size: 14px;
        }
        
        .input-group {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--border-color);
            transition: all 0.3s;
            background: white;
        }
        
        .input-group:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
        }
        
        .input-group-text {
            background-color: white;
            border: none;
            color: var(--gray-text);
            padding: 0 18px;
            font-size: 16px;
        }
        
        .form-control {
            border: none;
            padding: 14px 15px 14px 0;
            font-size: 15px;
            height: auto;
            box-shadow: none;
            background: transparent;
        }
        
        .form-control:focus {
            box-shadow: none;
            background: transparent;
        }
        
        .form-control::placeholder {
            color: #adb5bd;
            font-size: 14px;
        }
        
        .password-toggle {
            background: none;
            border: none;
            color: var(--gray-text);
            padding: 0 18px;
            cursor: pointer;
            transition: color 0.2s;
            font-size: 16px;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            font-weight: 600;
            font-size: 16px;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 12px;
            color: white;
            margin: 15px 0 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .btn-login:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }
        
        .form-check {
            margin: 15px 0;
            display: flex;
            align-items: center;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
            border: 2px solid var(--border-color);
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .form-check-label {
            color: var(--gray-text);
            font-size: 14px;
            cursor: pointer;
            user-select: none;
        }
        
        .forgot-password {
            text-align: right;
            margin: 10px 0;
        }
        
        .forgot-password a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .forgot-password a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: none;
            font-weight: 500;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-danger {
            background-color: #fff2f2;
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .register-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .register-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            transition: color 0.2s;
        }
        
        .register-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-top: 25px;
            color: var(--gray-text);
            font-size: 13px;
        }
        
        .security-badge i {
            color: #28a745;
            font-size: 16px;
        }
        
        .security-badge span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 0;
            padding: 20px 25px;
            border: none;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid var(--border-color);
            background: var(--light-bg);
        }
        
        .btn-reset {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-secondary {
            background: #e9ecef;
            border: none;
            color: var(--gray-text);
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-secondary:hover {
            background: #dee2e6;
            color: var(--dark-text);
        }
        
        /* Loading animation */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Demo credentials */
        .demo-credentials {
            background: var(--light-bg);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
            border: 1px dashed var(--border-color);
        }
        
        .demo-credentials p {
            margin: 5px 0;
            color: var(--gray-text);
        }
        
        .demo-credentials i {
            color: var(--primary-color);
            margin-right: 5px;
        }
        
        .demo-credentials .badge {
            background: var(--primary-color);
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-big">
                    <img src="assets/logo.png" alt="Ecommer Logo">
                </div>
                <h2>Welcome Back!</h2>
                <p>Sign in to continue to Ecommer</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm" novalidate>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user"></i> Username or Email
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" 
                                   name="username" 
                                   class="form-control" 
                                   placeholder="Enter your username or email" 
                                   required
                                   autofocus
                                   autocomplete="username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" 
                                   name="password" 
                                   id="password" 
                                   class="form-control" 
                                   placeholder="Enter your password" 
                                   required
                                   autocomplete="current-password">
                            <button type="button" class="password-toggle" id="togglePassword" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        
                        <div class="forgot-password">
                            <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                                <i class="fas fa-key me-1"></i> Forgot Password?
                            </a>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <span id="btnText">Sign In</span>
                        <span id="loading" style="display:none;">
                            <span class="spinner"></span> Signing In...
                        </span>
                    </button>
                    
                    <div class="register-link">
                        Don't have an account? <a href="register.php">Create Account</a>
                    </div>
                    
                    <div class="security-badge">
                        <span><i class="fas fa-shield-alt"></i> SSL Secured</span>
                        <span><i class="fas fa-lock"></i> Encrypted</span>
                        <span><i class="fas fa-clock"></i> 24/7 Support</span>
                    </div>
                    
                    <!-- Demo Credentials (Remove in production) -->
                    <div class="demo-credentials">
                        <p class="mb-2"><i class="fas fa-info-circle"></i> Demo Credentials:</p>
                        <p><strong>Admin:</strong> admin@ecommer.in / Admin@123 <span class="badge">Admin</span></p>
                        <p><strong>Sales:</strong> sales@ecommer.in / Sales@123 <span class="badge">Sales</span></p>
                        <p class="mb-0 text-muted"><small>*For testing purposes only</small></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="fas fa-key"></i>
                        Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="resetPasswordForm">
                    <div class="modal-body">
                        <p class="text-muted mb-4">
                            Enter your registered email address. We'll send you instructions to reset your password.
                        </p>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" 
                                       name="reset_email" 
                                       class="form-control" 
                                       placeholder="Enter your email address" 
                                       required
                                       autocomplete="email">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-reset" id="resetBtn">
                            <span id="resetBtnText">Send Reset Link</span>
                            <span id="resetLoading" style="display:none;">
                                <span class="spinner"></span> Sending...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Form submission loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const loading = document.getElementById('loading');
            
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        });
        
        // Reset password form submission
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('resetBtn');
            const btnText = document.getElementById('resetBtnText');
            const loading = document.getElementById('resetLoading');
            
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        });
        
        // Clear success message when modal is closed
        document.getElementById('forgotPasswordModal').addEventListener('hidden.bs.modal', function () {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                successAlert.remove();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
        
        // Prevent double form submission
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const submitButtons = form.querySelectorAll('button[type="submit"]');
                submitButtons.forEach(button => {
                    button.disabled = true;
                });
            });
        });
        
        // Add keyboard shortcut (Ctrl+Enter) to submit form
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                const form = document.getElementById('loginForm');
                if (form) {
                    form.submit();
                }
            }
        });
        
        // Input validation and formatting
        document.querySelector('input[name="username"]').addEventListener('input', function(e) {
            // Remove leading/trailing spaces
            this.value = this.value.trim();
        });
        
        // Show/Hide password requirements tooltip
        const passwordField = document.getElementById('password');
        passwordField.addEventListener('focus', function() {
            // You could show a tooltip with password requirements here
        });
        
        // Remember me checkbox enhancement
        const rememberCheck = document.getElementById('remember');
        rememberCheck.addEventListener('change', function() {
            if (this.checked) {
                // Store preference in session storage
                sessionStorage.setItem('remember_preference', 'true');
            }
        });
        
        // Load saved username if any
        window.addEventListener('load', function() {
            const savedUsername = localStorage.getItem('saved_username');
            if (savedUsername) {
                document.querySelector('input[name="username"]').value = savedUsername;
            }
        });
        
        // Save username on successful login (you might want to implement this server-side)
        document.getElementById('loginForm').addEventListener('submit', function() {
            const remember = document.getElementById('remember').checked;
            const username = document.querySelector('input[name="username"]').value;
            
            if (remember) {
                localStorage.setItem('saved_username', username);
            } else {
                localStorage.removeItem('saved_username');
            }
        });
    </script>
</body>
</html>