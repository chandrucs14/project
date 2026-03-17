<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at_desc';

// Build query with filters
$query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id) as invoice_count,
           (SELECT COUNT(*) FROM orders WHERE customer_id = c.id) as order_count,
           (SELECT SUM(outstanding_amount) FROM invoices WHERE customer_id = c.id AND status IN ('sent', 'partially_paid', 'overdue')) as total_outstanding
    FROM customers c
    WHERE 1=1
";

$count_query = "SELECT COUNT(*) FROM customers WHERE 1=1";
$params = [];

// Apply search filter
if (!empty($search)) {
    $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.email LIKE :search OR c.phone LIKE :search)";
    $count_query .= " AND (name LIKE :search OR customer_code LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Apply status filter
if ($status === 'active') {
    $query .= " AND c.is_active = 1";
    $count_query .= " AND is_active = 1";
} elseif ($status === 'inactive') {
    $query .= " AND c.is_active = 0";
    $count_query .= " AND is_active = 0";
} elseif ($status === 'has_outstanding') {
    $query .= " AND c.outstanding_balance > 0";
    $count_query .= " AND outstanding_balance > 0";
} elseif ($status === 'no_outstanding') {
    $query .= " AND c.outstanding_balance = 0";
    $count_query .= " AND outstanding_balance = 0";
}

// Apply sorting
switch ($sort_by) {
    case 'name_asc':
        $query .= " ORDER BY c.name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY c.name DESC";
        break;
    case 'outstanding_desc':
        $query .= " ORDER BY c.outstanding_balance DESC";
        break;
    case 'outstanding_asc':
        $query .= " ORDER BY c.outstanding_balance ASC";
        break;
    case 'created_at_asc':
        $query .= " ORDER BY c.created_at ASC";
        break;
    case 'created_at_desc':
    default:
        $query .= " ORDER BY c.created_at DESC";
        break;
}

// Get total records for pagination
$countStmt = $pdo->prepare($count_query);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get customers for current page
$query .= " LIMIT :offset, :limit";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll();

