<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';



// Check if user is admin
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?error=unauthorized");
    exit();
}

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Handle user deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    
    // Don't allow deleting yourself
    if ($delete_id == $_SESSION['user_id']) {
        $error_message = "You cannot delete your own account.";
    } else {
        try {
            // Check if user exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $checkStmt->execute([$delete_id]);
            
            if ($checkStmt->fetch()) {
                // Delete user
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$delete_id]);
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                    VALUES (?, 5, 'User deleted', ?, ?)
                ");
                $logStmt->execute([
                    $_SESSION['user_id'],
                    json_encode(['deleted_user_id' => $delete_id]),
                    $_SESSION['user_id']
                ]);
                
                $success_message = "User deleted successfully.";
            } else {
                $error_message = "User not found.";
            }
        } catch (PDOException $e) {
            error_log("Error deleting user: " . $e->getMessage());
            $error_message = "An error occurred while deleting the user.";
        }
    }
}

// Handle status toggle (activate/deactivate)
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    $toggle_id = intval($_GET['id']);
    
    // Don't allow toggling your own status
    if ($toggle_id == $_SESSION['user_id']) {
        $error_message = "You cannot change your own status.";
    } else {
        try {
            // Get current status
            $statusStmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $statusStmt->execute([$toggle_id]);
            $current_status = $statusStmt->fetchColumn();
            
            if ($current_status !== false) {
                $new_status = $current_status ? 0 : 1;
                $updateStmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $updateStmt->execute([$new_status, $toggle_id]);
                
                $status_text = $new_status ? 'activated' : 'deactivated';
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                    VALUES (?, 4, 'User status changed', ?, ?)
                ");
                $logStmt->execute([
                    $_SESSION['user_id'],
                    json_encode(['user_id' => $toggle_id, 'new_status' => $new_status]),
                    $_SESSION['user_id']
                ]);
                
                $success_message = "User $status_text successfully.";
            }
        } catch (PDOException $e) {
            error_log("Error toggling user status: " . $e->getMessage());
            $error_message = "An error occurred while changing user status.";
        }
    }
}

// Build query with filters
$query = "SELECT * FROM users WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM users WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (username LIKE :search OR email LIKE :search OR full_name LIKE :search OR phone LIKE :search)";
    $count_query .= " AND (username LIKE :search OR email LIKE :search OR full_name LIKE :search OR phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($role_filter)) {
    $query .= " AND role = :role";
    $count_query .= " AND role = :role";
    $params[':role'] = $role_filter;
}

if ($status_filter !== '') {
    $query .= " AND is_active = :is_active";
    $count_query .= " AND is_active = :is_active";
    $params[':is_active'] = $status_filter;
}

