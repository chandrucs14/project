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

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id) {
    header("Location: users.php");
    exit();
}

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: users.php");
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'sales';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($email)) {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (empty($full_name)) {
        $error = "Full name is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if email already exists for another user
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $user_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("Email already exists for another user.");
            }
            
            // Update user
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    email = ?, 
                    full_name = ?, 
                    phone = ?, 
                    role = ?, 
                    is_active = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $email,
                $full_name,
                $phone ?: null,
                $role,
                $is_active,
                $_SESSION['user_id'],
                $user_id
            ]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'user_id' => $user_id,
                    'username' => $user['username'],
                    'changes' => [
                        'email' => $email,
                        'role' => $role,
                        'is_active' => $is_active
                    ]
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "User updated: " . $user['username'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "User updated successfully.";
                header("Location: users.php");
                exit();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
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
                            <h4 class="mb-0 font-size-18">Edit User</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                                    <li class="breadcrumb-item active">Edit User</li>
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
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">User Information</h4>

                                <form method="POST" action="" id="userForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Username</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                                    <input type="text" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($user['username']) ?>"
                                                           readonly>
                                                </div>
                                                <small class="text-muted">Username cannot be changed</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                                    <input type="email" 
                                                           name="email" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($user['email']) ?>"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                                    <input type="text" 
                                                           name="full_name" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($user['full_name']) ?>"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                                    <input type="tel" 
                                                           name="phone" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">User Role <span class="text-danger">*</span></label>
                                                <select name="role" class="form-control select2" required>
                                                    <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                                    <option value="manager" <?= $user['role'] == 'manager' ? 'selected' : '' ?>>Manager</option>
                                                    <option value="sales" <?= $user['role'] == 'sales' ? 'selected' : '' ?>>Sales</option>
                                                    <option value="auditor" <?= $user['role'] == 'auditor' ? 'selected' : '' ?>>Auditor</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Account Status</label>
                                                <div class="form-check form-switch form-switch-md mt-2">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           name="is_active" 
                                                           id="is_active"
                                                           <?= $user['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_active">Active</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="row">
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                <i class="bi bi-info-circle me-2"></i>
                                                To change password, use the "Reset Password" button.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="text-end">
                                                <a href="users.php" class="btn btn-secondary me-2">
                                                    <i class="bi bi-arrow-left"></i> Cancel
                                                </a>
                                                <a href="reset-password.php?id=<?= $user_id ?>" class="btn btn-warning me-2">
                                                    <i class="bi bi-key"></i> Reset Password
                                                </a>
                                                <button type="submit" name="update_user" class="btn btn-primary" id="submitBtn">
                                                    <i class="bi bi-check-circle"></i>
                                                    <span id="btnText">Update User</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Updating...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Sidebar - User Info -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">User Information</h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td><strong>User ID:</strong></td>
                                        <td>#<?= $user['id'] ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Username:</strong></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Created:</strong></td>
                                        <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                                    </tr>
                                    <?php if ($user['last_login']): ?>
                                    <tr>
                                        <td><strong>Last Login:</strong></td>
                                        <td><?= date('d M Y h:i A', strtotime($user['last_login'])) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>

                                <hr>

                                <h6 class="mb-3">Role Permissions</h6>
                                <ul class="list-unstyled">
                                    <?php if ($user['role'] == 'admin'): ?>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Full system access</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> User management</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Settings configuration</li>
                                    <?php elseif ($user['role'] == 'manager'): ?>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Manage all modules</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> View reports</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Approve transactions</li>
                                    <?php elseif ($user['role'] == 'sales'): ?>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Sales & invoices</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Customer management</li>
                                    <?php elseif ($user['role'] == 'auditor'): ?>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> View-only access</li>
                                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Report generation</li>
                                    <?php endif; ?>
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
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            width: '100%',
            minimumResultsForSearch: -1
        });
    });

    // Form submission loading state
    document.getElementById('userForm')?.addEventListener('submit', function(e) {
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
    const form = document.getElementById('userForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox' && input.type !== 'hidden' && input.type !== 'submit') {
                input.addEventListener('change', () => { formDirty = true; });
                input.addEventListener('input', () => { formDirty = true; });
            }
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
.avatar-sm {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
}

/* Form switch styling */
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
</style>

</body>
</html>