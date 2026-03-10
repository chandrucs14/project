<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'sales'; // Get selected role, default to sales
    
    // Validation
    if ($username === '' || $email === '' || $full_name === '' || $password === '' || $confirm_password === '') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Please enter a valid 10-digit phone number.";
    } elseif (!in_array($role, ['admin', 'sales'])) {
        $error = "Invalid role selected.";
    } else {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = "Username already exists. Please choose a different username.";
            } else {
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = "Email already registered. Please use a different email or login.";
                } else {
                    // Hash password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user
                    $stmt = $pdo->prepare("
                        INSERT INTO users (
                            username, email, password_hash, full_name, phone, role, 
                            is_active, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    
                    if ($stmt->execute([$username, $email, $password_hash, $full_name, $phone, $role])) {
                        $user_id = $pdo->lastInsertId();
                        
                        // Log the registration activity
                        $activity_stmt = $pdo->prepare("
                            INSERT INTO activity_logs (
                                user_id, activity_type_id, description, activity_data, created_at
                            ) VALUES (?, 3, ?, ?, NOW())
                        ");
                        
                        $activity_data = json_encode([
                            'username' => $username,
                            'email' => $email,
                            'full_name' => $full_name,
                            'phone' => $phone,
                            'role' => $role
                        ]);
                        
                        $activity_stmt->execute([
                            $user_id,
                            'New user registration',
                            $activity_data
                        ]);
                        
                        $success = "Registration successful! You can now login with your credentials.";
                        
                        // Clear form data
                        $username = $email = $full_name = $phone = '';
                    } else {
                        $error = "Registration failed. Please try again.";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = "Registration failed. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecommer - Register</title>
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
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }
        
        .register-container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .register-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .register-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .register-header {
            text-align: center;
            padding: 30px 30px 20px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .register-header .logo-big img {
            width: 80px;
            height: auto;
            margin-bottom: 15px;
            filter: brightness(0) invert(1);
        }
        
        .register-header h2 {
            font-weight: 600;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .register-header p {
            opacity: 0.9;
            font-size: 14px;
            margin: 0;
        }
        
        .register-body {
            padding: 35px;
        }
        
        .form-group {
            margin-bottom: 22px;
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
        
        .input-group, .role-select-group {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid var(--border-color);
            transition: all 0.3s;
        }
        
        .input-group:focus-within, .role-select-group:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .input-group-text {
            background-color: white;
            border: none;
            color: var(--gray-text);
            padding: 0 15px;
        }
        
        .form-control, .form-select {
            border: none;
            padding: 12px 15px;
            font-size: 15px;
            height: auto;
            box-shadow: none;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: none;
        }
        
        .form-select {
            background-color: white;
            cursor: pointer;
        }
        
        .role-option {
            display: flex;
            align-items: center;
            padding: 5px 0;
        }
        
        .role-option i {
            width: 24px;
            color: var(--primary-color);
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .role-badge.admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .role-badge.sales {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .role-description {
            font-size: 12px;
            color: var(--gray-text);
            margin-top: 5px;
            margin-left: 45px;
        }
        
        .password-requirements {
            background: var(--light-bg);
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0 20px;
            font-size: 13px;
            border-left: 3px solid var(--primary-color);
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            color: var(--gray-text);
        }
        
        .requirement-item.valid {
            color: #28a745;
        }
        
        .requirement-item i {
            width: 18px;
            font-size: 14px;
        }
        
        .btn-register {
            width: 100%;
            padding: 14px;
            font-weight: 600;
            font-size: 16px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            color: white;
            margin: 10px 0 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(67, 97, 238, 0.4);
        }
        
        .btn-register:active {
            transform: translateY(0);
        }
        
        .btn-register::after {
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
        
        .btn-register:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% { transform: scale(0, 0); opacity: 0.5; }
            100% { transform: scale(20, 20); opacity: 0; }
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: none;
            font-weight: 500;
        }
        
        .alert-danger {
            background-color: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .login-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .login-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .terms-text {
            text-align: center;
            font-size: 13px;
            color: var(--gray-text);
            margin-top: 20px;
        }
        
        .terms-text a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .terms-text a:hover {
            text-decoration: underline;
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
        
        /* Password strength meter */
        .password-strength {
            height: 5px;
            border-radius: 5px;
            margin-top: 8px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
        }
        
        .strength-weak { background: #dc3545; }
        .strength-medium { background: #ffc107; }
        .strength-strong { background: #28a745; }
        
        .password-strength-text {
            font-size: 12px;
            margin-top: 5px;
            text-align: right;
        }
        
        /* Role info cards */
        .role-info {
            background: var(--light-bg);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
            border-left: 3px solid var(--primary-color);
            display: none;
        }
        
        .role-info.active {
            display: block;
        }
        
        .role-info i {
            color: var(--primary-color);
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo-big">
                    <img src="assets/logo.png" alt="Ecommer Logo">
                </div>
                <h2>Create an Account</h2>
                <p>Join Ecommer to start managing your business</p>
            </div>
            
            <div class="register-body">
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
                    <div class="text-center">
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                        </a>
                    </div>
                <?php else: ?>
                
                <form method="POST" id="registerForm" novalidate>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user"></i> Full Name <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" 
                                   name="full_name" 
                                   class="form-control" 
                                   placeholder="Enter your full name" 
                                   required
                                   autofocus
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user-circle"></i> Username <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-at"></i>
                            </span>
                            <input type="text" 
                                   name="username" 
                                   class="form-control" 
                                   placeholder="Choose a username" 
                                   required
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                        <small class="text-muted">Username must be unique and at least 3 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Email Address <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input type="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="Enter your email" 
                                   required
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-phone"></i> Phone Number
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-phone"></i>
                            </span>
                            <input type="tel" 
                                   name="phone" 
                                   class="form-control" 
                                   placeholder="Enter 10-digit mobile number" 
                                   maxlength="10"
                                   pattern="[0-9]{10}"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                        <small class="text-muted">Optional: For account recovery and notifications</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user-tag"></i> Select Role <span class="text-danger">*</span>
                        </label>
                        <div class="role-select-group">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-shield-alt"></i>
                                </span>
                                <select name="role" id="roleSelect" class="form-select" required>
                                    <option value="" disabled <?= !isset($_POST['role']) ? 'selected' : '' ?>>Choose your role</option>
                                    <option value="admin" <?= (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : '' ?>>Administrator</option>
                                    <option value="sales" <?= (isset($_POST['role']) && $_POST['role'] === 'sales') ? 'selected' : '' ?>>Sales Staff</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Role Information -->
                        <div id="adminInfo" class="role-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Administrator Access:</strong> Full system access including user management, settings, and all business operations.
                            <div style="margin-top: 8px;">
                                <span class="role-badge admin">Admin</span>
                                <span class="role-badge sales">Sales</span>
                            </div>
                        </div>
                        
                        <div id="salesInfo" class="role-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Sales Staff Access:</strong> Can manage sales, create invoices, track customers, and view reports but cannot modify system settings.
                            <div style="margin-top: 8px;">
                                <span class="role-badge sales">Sales Staff</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" 
                                   name="password" 
                                   id="password" 
                                   class="form-control" 
                                   placeholder="Create a strong password" 
                                   required>
                            <button type="button" class="password-toggle" id="togglePassword" style="background:none; border:none; padding:0 15px;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        
                        <div class="password-strength mt-2">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div class="password-strength-text" id="strengthText"></div>
                        
                        <div class="password-requirements">
                            <div class="requirement-item" id="lengthReq">
                                <i class="far fa-circle"></i> At least 8 characters
                            </div>
                            <div class="requirement-item" id="uppercaseReq">
                                <i class="far fa-circle"></i> At least 1 uppercase letter
                            </div>
                            <div class="requirement-item" id="lowercaseReq">
                                <i class="far fa-circle"></i> At least 1 lowercase letter
                            </div>
                            <div class="requirement-item" id="numberReq">
                                <i class="far fa-circle"></i> At least 1 number
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-check-circle"></i> Confirm Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-check"></i>
                            </span>
                            <input type="password" 
                                   name="confirm_password" 
                                   id="confirm_password" 
                                   class="form-control" 
                                   placeholder="Re-enter your password" 
                                   required>
                            <button type="button" class="password-toggle" id="toggleConfirmPassword" style="background:none; border:none; padding:0 15px;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" style="font-size:13px; margin-top:5px;"></div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="termsCheck" required>
                        <label class="form-check-label" for="termsCheck">
                            I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-register" id="registerBtn">
                        <span id="btnText">Create Account</span>
                        <span id="loading" style="display:none;">
                            <span class="spinner"></span> Creating Account...
                        </span>
                    </button>
                    
                    <div class="login-link">
                        Already have an account? <a href="index.php">Sign In</a>
                    </div>
                    
                    <div class="terms-text">
                        <i class="fas fa-shield-alt me-1"></i>
                        Your information is encrypted and secure
                    </div>
                </form>
                <?php endif; ?>
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
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const password = document.getElementById('confirm_password');
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
        
        // Role selection info display
        const roleSelect = document.getElementById('roleSelect');
        const adminInfo = document.getElementById('adminInfo');
        const salesInfo = document.getElementById('salesInfo');
        
        function updateRoleInfo() {
            const selectedRole = roleSelect.value;
            
            // Hide both info panels
            adminInfo.classList.remove('active');
            salesInfo.classList.remove('active');
            
            // Show selected role info
            if (selectedRole === 'admin') {
                adminInfo.classList.add('active');
            } else if (selectedRole === 'sales') {
                salesInfo.classList.add('active');
            }
        }
        
        roleSelect.addEventListener('change', updateRoleInfo);
        
        // Trigger on page load if role is pre-selected
        if (roleSelect.value) {
            updateRoleInfo();
        }
        
        // Password strength checker and requirements
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        
        const lengthReq = document.getElementById('lengthReq');
        const uppercaseReq = document.getElementById('uppercaseReq');
        const lowercaseReq = document.getElementById('lowercaseReq');
        const numberReq = document.getElementById('numberReq');
        const passwordMatch = document.getElementById('passwordMatch');
        
        function checkPasswordStrength() {
            const pwd = password.value;
            
            // Check requirements
            const hasLength = pwd.length >= 8;
            const hasUppercase = /[A-Z]/.test(pwd);
            const hasLowercase = /[a-z]/.test(pwd);
            const hasNumber = /[0-9]/.test(pwd);
            
            // Update requirement icons
            updateRequirement(lengthReq, hasLength);
            updateRequirement(uppercaseReq, hasUppercase);
            updateRequirement(lowercaseReq, hasLowercase);
            updateRequirement(numberReq, hasNumber);
            
            // Calculate strength
            const requirements = [hasLength, hasUppercase, hasLowercase, hasNumber];
            const metCount = requirements.filter(Boolean).length;
            
            let strength = 0;
            let strengthClass = '';
            let strengthLabel = '';
            
            if (pwd.length === 0) {
                strength = 0;
                strengthClass = '';
                strengthLabel = '';
            } else if (metCount <= 1) {
                strength = 25;
                strengthClass = 'strength-weak';
                strengthLabel = 'Weak';
            } else if (metCount === 2) {
                strength = 50;
                strengthClass = 'strength-weak';
                strengthLabel = 'Weak';
            } else if (metCount === 3) {
                strength = 75;
                strengthClass = 'strength-medium';
                strengthLabel = 'Medium';
            } else if (metCount === 4) {
                strength = 100;
                strengthClass = 'strength-strong';
                strengthLabel = 'Strong';
            }
            
            strengthBar.style.width = strength + '%';
            strengthBar.className = 'strength-bar ' + strengthClass;
            strengthText.textContent = strengthLabel ? 'Password strength: ' + strengthLabel : '';
            
            // Check password match
            checkPasswordMatch();
        }
        
        function updateRequirement(element, isValid) {
            const icon = element.querySelector('i');
            if (isValid) {
                element.classList.add('valid');
                icon.classList.remove('far', 'fa-circle');
                icon.classList.add('fas', 'fa-check-circle');
            } else {
                element.classList.remove('valid');
                icon.classList.remove('fas', 'fa-check-circle');
                icon.classList.add('far', 'fa-circle');
            }
        }
        
        function checkPasswordMatch() {
            if (confirmPassword.value.length === 0) {
                passwordMatch.innerHTML = '';
                return;
            }
            
            if (password.value === confirmPassword.value) {
                passwordMatch.innerHTML = '<i class="fas fa-check-circle text-success"></i> Passwords match';
                passwordMatch.className = 'text-success';
            } else {
                passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Passwords do not match';
                passwordMatch.className = 'text-danger';
            }
        }
        
        password.addEventListener('input', checkPasswordStrength);
        confirmPassword.addEventListener('input', checkPasswordMatch);
        
        // Form submission loading state
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('registerBtn');
            const btnText = document.getElementById('btnText');
            const loading = document.getElementById('loading');
            
            // Disable button and show loading
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline';
        });
        
        // Phone number validation
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
        });
        
        // Username validation (alphanumeric and underscore only)
        document.querySelector('input[name="username"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
        });
        
        // Clear form data if success message is shown
        <?php if ($success): ?>
        setTimeout(function() {
            window.location.href = 'index.php';
        }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>