// Get total records for pagination
$countStmt = $pdo->prepare($count_query);
$countStmt->execute($params);
$total_records = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get users for current page
$query .= " ORDER BY created_at DESC LIMIT :offset, :limit";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
try {
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
            SUM(CASE WHEN role = 'sales' THEN 1 ELSE 0 END) as sales_count,
            SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as manager_count,
            SUM(CASE WHEN role = 'auditor' THEN 1 ELSE 0 END) as auditor_count,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
        FROM users
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = [
        'total_users' => 0,
        'admin_count' => 0,
        'sales_count' => 0,
        'manager_count' => 0,
        'auditor_count' => 0,
        'active_count' => 0,
        'inactive_count' => 0
    ];
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
                            <h4 class="mb-0 font-size-18">User Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="masters.php">Masters</a></li>
                                    <li class="breadcrumb-item active">Users</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-account-group font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Users</p>
                                        <h4><?= number_format($stats['total_users'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-check-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Active Users</p>
                                        <h4><?= number_format($stats['active_count'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-alert-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Inactive Users</p>
                                        <h4><?= number_format($stats['inactive_count'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-shield-account font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Admins</p>
                                        <h4><?= number_format($stats['admin_count'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                        <i class="mdi mdi-account-plus"></i> Add New User
                                    </button>
                                  
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Users</h4>
                                <form method="GET" action="users.php" class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" 
                                                   placeholder="Search by name, email, username..." 
                                                   value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="role" class="form-label">Role</label>
                                            <select class="form-control" id="role" name="role">
                                                <option value="">All Roles</option>
                                                <option value="admin" <?= $role_filter == 'admin' ? 'selected' : '' ?>>Admin</option>
                                                <option value="sales" <?= $role_filter == 'sales' ? 'selected' : '' ?>>Sales</option>
                                                <option value="manager" <?= $role_filter == 'manager' ? 'selected' : '' ?>>Manager</option>
                                                <option value="auditor" <?= $role_filter == 'auditor' ? 'selected' : '' ?>>Auditor</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-control" id="status" name="status">
                                                <option value="">All Status</option>
                                                <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Active</option>
                                                <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="users.php" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i> Reset
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">User List</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <form method="GET" action="users.php" class="d-inline-block">
                                                <select name="per_page" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                    <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                                    <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                                                    <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                                    <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                                </select>
                                                <?php foreach (['search', 'role', 'status'] as $param): ?>
                                                    <?php if (!empty($_GET[$param])): ?>
                                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param]) ?>">
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Username</th>
                                                <th>Full Name</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Role</th>
                                                <th>Status</th>
                                                <th>Last Login</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($users)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No users found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($users as $user): ?>
                                                <tr>
                                                    <td><?= $user['id'] ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($user['username']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                                    <td><?= htmlspecialchars($user['phone'] ?? '-') ?></td>
                                                    <td>
                                                        <?php
                                                        $roleClass = '';
                                                        switch($user['role']) {
                                                            case 'admin':
                                                                $roleClass = 'danger';
                                                                break;
                                                            case 'manager':
                                                                $roleClass = 'info';
                                                                break;
                                                            case 'auditor':
                                                                $roleClass = 'warning';
                                                                break;
                                                            default:
                                                                $roleClass = 'success';
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $roleClass ?> text-<?= $roleClass ?>">
                                                            <?= ucfirst($user['role']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($user['is_active']): ?>
                                                            <span class="badge bg-soft-success text-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= $user['last_login'] ? date('d M Y, h:i A', strtotime($user['last_login'])) : 'Never' ?>
                                                    </td>
                                                    <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-soft-primary" 
                                                                    onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-soft-warning" 
                                                                    onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                                <i class="mdi mdi-lock-reset"></i>
                                                            </button>
                                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button type="button" class="btn btn-sm btn-soft-<?= $user['is_active'] ? 'danger' : 'success' ?>" 
                                                                    onclick="toggleStatus(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>', <?= $user['is_active'] ? 1 : 0 ?>)">
                                                                <i class="mdi mdi-<?= $user['is_active'] ? 'account-cancel' : 'account-check' ?>"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-soft-danger" 
                                                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="row mt-4">
                                    <div class="col-sm-6">
                                        <div class="text-muted">
                                            Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>" tabindex="-1">Previous</a>
                                            </li>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($i) ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="add-user.php" id="addUserForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                        <i class="mdi mdi-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                        <i class="mdi mdi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-control" id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin">Admin</option>
                                    <option value="sales">Sales</option>
                                    <option value="manager">Manager</option>
                                    <option value="auditor">Auditor</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="is_active" class="form-label">Status</label>
                                <select class="form-control" id="is_active" name="is_active">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="edit-user.php" id="editUserForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="edit_username" name="username" readonly>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="edit_email" name="email" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="edit_phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-control" id="edit_role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin">Admin</option>
                                    <option value="sales">Sales</option>
                                    <option value="manager">Manager</option>
                                    <option value="auditor">Auditor</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_is_active" class="form-label">Status</label>
                                <select class="form-control" id="edit_is_active" name="is_active">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="mdi mdi-information me-2"></i>
                        To change password, use the "Reset Password" button.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resetPasswordModalLabel">Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="reset-password.php" id="resetPasswordForm">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="modal-body">
                    <p>Reset password for user: <strong id="reset_username"></strong></p>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                <i class="mdi mdi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_new_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_new_password" name="confirm_password" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_new_password')">
                                <i class="mdi mdi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Select2
    $('#role, #status, #edit_role, #edit_is_active').select2({
        width: '100%',
        minimumResultsForSearch: -1
    });

    // Toggle password visibility
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
    }

    // Edit user function
    function editUser(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_username').value = user.username;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_full_name').value = user.full_name;
        document.getElementById('edit_phone').value = user.phone || '';
        document.getElementById('edit_role').value = user.role;
        document.getElementById('edit_is_active').value = user.is_active;
        
        // Trigger Select2 update
        $('#edit_role').trigger('change');
        $('#edit_is_active').trigger('change');
        
        var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }

    // Reset password function
    function resetPassword(userId, username) {
        document.getElementById('reset_user_id').value = userId;
        document.getElementById('reset_username').textContent = username;
        
        var modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
        modal.show();
    }

    // Toggle status function
    function toggleStatus(userId, username, currentStatus) {
        const action = currentStatus ? 'deactivate' : 'activate';
        const title = currentStatus ? 'Deactivate User' : 'Activate User';
        const text = `Are you sure you want to ${action} user "${username}"?`;
        
        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: currentStatus ? '#f46a6a' : '#34c38f',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, ' + action + '!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `users.php?action=toggle_status&id=${userId}`;
            }
        });
    }

    // Delete user function
    function deleteUser(userId, username) {
        if (userId == <?= $_SESSION['user_id'] ?>) {
            Swal.fire({
                icon: 'error',
                title: 'Cannot Delete',
                text: 'You cannot delete your own account!',
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        Swal.fire({
            title: 'Delete User',
            text: `Are you sure you want to delete user "${username}"? This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `users.php?action=delete&id=${userId}`;
            }
        });
    }

    // Form validation for add user
    document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        if (password.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Password',
                text: 'Password must be at least 6 characters long',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (password !== confirm) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Password Mismatch',
                text: 'Passwords do not match',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });

    // Form validation for reset password
    document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
        const password = document.getElementById('new_password').value;
        const confirm = document.getElementById('confirm_new_password').value;
        
        if (password.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Password',
                text: 'Password must be at least 6 characters long',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (password !== confirm) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Password Mismatch',
                text: 'Passwords do not match',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });

    // Export functionality
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        window.location.href = 'export-users.php?' + window.location.search.substring(1);
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

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'users.php?' . http_build_query($params);
}
?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .select2, .no-print {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 9pt;
    }
    .badge {
        border: 1px solid #000;
        color: #000 !important;
        background: transparent !important;
    }
}

.table td {
    vertical-align: middle;
}

/* Button styles */
.btn-soft-primary, .btn-soft-success, .btn-soft-info, .btn-soft-warning, .btn-soft-danger {
    transition: all 0.3s;
}

.btn-soft-primary:hover, .btn-soft-success:hover, .btn-soft-info:hover, 
.btn-soft-warning:hover, .btn-soft-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

/* Status badges */
.badge {
    font-size: 0.8rem;
    padding: 6px 10px;
}

/* Role badges */
.badge.bg-soft-danger {
    color: #f46a6a !important;
}
.badge.bg-soft-success {
    color: #34c38f !important;
}
.badge.bg-soft-info {
    color: #50a5f1 !important;
}
.badge.bg-soft-warning {
    color: #f1b44c !important;
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

/* Modal styles */
.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

.modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table {
        font-size: 8pt;
    }
    .table td, .table th {
        padding: 0.5rem;
    }
    .btn-sm {
        padding: 0.2rem 0.4rem;
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
</style>

</body>
</html>