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
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
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
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
        }
        
        @keyframes gradientBG {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }
        
        /* Animated floating particles */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .particle {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.2);
            animation: floatParticle 25s linear infinite;
            bottom: -150px;
            border-radius: 50%;
            opacity: 0.5;
        }
        
        @keyframes floatParticle {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.5;
                border-radius: 0;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }
        
        /* Random particle positions */
        .particle:nth-child(1) {
            left: 10%;
            width: 80px;
            height: 80px;
            animation-delay: 0s;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .particle:nth-child(2) {
            left: 20%;
            width: 60px;
            height: 60px;
            animation-delay: 2s;
            animation-duration: 17s;
            background: rgba(255, 255, 255, 0.15);
        }
        
        .particle:nth-child(3) {
            left: 35%;
            width: 40px;
            height: 40px;
            animation-delay: 4s;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .particle:nth-child(4) {
            left: 50%;
            width: 100px;
            height: 100px;
            animation-delay: 0s;
            animation-duration: 20s;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .particle:nth-child(5) {
            left: 65%;
            width: 50px;
            height: 50px;
            animation-delay: 7s;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .particle:nth-child(6) {
            left: 80%;
            width: 70px;
            height: 70px;
            animation-delay: 3s;
            animation-duration: 22s;
            background: rgba(255, 255, 255, 0.15);
        }
        
        .particle:nth-child(7) {
            left: 95%;
            width: 30px;
            height: 30px;
            animation-delay: 5s;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .auth-page {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }
        
        /* Enhanced glass morphism effect */
        .glass-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 30px;
            padding: 50px 40px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.8s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s;
        }
        
        .glass-card:hover::before {
            left: 100%;
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
            position: relative;
        }
        
        .logo img {
            max-width: 180px;
            height: auto;
            margin-bottom: 15px;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .logo h3 {
            color: white;
            font-weight: 700;
            font-size: 32px;
            margin: 10px 0 5px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            letter-spacing: 1px;
            animation: glow 2s ease-in-out infinite;
        }
        
        @keyframes glow {
            0%, 100% {
                text-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
            }
            50% {
                text-shadow: 0 0 20px rgba(255, 255, 255, 0.8);
            }
        }
        
        .logo p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 16px;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        
        .form-label {
            color: white;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .input-group {
            margin-bottom: 5px;
            transition: all 0.3s;
        }
        
        .input-group:hover {
            transform: translateY(-2px);
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-right: none;
            border-radius: 15px 0 0 15px;
            color: white;
            padding: 0 20px;
            font-size: 18px;
        }
        
        .form-control {
            height: 55px;
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-left: none;
            border-radius: 0 15px 15px 0;
            color: white;
            font-size: 16px;
            padding: 10px 20px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.35);
            border-color: rgba(255, 255, 255, 0.8);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 300;
        }
        
        .input-group .btn-outline-secondary {
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-left: none;
            border-radius: 0 15px 15px 0;
            color: white;
            height: 55px;
            width: 55px;
            padding: 0;
            transition: all 0.3s;
        }
        
        .input-group .btn-outline-secondary:hover {
            background: rgba(255, 255, 255, 0.4);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            height: 55px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .form-check-label {
            color: white;
            font-size: 14px;
            font-weight: 400;
            cursor: pointer;
        }
        
        .form-check-input {
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.4);
            cursor: pointer;
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: white;
        }
        
        .form-check-input:focus {
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
            border-color: white;
        }
        
        .forgot-password {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            opacity: 0.9;
            padding: 5px 10px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .forgot-password:hover {
            color: white;
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }
        
        .alert {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            color: white;
            font-size: 14px;
            padding: 15px 20px;
            margin-bottom: 25px;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.3);
            border-color: rgba(220, 53, 69, 0.5);
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.3);
            border-color: rgba(40, 167, 69, 0.5);
        }
        
        .alert .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.9;
        }
        
        .alert .btn-close:hover {
            opacity: 1;
        }
        
        /* Input icon animations */
        .input-group-text i {
            transition: all 0.3s;
        }
        
        .input-group:hover .input-group-text i {
            transform: rotate(10deg) scale(1.1);
        }
        
        /* Loading spinner */
        .spinner-border {
            color: white !important;
            width: 20px;
            height: 20px;
            margin-right: 8px;
        }
        
        @media (max-width: 576px) {
            .glass-card {
                padding: 40px 25px;
            }
            
            .logo img {
                max-width: 140px;
            }
            
            .logo h3 {
                font-size: 26px;
            }
            
            .btn-login {
                height: 50px;
                font-size: 16px;
            }
            
            .form-control {
                height: 50px;
                font-size: 14px;
            }
            
            .input-group .btn-outline-secondary {
                height: 50px;
                width: 50px;
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
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }
    </style>
</head>

<body>
    <!-- Animated particles -->
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    
    <div class="auth-page animate__animated animate__fadeIn">
        <div class="glass-card">
            <div class="logo">
                <img src="assets/logo.png" alt="VKM Logo" onerror="this.src='assets/images/logo-default.png'">
                <h3 class="animate__animated animate__bounceIn">Welcome..!</h3>
                <p class="animate__animated animate__fadeInUp">Sign in to access your account</p>
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
                <div class="mb-4 animate__animated animate__fadeInUp" style="animation-delay: 0.1s">
                    <label for="username" class="form-label">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="mdi mdi-account"></i></span>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Enter username or email" required 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="mb-4 animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
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
                
                <div class="mb-4 d-flex justify-content-between align-items-center animate__animated animate__fadeInUp" style="animation-delay: 0.3s">
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
                
                <button type="submit" class="btn btn-login animate__animated animate__fadeInUp" id="loginBtn" style="animation-delay: 0.4s">
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
                $(this).closest('.input-group').find('.input-group-text').css({
                    'transform': 'scale(1.1)',
                    'transition': 'all 0.3s'
                });
            }).blur(function() {
                $(this).closest('.input-group').find('.input-group-text').css({
                    'transform': 'scale(1)',
                    'transition': 'all 0.3s'
                });
            });

            // Add ripple effect to buttons
            $('.btn-login').click(function(e) {
                e.preventDefault();
                let ripple = document.createElement('span');
                ripple.classList.add('ripple');
                this.appendChild(ripple);
                let x = e.clientX - e.target.offsetLeft;
                let y = e.clientY - e.target.offsetTop;
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                setTimeout(() => {
                    ripple.remove();
                }, 300);
            });
        });
    </script>
    
    <style>
        /* Ripple effect */
        .btn-login {
            position: relative;
            overflow: hidden;
        }
        
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple-animation 0.6s ease-out;
            pointer-events: none;
        }
        
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    </style>
</body>
</html>