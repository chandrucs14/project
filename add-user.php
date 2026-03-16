<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Check if user is admin
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?error=unauthorized");
    exit();
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'sales';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    if (empty($full_name)) {
        $errors[] = "Full name is required.";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check if username already exists
    if (empty($errors)) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            if ($checkStmt->fetch()) {
                $errors[] = "Username already exists. Please choose a different username.";
            }
        } catch (PDOException $e) {
            error_log("Error checking username: " . $e->getMessage());
            $errors[] = "Database error occurred. Please try again.";
        }
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                $errors[] = "Email already exists. Please use a different email address.";
            }
        } catch (PDOException $e) {
            error_log("Error checking email: " . $e->getMessage());
            $errors[] = "Database error occurred. Please try again.";
        }
    }
    
    // If no errors, insert user
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username, email, password_hash, full_name, phone, role, is_active, 
                    created_by, created_at
                ) VALUES (
                    :username, :email, :password_hash, :full_name, :phone, :role, :is_active,
                    :created_by, NOW()
                )
            ");
            
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $password_hash,
                ':full_name' => $full_name,
                ':phone' => $phone ?: null,
                ':role' => $role,
                ':is_active' => $is_active,
                ':created_by' => $_SESSION['user_id']
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by, created_at)
                VALUES (?, 3, ?, ?, ?, NOW())
            ");
            
            $logStmt->execute([
                $_SESSION['user_id'],
                "New user created: $username",
                json_encode([
                    'user_id' => $user_id,
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $full_name,
                    'role' => $role
                ]),
                $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "User created successfully!";
            header("Location: users.php");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error creating user: " . $e->getMessage());
            $errors[] = "Database error occurred while creating user. Please try again.";
        }
    }
    
    // Combine errors for display
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                            <h4 class="mb-0 font-size-18">Add New User</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                                    <li class="breadcrumb-item active">Add User</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Message -->
                <?php if (!empty($error)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i>
                            <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add User Form -->
                <div class="row">
                    <div class="col-xl-8 col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">User Information</h4>
                                
                                <form method="POST" action="add-user.php" id="addUserForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="username" class="form-label">
                                                    Username <span class="text-danger">*</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                                    <input type="text" 
                                                           class="form-control" 
                                                           id="username" 
                                                           name="username" 
                                                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                                           placeholder="Enter username"
                                                           maxlength="50"
                                                           required>
                                                </div>
                                                <small class="text-muted">
                                                    Only letters, numbers, and underscores. Min 3 characters.
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="email" class="form-label">
                                                    Email Address <span class="text-danger">*</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                                    <input type="email" 
                                                           class="form-control" 
                                                           id="email" 
                                                           name="email" 
                                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                                           placeholder="Enter email address"
                                                           maxlength="100"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="full_name" class="form-label">
                                                    Full Name <span class="text-danger">*</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                                    <input type="text" 
                                                           class="form-control" 
                                                           id="full_name" 
                                                           name="full_name" 
                                                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                                                           placeholder="Enter full name"
                                                           maxlength="100"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="phone" class="form-label">Phone Number</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                                    <input type="tel" 
                                                           class="form-control" 
                                                           id="phone" 
                                                           name="phone" 
                                                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                                           placeholder="Enter phone number"
                                                           maxlength="20">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="password" class="form-label">
                                                    Password <span class="text-danger">*</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                                    <input type="password" 
                                                           class="form-control" 
                                                           id="password" 
                                                           name="password" 
                                                           placeholder="Enter password"
                                                           minlength="6"
                                                           required>
                                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                                <small class="text-muted">Minimum 6 characters</small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="confirm_password" class="form-label">
                                                    Confirm Password <span class="text-danger">*</span>
                                                </label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                                    <input type="password" 
                                                           class="form-control" 
                                                           id="confirm_password" 
                                                           name="confirm_password" 
                                                           placeholder="Confirm password"
                                                           minlength="6"
                                                           required>
                                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="role" class="form-label">
                                                    User Role <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-control select2" id="role" name="role" required>
                                                    <option value="">Select Role</option>
                                                    <option value="admin" <?= (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : '' ?>>Admin</option>
                                                    <option value="manager" <?= (isset($_POST['role']) && $_POST['role'] == 'manager') ? 'selected' : '' ?>>Manager</option>
                                                    <option value="sales" <?= (isset($_POST['role']) && $_POST['role'] == 'sales') ? 'selected' : '' ?>>Sales</option>
                                                    <option value="auditor" <?= (isset($_POST['role']) && $_POST['role'] == 'auditor') ? 'selected' : '' ?>>Auditor</option>
                                                </select>
                                                <small class="text-muted">
                                                    <strong>Admin:</strong> Full access<br>
                                                    <strong>Manager:</strong> Can manage most modules<br>
                                                    <strong>Sales:</strong> Limited to sales and customers<br>
                                                    <strong>Auditor:</strong> View-only access
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label d-block">Account Status</label>
                                                <div class="form-check form-switch form-switch-md mt-2">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           id="is_active" 
                                                           name="is_active" 
                                                           <?= (!isset($_POST['is_active']) || $_POST['is_active'] == 'on') ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_active">Active</label>
                                                </div>
                                                <small class="text-muted">
                                                    Inactive users cannot log in to the system
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                                <i class="bi bi-person-plus"></i> Create User
                                            </button>
                                            <a href="users.php" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Cancel
                                            </a>
                                            <button type="reset" class="btn btn-light">
                                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-lg-12">
                        <!-- Information Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Role Information</h4>
                                
                                <div class="alert alert-info" role="alert">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Choose the appropriate role based on user responsibilities.
                                </div>
                                
                                <div class="mt-3">
                                    <h6 class="font-size-14"><i class="bi bi-shield-shaded text-danger me-2"></i>Admin</h6>
                                    <p class="text-muted small ps-4">
                                        Full system access. Can manage users, settings, and all modules.
                                    </p>
                                    
                                    <h6 class="font-size-14 mt-3"><i class="bi bi-briefcase text-primary me-2"></i>Manager</h6>
                                    <p class="text-muted small ps-4">
                                        Can manage customers, suppliers, sales, purchases, and reports.
                                    </p>
                                    
                                    <h6 class="font-size-14 mt-3"><i class="bi bi-cart text-success me-2"></i>Sales</h6>
                                    <p class="text-muted small ps-4">
                                        Limited to sales, invoices, and customer management.
                                    </p>
                                    
                                    <h6 class="font-size-14 mt-3"><i class="bi bi-eye text-warning me-2"></i>Auditor</h6>
                                    <p class="text-muted small ps-4">
                                        View-only access to all modules for auditing purposes.
                                    </p>
                                </div>
                                
                                <hr>
                                
                                <div class="mt-3">
                                    <h6 class="font-size-14">Password Requirements:</h6>
                                    <ul class="text-muted small ps-3 mb-0">
                                        <li>Minimum 6 characters</li>
                                        <li>Mix of letters and numbers recommended</li>
                                        <li>Never share passwords</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Tips Card -->
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Quick Tips</h4>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="bi bi-lightbulb text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Use strong passwords with a mix of characters.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="bi bi-lightbulb text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Assign minimum required permissions for security.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="bi bi-lightbulb text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Deactivate accounts of users who leave the organization.</p>
                                    </div>
                                </div>
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
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Select2 for role dropdown
    $('#role').select2({
        width: '100%',
        placeholder: 'Select Role',
        minimumResultsForSearch: -1
    });

    // Toggle password visibility
    $('#togglePassword').click(function() {
        const password = $('#password');
        const type = password.attr('type') === 'password' ? 'text' : 'password';
        password.attr('type', type);
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    $('#toggleConfirmPassword').click(function() {
        const password = $('#confirm_password');
        const type = password.attr('type') === 'password' ? 'text' : 'password';
        password.attr('type', type);
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    // Form validation
    $('#addUserForm').submit(function(e) {
        const username = $('#username').val().trim();
        const email = $('#email').val().trim();
        const full_name = $('#full_name').val().trim();
        const password = $('#password').val();
        const confirm = $('#confirm_password').val();
        const role = $('#role').val();
        
        let errors = [];
        
        // Username validation
        if (username.length < 3) {
            errors.push('Username must be at least 3 characters long');
        }
        if (!/^[a-zA-Z0-9_]+$/.test(username)) {
            errors.push('Username can only contain letters, numbers, and underscores');
        }
        
        // Email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            errors.push('Please enter a valid email address');
        }
        
        // Password validation
        if (password.length < 6) {
            errors.push('Password must be at least 6 characters long');
        }
        
        if (password !== confirm) {
            errors.push('Passwords do not match');
        }
        
        // Role validation
        if (!role) {
            errors.push('Please select a user role');
        }
        
        if (errors.length > 0) {
            e.preventDefault();
            
            let errorHtml = '<ul class="mb-0">';
            errors.forEach(error => {
                errorHtml += `<li>${error}</li>`;
            });
            errorHtml += '</ul>';
            
            Swal.fire({
                title: 'Validation Error',
                html: errorHtml,
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            
            return false;
        }
        
        return true;
    });

    // Reset button confirmation
    $('button[type="reset"]').click(function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Reset Form?',
            text: 'All entered data will be cleared. Continue?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#556ee6',
            cancelButtonColor: '#f46a6a',
            confirmButtonText: 'Yes, reset',
            cancelButtonText: 'No, keep'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#addUserForm')[0].reset();
                $('#role').val('').trigger('change');
                
                Swal.fire({
                    title: 'Reset Complete',
                    text: 'Form has been cleared',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            setTimeout(function() {
                bsAlert.close();
            }, 5000);
        });
    }, 100);
</script>

<style>
/* Form styling */
.form-label {
    font-weight: 500;
    color: #495057;
}

.input-group-text {
    background-color: #f8f9fa;
    border-right: none;
}

.form-control:focus {
    border-color: #556ee6;
    box-shadow: none;
}

.form-control:focus + .input-group-text {
    border-color: #556ee6;
}

/* Select2 customization */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

/* Switch styling */
.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
    margin-top: 0.15em;
    cursor: pointer;
}

.form-switch .form-check-input:checked {
    background-color: #34c38f;
    border-color: #34c38f;
}

/* Card styling */
.card.bg-soft-primary {
    background-color: rgba(85, 110, 230, 0.1) !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .col-xl-4 {
        margin-top: 20px;
    }
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

.swal2-cancel {
    background-color: #f46a6a !important;
}

/* Role badges in info card */
.bi-shield-shaded { color: #dc3545; }
.bi-briefcase { color: #0d6efd; }
.bi-cart { color: #198754; }
.bi-eye { color: #ffc107; }

/* Input group hover effect */
.input-group:hover .input-group-text {
    background-color: #e9ecef;
}

/* Loading state for submit button */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.65;
}

.btn-loading:after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

</body>
</html>