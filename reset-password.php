<?php
date_default_timezone_set('Asia/Kolkata');


// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Check if user is admin (for resetting other users' passwords)
$is_admin = ($_SESSION['user_role'] === 'admin');

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no ID specified, reset current user's password
if (!$user_id) {
    $user_id = $_SESSION['user_id'];
}

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: users.php");
        exit();
    }
    
    // Check permission: only admin can reset others' passwords
    if ($user_id != $_SESSION['user_id'] && !$is_admin) {
        $_SESSION['error_message'] = "You don't have permission to reset this user's password.";
        header("Location: index.php");
        exit();
    }
    
} catch (Exception $e) {
    error_log("Error fetching user: " . $e->getMessage());
    $_SESSION['error_message'] = "Error fetching user details.";
    header("Location: users.php");
    exit();
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($new_password)) {
        $error = "New password is required.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Hash the new password
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$password_hash, $_SESSION['user_id'], $user_id]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'user_id' => $user_id,
                    'username' => $user['username'],
                    'reset_by' => $_SESSION['user_id']
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "Password reset for user: " . $user['username'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                // If resetting own password, also update session? (optional)
                // You might want to keep the user logged in
                
                $_SESSION['success_message'] = "Password reset successfully.";
                
                // Redirect based on who performed the reset
                if ($user_id == $_SESSION['user_id']) {
                    header("Location: profile.php");
                } else {
                    header("Location: users.php");
                }
                exit();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error resetting password: " . $e->getMessage();
        }
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>

<body data-sidebar="dark">

<!-- Loader -->
<?php include('includes/pre-loader.php'); ?>

