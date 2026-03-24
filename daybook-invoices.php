<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$customer_name = isset($_GET['customer_name']) ? trim($_GET['customer_name']) : '';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT d.*, u.full_name as created_by_name,
          (SELECT COUNT(*) FROM daybook_items WHERE daybook_id = d.id) as item_count
          FROM daybook d 
          LEFT JOIN users u ON d.created_by = u.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM daybook WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (d.invoice_number LIKE ? OR d.driver_name LIKE ? OR d.customer_name LIKE ? OR d.location LIKE ?)";
    $countQuery .= " AND (invoice_number LIKE ? OR driver_name LIKE ? OR customer_name LIKE ? OR location LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($customer_name)) {
    $query .= " AND d.customer_name LIKE ?";
    $countQuery .= " AND customer_name LIKE ?";
    $params[] = "%$customer_name%";
}

if ($payment_status !== 'all') {
    $query .= " AND d.payment_status = ?";
    $countQuery .= " AND payment_status = ?";
    $params[] = $payment_status;
}

if (!empty($date_from)) {
    $query .= " AND d.invoice_date >= ?";
    $countQuery .= " AND invoice_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND d.invoice_date <= ?";
    $countQuery .= " AND invoice_date <= ?";
    $params[] = $date_to;
}

// Get total count
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get paginated results
$query .= " ORDER BY d.invoice_date DESC, d.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$daybooks = $stmt->fetchAll();

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) as partial,
        SUM(grand_total) as total_amount,
        SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE 0 END) as paid_amount,
        SUM(CASE WHEN payment_status IN ('pending', 'partial') THEN grand_total - paid_amount ELSE 0 END) as outstanding_amount
    FROM daybook
