<?php
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Include database configuration
require_once 'config/database.php';

$error = '';
$success = '';

// Check if there's a logout message
if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success = "You have been successfully logged out.";
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;
    
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            // Prepare SQL statement to prevent SQL injection
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Password is correct, start session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                
                // Update last login time
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Log login activity
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, activity_type_id, description, created_by) 
                        VALUES (?, 1, 'User logged in', ?)
                    ");
                    $logStmt->execute([$user['id'], $user['id']]);
                } catch (Exception $e) {
                    // Log table might not exist or other error - ignore
                }
                
                // Set remember me cookie if requested
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    // Store token in database (you would need a remember_me table)
                    // For now, just set cookie with expiration
                    setcookie('remember_user', $user['id'], time() + (86400 * 30), "/"); // 30 days
                }
                
                // Redirect to dashboard
                header("Location: index.php");
                exit();
            } else {
                $error = "Invalid username/email or password.";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Login | VKM Business Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Business Management System" name="description" />
    <meta content="VKM" name="author" />
    
    <!-- App favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link href="assets/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet" type="text/css" />
    
    <!-- Icons CSS -->
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    
    <!-- App CSS -->
    <link href="assets/css/app.min.css" id="app-style" rel="stylesheet" type="text/css" />
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
           
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Animated background bubbles */
        body::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -150px;
            left: -150px;
            animation: float 6s ease-in-out infinite;
        }
        
        body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            bottom: -200px;
            right: -200px;
            animation: float 8s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }
        
        .auth-page {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glass morphism effect */
        .glass-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 50px 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo img {
            max-width: 180px;
            height: auto;
            margin-bottom: 20px;
            filter: drop-shadow(0 5px 15px rgba(0, 0, 0, 0.2));
            animation: fadeIn 1s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .logo h3 {
            color: white;
            font-weight: 700;
            font-size: 28px;
            margin: 10px 0 5px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
            letter-spacing: 1px;
        }
        
        .logo p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 15px;
            font-weight: 300;
        }
        
        .form-label {
            color: white;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        
        .input-group {
            margin-bottom: 5px;
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-right: none;
            border-radius: 12px 0 0 12px;
            color: white;
            padding: 0 15px;
        }
        
        .form-control {
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-left: none;
            border-radius: 0 12px 12px 0;
            color: white;
            font-size: 15px;
            padding: 10px 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: none;
            color: white;
        }
        
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
            font-weight: 300;
        }
        
        .input-group .btn-outline-secondary {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-left: none;
            border-radius: 0 12px 12px 0;
            color: white;
            height: 50px;
            transition: all 0.3s;
        }
        
        .input-group .btn-outline-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .btn-login {
            background: white;
            border: none;
            color: #667eea;
            height: 50px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            background: #f8f9fa;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .form-check-label {
            color: white;
            font-size: 14px;
            font-weight: 300;
        }
        
        .form-check-input {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            cursor: pointer;
        }
        
        .form-check-input:checked {
            background-color: white;
            border-color: white;
        }
        
        .form-check-input:focus {
            box-shadow: none;
            border-color: white;
        }
        
        .forgot-password {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 400;
            transition: all 0.3s;
            opacity: 0.9;
        }
        
        .forgot-password:hover {
            color: white;
            opacity: 1;
            text-decoration: underline;
        }
        
        .alert {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: white;
            font-size: 14px;
            padding: 12px 15px;
            margin-bottom: 25px;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            border-color: rgba(220, 53, 69, 0.3);
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            border-color: rgba(40, 167, 69, 0.3);
        }
        
        .alert .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.8;
        }
        
        .footer {
            text-align: center;
            margin-top: 25px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            font-weight: 300;
        }
        
        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 400;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        /* Input icon animations */
        .input-group-text i {
            transition: transform 0.3s;
        }
        
        .input-group:hover .input-group-text i {
            transform: scale(1.1);
        }
        
        /* Loading spinner */
        .spinner-border {
            color: white !important;
        }
        
        @media (max-width: 576px) {
            .glass-card {
                padding: 40px 25px;
            }
            
            .logo img {
                max-width: 150px;
            }
            
            .logo h3 {
                font-size: 24px;
            }
        }
        
        /* Remove number input spinners */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        
        input[type=number] {
            -moz-appearance: textfield;
        }
    </style>
</head>

<body>
    <div class="auth-page">
        <div class="glass-card">
            <div class="logo">
                <img src="assets/logo.png" alt="VKM Logo" onerror="this.src='assets/images/logo-default.png'">
                <h3>VKM System</h3>
                <p>Sign in to access your account</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="mdi mdi-alert-circle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="mdi mdi-check-circle me-2"></i>
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php" id="loginForm">
                <div class="mb-4">
                    <label for="username" class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="mdi mdi-account"></i></span>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Enter username or email" required 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="mdi mdi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="mdi mdi-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Remember me
                        </label>
                    </div>
                    <a href="forgot-password.php" class="forgot-password">
                        <i class="mdi mdi-lock-reset me-1"></i>Forgot password?
                    </a>
                </div>
                
                <button type="submit" class="btn btn-login" id="loginBtn">
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" id="loginSpinner"></span>
                    <span id="loginText">Sign In</span>
                </button>
            </form>
        </div>
        
        
    </div>

    <!-- JAVASCRIPT -->
    <script src="assets/libs/jquery/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/libs/metismenu/metisMenu.min.js"></script>
    <script src="assets/libs/simplebar/simplebar.min.js"></script>
    <script src="assets/libs/node-waves/waves.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            // Toggle password visibility
            $('#togglePassword').click(function() {
                const password = $('#password');
                const type = password.attr('type') === 'password' ? 'text' : 'password';
                password.attr('type', type);
                $(this).find('i').toggleClass('mdi-eye mdi-eye-off');
            });
            
            // Form submission with loading state
            $('#loginForm').submit(function() {
                $('#loginBtn').prop('disabled', true);
                $('#loginSpinner').removeClass('d-none');
                $('#loginText').text('Signing in...');
                return true;
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);

            // Add floating animation to inputs on focus
            $('.form-control').focus(function() {
                $(this).closest('.input-group').find('.input-group-text').css('transform', 'scale(1.05)');
            }).blur(function() {
                $(this).closest('.input-group').find('.input-group-text').css('transform', 'scale(1)');
            });
        });
    </script>
</body>
</html>