<!-- Begin page -->
<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>    

    <!-- ========== Left Sidebar Start ========== -->
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <!--- Sidemenu -->
            <?php include('includes/sidebar.php'); ?>
            <!-- Sidebar -->
        </div>
    </div>
    <!-- Left Sidebar End -->

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Start page title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0 font-size-18">Reset Password</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <?php if ($user_id != $_SESSION['user_id']): ?>
                                    <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                                    <?php endif; ?>
                                    <li class="breadcrumb-item active">Reset Password</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Alerts -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8 mx-auto">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Reset Password for <?= htmlspecialchars($user['full_name']) ?></h4>

                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Username:</strong> <?= htmlspecialchars($user['username']) ?><br>
                                    <strong>Email:</strong> <?= htmlspecialchars($user['email']) ?>
                                </div>

                                <form method="POST" action="" id="passwordForm">
                                    <div class="mb-4">
                                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                            <input type="password" 
                                                   name="new_password" 
                                                   id="new_password" 
                                                   class="form-control" 
                                                   placeholder="Enter new password"
                                                   minlength="6"
                                                   required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                            <input type="password" 
                                                   name="confirm_password" 
                                                   id="confirm_password" 
                                                   class="form-control" 
                                                   placeholder="Confirm new password"
                                                   minlength="6"
                                                   required>
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <div class="password-strength" id="passwordStrength"></div>
                                    </div>

                                    <hr>

                                    <div class="text-end">
                                        <?php if ($user_id != $_SESSION['user_id']): ?>
                                            <a href="users.php" class="btn btn-secondary me-2">
                                                <i class="bi bi-arrow-left"></i> Back to Users
                                            </a>
                                        <?php else: ?>
                                            <a href="profile.php" class="btn btn-secondary me-2">
                                                <i class="bi bi-arrow-left"></i> Back to Profile
                                            </a>
                                        <?php endif; ?>
                                        <button type="submit" name="reset_password" class="btn btn-warning" id="submitBtn">
                                            <i class="bi bi-key"></i>
                                            <span id="btnText">Reset Password</span>
                                            <span id="loading" style="display:none;">
                                                <span class="spinner-border spinner-border-sm me-1"></span>
                                                Resetting...
                                            </span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Password Requirements</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2" id="req-length">
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i> At least 6 characters
                                    </li>
                                    <li class="mb-2" id="req-uppercase">
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i> At least one uppercase letter
                                    </li>
                                    <li class="mb-2" id="req-lowercase">
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i> At least one lowercase letter
                                    </li>
                                    <li class="mb-2" id="req-number">
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i> At least one number
                                    </li>
                                    <li class="mb-2" id="req-special">
                                        <i class="bi bi-x-circle-fill text-danger me-2"></i> At least one special character
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Toggle password visibility
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
        
        // Toggle icon
        const button = field.nextElementSibling;
        const icon = button.querySelector('i');
        icon.classList.toggle('bi-eye');
        icon.classList.toggle('bi-eye-slash');
    }

    // Password strength checker
    document.getElementById('new_password')?.addEventListener('input', function() {
        const password = this.value;
        
        // Check requirements
        const length = password.length >= 6;
        const uppercase = /[A-Z]/.test(password);
        const lowercase = /[a-z]/.test(password);
        const number = /[0-9]/.test(password);
        const special = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
        
        // Update requirement indicators
        updateRequirement('req-length', length);
        updateRequirement('req-uppercase', uppercase);
        updateRequirement('req-lowercase', lowercase);
        updateRequirement('req-number', number);
        updateRequirement('req-special', special);
        
        // Calculate strength
        let strength = 0;
        if (length) strength++;
        if (uppercase) strength++;
        if (lowercase) strength++;
        if (number) strength++;
        if (special) strength++;
        
        displayPasswordStrength(strength);
    });

    function updateRequirement(elementId, isValid) {
        const element = document.getElementById(elementId);
        if (!element) return;
        
        const icon = element.querySelector('i');
        if (isValid) {
            icon.className = 'bi bi-check-circle-fill text-success me-2';
        } else {
            icon.className = 'bi bi-x-circle-fill text-danger me-2';
        }
    }

    function displayPasswordStrength(strength) {
        let strengthText = '';
        let strengthClass = '';
        
        switch(strength) {
            case 0:
            case 1:
                strengthText = 'Very Weak';
                strengthClass = 'bg-danger';
                break;
            case 2:
                strengthText = 'Weak';
                strengthClass = 'bg-warning';
                break;
            case 3:
                strengthText = 'Medium';
                strengthClass = 'bg-info';
                break;
            case 4:
                strengthText = 'Strong';
                strengthClass = 'bg-primary';
                break;
            case 5:
                strengthText = 'Very Strong';
                strengthClass = 'bg-success';
                break;
        }
        
        document.getElementById('passwordStrength').innerHTML = `
            <div class="progress" style="height: 8px;">
                <div class="progress-bar ${strengthClass}" role="progressbar" 
                     style="width: ${strength * 20}%" 
                     aria-valuenow="${strength * 20}" 
                     aria-valuemin="0" 
                     aria-valuemax="100"></div>
            </div>
            <small class="text-muted mt-1 d-block">Password Strength: ${strengthText}</small>
        `;
    }

    // Form submission loading state
    document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
        const newPass = document.getElementById('new_password').value;
        const confirmPass = document.getElementById('confirm_password').value;
        
        if (newPass !== confirmPass) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Password Mismatch',
                text: 'New password and confirm password do not match.',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (newPass.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Password',
                text: 'Password must be at least 6 characters long.',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        if (btn) {
            btn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (loading) loading.style.display = 'inline-block';
        }
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 500);
        });
    }, 5000);

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('passwordForm');
    if (form) {
        const formInputs = form.querySelectorAll('input');
        
        formInputs.forEach(input => {
            input.addEventListener('change', () => { formDirty = true; });
            input.addEventListener('input', () => { formDirty = true; });
        });
        
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        form.addEventListener('submit', () => {
            formDirty = false;
        });
    }
</script>

<style>
/* Password strength meter */
.progress {
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    transition: width 0.3s ease;
}

/* Requirement list styling */
#req-length i, #req-uppercase i, #req-lowercase i, #req-number i, #req-special i {
    font-size: 14px;
    transition: all 0.3s;
}

/* Input group styling */
.input-group-text {
    background-color: #f8f9fa;
}

/* Alert animations */
.alert {
    transition: opacity 0.5s ease;
}

/* SweetAlert2 customization */
.swal2-popup {
    font-family: inherit;
}

.swal2-title {
    font-size: 1.2rem;
}

.swal2-confirm {
    background-color: #556ee6 !important;
}

/* Card styling */
.card {
    box-shadow: 0 2px 4px rgba(0,0,0,.05);
    border: none;
    margin-bottom: 20px;
}

.card-title {
    color: #495057;
    font-weight: 600;
}

/* Info alert styling */
.alert-info {
    background-color: rgba(80, 165, 241, 0.1);
    border-color: rgba(80, 165, 241, 0.2);
    color: #50a5f1;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .col-lg-8 {
        padding: 0 15px;
    }
}
</style>

</body>
</html>