");
$stats = $statsStmt->fetch();

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT DISTINCT customer_name FROM daybook ORDER BY customer_name");
    $customers = $customersStmt->fetchAll();
} catch (Exception $e) {
    $customers = [];
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

// Helper function for safe output
function safe_echo($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function for payment status badge
function getPaymentStatusBadge($status) {
    switch ($status) {
        case 'paid':
            return '<span class="badge bg-success">Paid</span>';
        case 'partial':
            return '<span class="badge bg-warning text-dark">Partially Paid</span>';
        default:
            return '<span class="badge bg-danger">Pending</span>';
    }
}

// Helper function for customer type badge
function getCustomerTypeBadge($type) {
    switch ($type) {
        case 'constructor':
            return '<span class="badge bg-info">Constructor/Builder</span>';
        case 'dealer':
            return '<span class="badge bg-primary">Dealer</span>';
        default:
            return '<span class="badge bg-secondary">Regular Customer</span>';
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .stats-card {
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .action-buttons .btn {
            margin: 0 2px;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .invoice-number {
            font-weight: 600;
            color: #667eea;
        }
        
        .amount-positive {
            color: #10b981;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #ef4444;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .filter-section .row > div {
                margin-bottom: 10px;
            }
        }
        
        /* Alert animations */
        .alert {
            animation: slideDown 0.5s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                            <h4 class="mb-0 font-size-18">Daybook Entries</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Daybook</li>
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

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-package-variant" style="font-size: 32px; color: #667eea;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($stats['total'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Total Entries</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-currency-inr" style="font-size: 32px; color: #10b981;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">₹<?= number_format($stats['total_amount'] ?? 0, 2) ?></h5>
                                        <p class="text-muted mb-0">Total Amount</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-check-circle" style="font-size: 32px; color: #10b981;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($stats['paid'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Paid Entries</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-currency-inr" style="font-size: 32px; color: #f59e0b;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">₹<?= number_format($stats['outstanding_amount'] ?? 0, 2) ?></h5>
                                        <p class="text-muted mb-0">Outstanding Amount</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Filter Section -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Filter Daybook Entries</h4>
                        
                        <form method="GET" action="" id="filterForm">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Search</label>
                                        <input type="text" class="form-control" name="search" 
                                               placeholder="Invoice #, Driver, Customer, Location..." 
                                               value="<?= safe_echo($search) ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Customer Name</label>
                                        <select name="customer_name" class="form-control">
                                            <option value="">All Customers</option>
                                            <?php foreach ($customers as $cust): ?>
                                                <option value="<?= safe_echo($cust['customer_name']) ?>" <?= $customer_name == $cust['customer_name'] ? 'selected' : '' ?>>
                                                    <?= safe_echo($cust['customer_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Payment Status</label>
                                        <select name="payment_status" class="form-control">
                                            <option value="all" <?= $payment_status === 'all' ? 'selected' : '' ?>>All</option>
                                            <option value="pending" <?= $payment_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="partial" <?= $payment_status === 'partial' ? 'selected' : '' ?>>Partial</option>
                                            <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Date From</label>
                                        <input type="date" class="form-control" name="date_from" value="<?= safe_echo($date_from) ?>">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Date To</label>
                                        <input type="date" class="form-control" name="date_to" value="<?= safe_echo($date_to) ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="mdi mdi-magnify me-1"></i> Apply Filters
                                    </button>
                                    <a href="daybook-list.php" class="btn btn-secondary ms-2">
                                        <i class="mdi mdi-refresh me-1"></i> Reset
                                    </a>
                                    <a href="daybook.php" class="btn btn-success float-end">
                                        <i class="mdi mdi-plus me-1"></i> New Entry
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Daybook Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Daybook Entries List</h4>
                                
                                <?php if (empty($daybooks)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-book-open-page-variant" style="font-size: 64px; color: #dee2e6;"></i>
                                        <h5 class="mt-3 text-muted">No daybook entries found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="daybook.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-plus"></i> Create New Entry
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-centered mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Entry #</th>
                                                    <th>Date</th>
                                                    <th>Driver Name</th>
                                                    <th>Customer</th>
                                                    <th>Location</th>
                                                    <th>Items</th>
                                                    <th>Grand Total</th>
                                                    <th>Paid</th>
                                                    <th>Balance</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                  </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($daybooks as $daybook): ?>
                                                <tr>
                                                    <td>
                                                        <div class="invoice-number"><?= safe_echo($daybook['invoice_number']) ?></div>
                                                        <small class="text-muted"><?= getCustomerTypeBadge($daybook['customer_type']) ?></small>
                                                    </td>
                                                    <td><?= date('d-m-Y', strtotime($daybook['invoice_date'])) ?></td>
                                                    <td>
                                                        <?= safe_echo($daybook['driver_name']) ?>
                                                        <?php if (!empty($daybook['driver_number'])): ?>
                                                            <br><small class="text-muted">📞 <?= safe_echo($daybook['driver_number']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?= safe_echo($daybook['customer_name']) ?></strong>
                                                        <?php if (!empty($daybook['customer_mobile'])): ?>
                                                            <br><small class="text-muted">📞 <?= safe_echo($daybook['customer_mobile']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= safe_echo($daybook['location']) ?></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?= $daybook['item_count'] ?? 0 ?> items</span>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong>₹<?= number_format($daybook['grand_total'], 2) ?></strong>
                                                        <?php if ($daybook['gst_total'] > 0): ?>
                                                            <br><small class="text-muted">GST: ₹<?= number_format($daybook['gst_total'], 2) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($daybook['paid_amount'], 2) ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php $balance = $daybook['grand_total'] - $daybook['paid_amount']; ?>
                                                        <span class="<?= $balance > 0 ? 'text-danger' : ($balance < 0 ? 'text-success' : '') ?>">
                                                            ₹<?= number_format($balance, 2) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= getPaymentStatusBadge($daybook['payment_status']) ?></td>
                                                    <td class="action-buttons">
                                                        <a href="print-daybook.php?id=<?= $daybook['id'] ?>" 
                                                           class="btn btn-sm btn-soft-primary" 
                                                           data-bs-toggle="tooltip" 
                                                           title="View/Print Entry"
                                                           target="_blank">
                                                            <i class="mdi mdi-printer"></i>
                                                        </a>
                                                        <a href="edit-daybook.php?id=<?= $daybook['id'] ?>" 
                                                           class="btn btn-sm btn-soft-warning" 
                                                           data-bs-toggle="tooltip" 
                                                           title="Edit Entry">
                                                            <i class="mdi mdi-pencil"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Pagination -->
                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-sm-6">
                                                <div class="text-muted">
                                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <ul class="pagination justify-content-end">
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    if ($startPage > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">1</a>
                                                        </li>
                                                        <?php if ($startPage > 2): ?>
                                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($endPage < $totalPages): ?>
                                                        <?php if ($endPage < $totalPages - 1): ?>
                                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                                        <?php endif; ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"><?= $totalPages ?></a>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                                            <i class="mdi mdi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Export Options</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                        <i class="mdi mdi-printer me-1"></i> Print List
                                    </button>
                                    <a href="export-daybook.php?search=<?= urlencode($search) ?>&customer_name=<?= urlencode($customer_name) ?>&payment_status=<?= $payment_status ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" 
                                       class="btn btn-outline-success">
                                        <i class="mdi mdi-file-excel me-1"></i> Export to Excel
                                    </a>
                                    <a href="daybook.php" class="btn btn-outline-primary">
                                        <i class="mdi mdi-plus me-1"></i> New Entry
                                    </a>
                                </div>
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

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Auto-submit on filter change (optional)
    document.querySelector('select[name="payment_status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (!alert.classList.contains('alert-info')) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert.parentNode) alert.remove();
                }, 500);
            }
        });
    }, 5000);
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>

</body>
</html>