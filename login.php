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
    
    <!-- Custom CSS -->
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        
        .auth-page {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            background: white;
        }
        
        .card-body {
            padding: 50px 40px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo img {
            max-width: 180px;
            height: auto;
            margin-bottom: 20px;
        }
        
        .logo h3 {
            color: #333;
            font-weight: 600;
            font-size: 24px;
            margin: 10px 0 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
        }
        
        .form-control {
            height: 48px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding: 10px 15px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }
        
        .input-group-text {
            background: transparent;
            border: 1px solid #e0e0e0;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #667eea;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        
        .input-group .btn-outline-secondary {
            border: 1px solid #e0e0e0;
            border-left: none;
            border-radius: 0 10px 10px 0;
            color: #667eea;
        }
        
        .input-group .btn-outline-secondary:hover {
            background: #f8f9fa;
            color: #764ba2;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            height: 48px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .form-check-label {
            color: #666;
            font-size: 14px;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .forgot-password:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 10px;
            font-size: 14px;
            padding: 12px 15px;
            margin-bottom: 25px;
        }
        
        .footer {
            text-align: center;
            margin-top: 25px;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
        }
        
        .footer a {
            color: white;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 576px) {
            .card-body {
                padding: 40px 25px;
            }
            
            .logo img {
                max-width: 150px;
            }
        }
    </style>
</head>

<body>
    <div class="auth-page">
        <div class="card">
            <div class="card-body">
                <div class="logo">
                    <img src="assets/logo.png" alt="VKM Logo" onerror="this.src='assets/images/logo-default.png'">
                    <h3>VKM System</h3>
                    <p class="text-muted">Sign in to access your account</p>
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
        
        <!-- Footer -->
        <div class="footer">
            <p class="mb-0">&copy; <?= date('Y') ?> VKM Business Management System. All rights reserved.</p>
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
        });
    </script>
</body>
</html>