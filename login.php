<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['needs_shop_selection']) && $_SESSION['needs_shop_selection'] === true) {
        header("Location: select_shop.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$error = '';
$success = '';

// Test database connection
try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    $error = "Database connection failed: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_email'])) {
        // Handle password reset request
        $email = trim($_POST['reset_email'] ?? '');
        
        if ($email === '') {
            $error = "Please enter your email address.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
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
                // Log the login attempt
                error_log("Login attempt for username/email: " . $username);
                
                // First, check if the users table exists and has records
                $checkTable = $pdo->query("SHOW TABLES LIKE 'users'");
                if ($checkTable->rowCount() == 0) {
                    error_log("Users table does not exist!");
                    $error = "System configuration error. Please contact administrator.";
                } else {
                    // Check if there are any users in the table
                    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                    error_log("Total users in database: " . $userCount);
                    
                    if ($userCount == 0) {
                        $error = "No users found in the system. Please register first.";
                    } else {
                        // Get user by username or email
                        $stmt = $pdo->prepare("
                            SELECT id, username, email, password_hash, full_name, phone, role, is_active, 
                                   last_login, created_at, shop_id, business_id
                            FROM users 
                            WHERE (username = ? OR email = ?)
                            LIMIT 1
                        ");
                        $stmt->execute([$username, $username]);
                        $user = $stmt->fetch();
                        
                        if ($user) {
                            error_log("User found: " . $user['username'] . ", Active: " . $user['is_active']);
                            
                            // Check if user is active
                            if ($user['is_active'] != 1) {
                                error_log("User account is inactive: " . $user['username']);
                                $error = "Your account has been deactivated. Please contact administrator.";
                            } else {
                                // Verify password
                                if (password_verify($password, $user['password_hash'])) {
                                    error_log("Password verification successful for user: " . $user['username']);
                                    
                                    // Set session variables
                                    $_SESSION['user_id'] = (int)$user['id'];
                                    $_SESSION['username'] = $user['username'];
                                    $_SESSION['full_name'] = $user['full_name'];
                                    $_SESSION['email'] = $user['email'];
                                    $_SESSION['role'] = $user['role'];
                                    $_SESSION['login_time'] = time();
                                    
                                    if (isset($user['business_id']) && $user['business_id']) {
                                        $_SESSION['business_id'] = (int)$user['business_id'];
                                    }
                                    
                                    if (isset($user['shop_id']) && $user['shop_id']) {
                                        $_SESSION['shop_id'] = (int)$user['shop_id'];
                                    }
                                    
                                    // Update last login
                                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                                    $updateStmt->execute([$user['id']]);
                                    
                                    // Log the login activity
                                    try {
                                        $activity_stmt = $pdo->prepare("
                                            INSERT INTO activity_logs (
                                                user_id, activity_type_id, description, activity_data, created_at
                                            ) VALUES (?, 1, ?, ?, NOW())
                                        ");
                                        
                                        $activity_data = json_encode([
                                            'username' => $user['username'],
                                            'login_method' => 'username/email',
                                            'ip_address' => $_SERVER['REMOTE_ADDR'],
                                            'user_agent' => $_SERVER['HTTP_USER_AGENT']
                                        ]);
                                        
                                        $activity_stmt->execute([
                                            $user['id'],
                                            'User logged in successfully',
                                            $activity_data
                                        ]);
                                    } catch (Exception $e) {
                                        error_log("Failed to log activity: " . $e->getMessage());
                                        // Continue even if activity logging fails
                                    }
                                    
                                    // Check user role and redirect
                                    if ($user['role'] === 'admin') {
                                        // Check if there are multiple shops
                                        try {
                                            $shopStmt = $pdo->query("SELECT COUNT(*) FROM shops WHERE is_active = 1");
                                            $shopCount = $shopStmt->fetchColumn();
                                            
                                            if ($shopCount > 1) {
                                                $_SESSION['needs_shop_selection'] = true;
                                                header("Location: select_shop.php");
                                                exit();
                                            } else {
                                                $shopStmt = $pdo->query("SELECT id, shop_name FROM shops WHERE is_active = 1 LIMIT 1");
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
                                        } catch (Exception $e) {
                                            error_log("Shop check error: " . $e->getMessage());
                                            header("Location: dashboard.php");
                                            exit();
                                        }
                                    } elseif ($user['role'] === 'sales') {
                                        if ($user['shop_id']) {
                                            try {
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
                                            } catch (Exception $e) {
                                                error_log("Shop fetch error: " . $e->getMessage());
                                                $error = "Error accessing shop details.";
                                            }
                                        } else {
                                            $error = "No shop assigned to your account. Please contact administrator.";
                                        }
                                    } else {
                                        $error = "Invalid user role.";
                                    }
                                } else {
                                    error_log("Password verification failed for user: " . $user['username']);
                                    
                                    // Log failed login attempt
                                    try {
                                        $failed_stmt = $pdo->prepare("
                                            INSERT INTO activity_logs (
                                                activity_type_id, description, activity_data, created_at
                                            ) VALUES (1, ?, ?, NOW())
                                        ");
                                        
                                        $failed_data = json_encode([
                                            'attempted_username' => $username,
                                            'ip_address' => $_SERVER['REMOTE_ADDR'],
                                            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                                            'reason' => 'Invalid password'
                                        ]);
                                        
                                        $failed_stmt->execute([
                                            'Failed login attempt - invalid password',
                                            $failed_data
                                        ]);
                                    } catch (Exception $e) {
                                        error_log("Failed to log failed attempt: " . $e->getMessage());
                                    }
                                    
                                    $error = "Invalid username or password.";
                                }
                            }
                        } else {
                            error_log("No user found with username/email: " . $username);
                            
                            // Log failed login attempt for non-existent user
                            try {
                                $failed_stmt = $pdo->prepare("
                                    INSERT INTO activity_logs (
                                        activity_type_id, description, activity_data, created_at
                                    ) VALUES (1, ?, ?, NOW())
                                ");
                                
                                $failed_data = json_encode([
                                    'attempted_username' => $username,
                                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                                    'reason' => 'User not found'
                                ]);
                                
                                $failed_stmt->execute([
                                    'Failed login attempt - user not found',
                                    $failed_data
                                ]);
                            } catch (Exception $e) {
                                error_log("Failed to log failed attempt: " . $e->getMessage());
                            }
                            
                            $error = "Invalid username or password.";
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $error = "Login failed. Please try again. (Error: " . $e->getMessage() . ")";
            }
        }
    }
}

// Get list of users for debugging (remove in production)
$debug_users = [];
try {
    $stmt = $pdo->query("SELECT id, username, email, role, is_active FROM users LIMIT 5");
    $debug_users = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore errors in debug query
}
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
        /* Your existing styles here */
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
        }
        
        .login-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            animation: fadeInUp 0.8s ease-out;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
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
        }
        
        .form-control {
            border: none;
            padding: 14px 15px 14px 0;
            font-size: 15px;
            height: auto;
            box-shadow: none;
        }
        
        .form-control:focus {
            box-shadow: none;
        }
        
        .password-toggle {
            background: none;
            border: none;
            color: var(--gray-text);
            padding: 0 18px;
            cursor: pointer;
            transition: color 0.2s;
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
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .alert {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: none;
            font-weight: 500;
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
        }
        
        .debug-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 12px;
            border: 1px solid #dee2e6;
            color: #495057;
        }
        
        .debug-info h6 {
            margin-bottom: 10px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .debug-info pre {
            margin: 0;
            font-size: 11px;
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #e9ecef;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-big">
                    <img src="assets/logo.png" alt="Ecommer Logo" onerror="this.src='https://via.placeholder.com/120x120?text=Ecommer'">
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
                
                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label class="form-label">Username or Email</label>
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
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" 
                                   name="password" 
                                   id="password" 
                                   class="form-control" 
                                   placeholder="Enter your password" 
                                   required>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        
                        <div>
                            <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" style="color: var(--primary-color); text-decoration: none; font-size: 14px;">
                                <i class="fas fa-key me-1"></i> Forgot Password?
                            </a>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <span id="btnText">Sign In</span>
                        <span id="loading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                            Signing In...
                        </span>
                    </button>
                    
                    <div class="register-link">
                        Don't have an account? <a href="register.php">Create Account</a>
                    </div>
                </form>
                
                <!-- Debug Information - Remove in production -->
                <div class="debug-info">
                    <h6><i class="fas fa-bug me-2"></i>Debug Information</h6>
                    <p class="mb-2"><strong>PHP Version:</strong> <?= phpversion() ?></p>
                    <p class="mb-2"><strong>Database Connection:</strong> <?= isset($pdo) ? 'Connected' : 'Not Connected' ?></p>
                    <p class="mb-2"><strong>Users in Database:</strong> <?= count($debug_users) ?></p>
                    <?php if (!empty($debug_users)): ?>
                        <p class="mb-2"><strong>Sample Users:</strong></p>
                        <pre><?php foreach($debug_users as $u): ?>ID: <?= $u['id'] ?>, Username: <?= $u['username'] ?>, Email: <?= $u['email'] ?>, Role: <?= $u['role'] ?>, Active: <?= $u['is_active'] ? 'Yes' : 'No' ?>

<?php endforeach; ?></pre>
                    <?php endif; ?>
                    <p class="mb-0 text-muted mt-2"><small>Check error_log for detailed error messages</small></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p class="text-muted mb-4">
                            Enter your registered email address. We'll send you instructions to reset your password.
                        </p>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" 
                                       name="reset_email" 
                                       class="form-control" 
                                       placeholder="Enter your email address" 
                                       required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Reset Link</button>
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
    </script>
</body>
</html>