// Get statistics
try {
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_customers,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_customers,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_customers,
            COALESCE(SUM(outstanding_balance), 0) as total_outstanding,
            COUNT(CASE WHEN outstanding_balance > credit_limit AND credit_limit > 0 THEN 1 END) as credit_limit_exceeded,
            AVG(outstanding_balance) as avg_outstanding
        FROM customers
    ");
    $stats = $statsStmt->fetch();
} catch (Exception $e) {
    $stats = [
        'total_customers' => 0,
        'active_customers' => 0,
        'inactive_customers' => 0,
        'total_outstanding' => 0,
        'credit_limit_exceeded' => 0,
        'avg_outstanding' => 0
    ];
    error_log("Error fetching stats: " . $e->getMessage());
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $customer_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if customer has any transactions
        $checkStmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM invoices WHERE customer_id = ?) as invoices,
                (SELECT COUNT(*) FROM orders WHERE customer_id = ?) as orders,
                (SELECT COUNT(*) FROM customer_outstanding WHERE customer_id = ?) as outstanding
        ");
        $checkStmt->execute([$customer_id, $customer_id, $customer_id]);
        $checks = $checkStmt->fetch();
        
        if ($checks['invoices'] > 0 || $checks['orders'] > 0 || $checks['outstanding'] > 0) {
            throw new Exception("Cannot delete customer because they have associated transactions.");
        }
        
        // Get customer details for logging
        $getStmt = $pdo->prepare("SELECT name, customer_code FROM customers WHERE id = ?");
        $getStmt->execute([$customer_id]);
        $customer = $getStmt->fetch();
        
        // Delete customer
        $deleteStmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
        $deleteStmt->execute([$customer_id]);
        
        if ($deleteStmt->rowCount() > 0) {
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'customer_id' => $customer_id,
                'customer_name' => $customer['name'],
                'customer_code' => $customer['customer_code']
            ]);
            
            $logStmt->execute([
                $_SESSION['user_id'],
                "Customer deleted: " . $customer['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Customer deleted successfully.";
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
        error_log("Delete error: " . $e->getMessage());
    }
    
    // Redirect to refresh the page
    header("Location: customers.php?" . http_build_query([
        'search' => $search,
        'status' => $status,
        'sort_by' => $sort_by,
        'page' => $page,
        'per_page' => $records_per_page
    ]));
    exit();
}

// Check for session messages
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
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
    <style>
        .avatar-sm {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }
        .status-badge {
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 30px;
        }
        .table td {
            vertical-align: middle;
        }
        .btn-group .btn {
            margin: 0 2px;
        }
        .summary-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
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
        .credit-warning {
            color: #f46a6a;
            font-size: 11px;
            margin-top: 2px;
        }
        .amount-highlight {
            font-weight: 600;
            color: #556ee6;
        }
    </style>
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
                            <h4 class="mb-0 font-size-18">Customers</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Customers</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="add-customer.php" class="btn btn-primary">
                                        <i class="bi bi-person-plus"></i> Add New Customer
                                    </a>
                                    <a href="customer-outstanding.php" class="btn btn-info">
                                        <i class="bi bi-cash-stack"></i> Outstanding Report
                                    </a>
                                    <button type="button" class="btn btn-success" id="exportBtn">
                                        <i class="bi bi-download"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm bg-soft-primary text-primary rounded-circle">
                                            <i class="bi bi-people font-size-22"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Customers</p>
                                        <h4><?= number_format($stats['total_customers'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('active')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm bg-soft-success text-success rounded-circle">
                                            <i class="bi bi-check-circle font-size-22"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Active Customers</p>
                                        <h4><?= number_format($stats['active_customers'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('has_outstanding')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm bg-soft-warning text-warning rounded-circle">
                                            <i class="bi bi-cash-stack font-size-22"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Outstanding</p>
                                        <h4>₹<?= number_format($stats['total_outstanding'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('credit_limit_exceeded')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm bg-soft-danger text-danger rounded-circle">
                                            <i class="bi bi-exclamation-triangle font-size-22"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Credit Limit Exceeded</p>
                                        <h4><?= number_format($stats['credit_limit_exceeded'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end statistics cards -->

                <!-- Filter and Search Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="GET" action="customers.php" class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="search" 
                                                   placeholder="Search by name, code, email, phone..." 
                                                   value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <option value="">All Customers</option>
                                            <option value="active" <?= $status == 'active' ? 'selected' : '' ?>>Active Only</option>
                                            <option value="inactive" <?= $status == 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                                            <option value="has_outstanding" <?= $status == 'has_outstanding' ? 'selected' : '' ?>>Has Outstanding</option>
                                            <option value="no_outstanding" <?= $status == 'no_outstanding' ? 'selected' : '' ?>>No Outstanding</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Sort By</label>
                                        <select class="form-select" name="sort_by">
                                            <option value="created_at_desc" <?= $sort_by == 'created_at_desc' ? 'selected' : '' ?>>Newest First</option>
                                            <option value="created_at_asc" <?= $sort_by == 'created_at_asc' ? 'selected' : '' ?>>Oldest First</option>
                                            <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                                            <option value="name_desc" <?= $sort_by == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                                            <option value="outstanding_desc" <?= $sort_by == 'outstanding_desc' ? 'selected' : '' ?>>Highest Outstanding</option>
                                            <option value="outstanding_asc" <?= $sort_by == 'outstanding_asc' ? 'selected' : '' ?>>Lowest Outstanding</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-filter"></i> Apply
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <!-- Per Page and Reset -->
                                <form method="GET" action="customers.php" class="row mt-3">
                                    <div class="col-md-4">
                                        <select name="per_page" class="form-select" onchange="this.form.submit()">
                                            <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                            <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                                            <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                            <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="customers.php" class="btn btn-secondary w-100">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
                                        </a>
                                    </div>
                                    <?php 
                                    // Preserve other filter parameters
                                    foreach (['search', 'status', 'sort_by'] as $param):
                                        if (!empty($_GET[$param])):
                                    ?>
                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param]) ?>">
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter section -->

                <!-- Customers Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Customer List</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <span class="text-muted">
                                                Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover table-centered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Customer</th>
                                                <th>Contact</th>
                                                <th>Location</th>
                                                <th>Outstanding</th>
                                                <th>Credit Limit</th>
                                                <th>Transactions</th>
                                                <th>Status</th>
                                                <th>Joined</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($customers)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="bi bi-emoji-frown" style="font-size: 48px;"></i>
                                                    <p class="mt-2">No customers found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($customers as $customer): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-sm bg-soft-info text-info rounded-circle me-2">
                                                                <?= strtoupper(substr($customer['name'], 0, 2)) ?>
                                                            </div>
                                                            <div>
                                                                <h6 class="mb-0"><?= htmlspecialchars($customer['name']) ?></h6>
                                                                <small class="text-muted">Code: <?= htmlspecialchars($customer['customer_code']) ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div><i class="bi bi-envelope text-muted me-1"></i> <?= htmlspecialchars($customer['email'] ?? 'N/A') ?></div>
                                                        <div><i class="bi bi-telephone text-muted me-1"></i> <?= htmlspecialchars($customer['phone'] ?? 'N/A') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $location = [];
                                                        if (!empty($customer['city'])) $location[] = $customer['city'];
                                                        if (!empty($customer['state'])) $location[] = $customer['state'];
                                                        echo !empty($location) ? htmlspecialchars(implode(', ', $location)) : 'N/A';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="amount-highlight">
                                                            ₹<?= number_format($customer['outstanding_balance'] ?? 0, 2) ?>
                                                        </span>
                                                        <?php if (($customer['outstanding_balance'] ?? 0) > ($customer['credit_limit'] ?? 0) && $customer['credit_limit'] > 0): ?>
                                                            <div class="credit-warning">
                                                                <i class="bi bi-exclamation-triangle-fill"></i> Exceeds limit
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($customer['credit_limit']): ?>
                                                            ₹<?= number_format($customer['credit_limit'], 2) ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-info text-info">No Limit</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div><span class="badge bg-soft-primary text-primary">I: <?= $customer['invoice_count'] ?? 0 ?></span></div>
                                                        <div><span class="badge bg-soft-warning text-warning">O: <?= $customer['order_count'] ?? 0 ?></span></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($customer['is_active']): ?>
                                                            <span class="badge bg-soft-success text-success status-badge">
                                                                <i class="bi bi-check-circle"></i> Active
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-danger text-danger status-badge">
                                                                <i class="bi bi-x-circle"></i> Inactive
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= date('d-m-Y', strtotime($customer['created_at'])) ?></td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <a href="view-customer.php?id=<?= $customer['id'] ?>" 
                                                               class="btn btn-sm btn-soft-primary" 
                                                               title="View Details"
                                                               data-bs-toggle="tooltip">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <a href="edit-customer.php?id=<?= $customer['id'] ?>" 
                                                               class="btn btn-sm btn-soft-info" 
                                                               title="Edit"
                                                               data-bs-toggle="tooltip">
                                                                <i class="bi bi-pencil"></i>
                                                            </a>
                                                            <?php if (($customer['invoice_count'] ?? 0) == 0 && ($customer['order_count'] ?? 0) == 0): ?>
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-soft-danger" 
                                                                    title="Delete"
                                                                    data-bs-toggle="tooltip"
                                                                    onclick="confirmDelete(<?= $customer['id'] ?>, '<?= htmlspecialchars(addslashes($customer['name'])) ?>')">
                                                                <i class="bi bi-trash"></i>
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
                                            Page <?= $page ?> of <?= $total_pages ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
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
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end table -->

                <!-- Recent Activity (Optional) -->
                <?php
                try {
                    $recentStmt = $pdo->prepare("
                        SELECT al.*, u.username 
                        FROM activity_logs al 
                        LEFT JOIN users u ON al.user_id = u.id 
                        WHERE al.description LIKE '%customer%' 
                        ORDER BY al.created_at DESC 
                        LIMIT 5
                    ");
                    $recentStmt->execute();
                    $recentActivities = $recentStmt->fetchAll();
                } catch (Exception $e) {
                    $recentActivities = [];
                }
                ?>

                <?php if (!empty($recentActivities)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Customer Activity</h4>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>User</th>
                                                <th>Activity</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentActivities as $activity): ?>
                                            <tr>
                                                <td><?= date('d M Y h:i A', strtotime($activity['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($activity['username'] ?? 'System') ?></td>
                                                <td><?= htmlspecialchars($activity['description']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
            placeholder: 'Select option'
        });
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Filter by status function
    function filterByStatus(status) {
        const url = new URL(window.location.href);
        if (status) {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    // Confirm delete function
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Delete Customer?',
            html: `Are you sure you want to delete <strong>${name}</strong>?`,
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                // Get current URL parameters
                const urlParams = new URLSearchParams(window.location.search);
                const search = urlParams.get('search') || '';
                const status = urlParams.get('status') || '';
                const sort_by = urlParams.get('sort_by') || 'created_at_desc';
                const page = urlParams.get('page') || '1';
                const per_page = urlParams.get('per_page') || '10';
                
                // Redirect with all parameters
                window.location.href = `customers.php?action=delete&id=${id}&search=${encodeURIComponent(search)}&status=${status}&sort_by=${sort_by}&page=${page}&per_page=${per_page}`;
                
                return new Promise(() => {});
            },
            allowOutsideClick: () => !Swal.isLoading()
        });
    }

    // Export functionality
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        exportToCSV();
    });

    function exportToCSV() {
        const data = <?= json_encode($customers) ?>;
        if (data.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Data',
                text: 'No customers to export',
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        // Create CSV content
        let csv = 'Customer Code,Name,Email,Phone,Address,City,State,Pincode,GST Number,Outstanding Balance,Credit Limit,Status,Created At\n';
        
        data.forEach(customer => {
            csv += `"${customer.customer_code}","${customer.name}","${customer.email || ''}","${customer.phone || ''}","${customer.address || ''}","${customer.city || ''}","${customer.state || ''}","${customer.pincode || ''}","${customer.gst_number || ''}",${customer.outstanding_balance},${customer.credit_limit || 0},"${customer.is_active ? 'Active' : 'Inactive'}","${customer.created_at}"\n`;
        });
        
        // Download CSV
        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `customers_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
        
        Swal.fire({
            icon: 'success',
            title: 'Exported',
            text: 'Customers exported successfully',
            timer: 1500,
            showConfirmButton: false
        });
    }

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
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'customers.php?' . http_build_query($params);
}
?>

<style>
/* Avatar styling */
.avatar-sm {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
}

/* Button hover effects */
.btn-soft-primary:hover,
.btn-soft-info:hover,
.btn-soft-danger:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
}

/* Table row hover */
.table-hover tbody tr:hover {
    background-color: rgba(85, 110, 230, 0.02);
}

/* Badge styling */
.badge {
    padding: 6px 10px;
    font-size: 11px;
}

/* Summary card styling */
.summary-card {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,.05);
}

.summary-card .avatar-sm {
    width: 50px;
    height: 50px;
    font-size: 24px;
}

/* Pagination styling */
.pagination .page-link {
    color: #556ee6;
    border: none;
    margin: 0 3px;
    border-radius: 5px;
}

.pagination .page-item.active .page-link {
    background-color: #556ee6;
    color: white;
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

.swal2-html-container {
    font-size: 0.95rem;
}

.swal2-confirm {
    background-color: #556ee6 !important;
}

.swal2-cancel {
    background-color: #f46a6a !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .btn-group .btn {
        border-radius: 4px !important;
        margin: 0;
    }
    
    .table td {
        min-width: 120px;
    }
}
</style>

</body>
</html>