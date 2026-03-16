<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Get user role to determine what to show
$user_role = $_SESSION['role'] ?? 'sales';
$is_admin = ($user_role === 'admin');

// Get dashboard statistics
try {
    // Total customers
    $customerStmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
    $totalCustomers = $customerStmt->fetch()['total'] ?? 0;
    
    // Total suppliers
    $supplierStmt = $pdo->query("SELECT COUNT(*) as total FROM suppliers");
    $totalSuppliers = $supplierStmt->fetch()['total'] ?? 0;
    
    // Total products
    $productStmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $totalProducts = $productStmt->fetch()['total'] ?? 0;
    
    // Total invoices
    $invoiceStmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as amount FROM invoices");
    $invoiceData = $invoiceStmt->fetch();
    $totalInvoices = $invoiceData['total'] ?? 0;
    $totalInvoiceAmount = $invoiceData['amount'] ?? 0;
    
    // Recent customers
    $recentCustomersStmt = $pdo->query("SELECT id, name, customer_code, created_at FROM customers ORDER BY created_at DESC LIMIT 5");
    $recentCustomers = $recentCustomersStmt->fetchAll();
    
    // Recent suppliers
    $recentSuppliersStmt = $pdo->query("SELECT id, name, supplier_code, created_at FROM suppliers ORDER BY created_at DESC LIMIT 5");
    $recentSuppliers = $recentSuppliersStmt->fetchAll();
    
    // Recent invoices
    $recentInvoicesStmt = $pdo->query("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status, c.name as customer_name 
        FROM invoices i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $recentInvoices = $recentInvoicesStmt->fetchAll();
    
    // Low stock products
    $lowStockStmt = $pdo->query("
        SELECT id, name, current_stock, reorder_level 
        FROM products 
        WHERE current_stock <= reorder_level AND reorder_level > 0 
        ORDER BY current_stock ASC 
        LIMIT 5
    ");
    $lowStockProducts = $lowStockStmt->fetchAll();
    
    // Get recent activity logs for admin
    if ($is_admin) {
        $activityStmt = $pdo->query("
            SELECT al.*, at.name as activity_type, u.full_name as user_name, u.role as user_role
            FROM activity_logs al
            LEFT JOIN activity_types at ON al.activity_type_id = at.id
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 10
        ");
        $recentActivities = $activityStmt->fetchAll();
        
        // Get activity statistics for admin
        $activityStatsStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_activities,
                COUNT(DISTINCT user_id) as active_users,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_activities,
                MAX(created_at) as last_activity
            FROM activity_logs
        ");
        $activityStats = $activityStatsStmt->fetch();
    }
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // Set default values if queries fail
    $totalCustomers = 0;
    $totalSuppliers = 0;
    $totalProducts = 0;
    $totalInvoices = 0;
    $totalInvoiceAmount = 0;
    $recentCustomers = [];
    $recentSuppliers = [];
    $recentInvoices = [];
    $lowStockProducts = [];
    $recentActivities = [];
    $activityStats = ['total_activities' => 0, 'active_users' => 0, 'today_activities' => 0, 'last_activity' => null];
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .activity-feed {
            padding: 0;
            margin: 0;
            list-style: none;
        }
        .activity-feed .feed-item {
            position: relative;
            padding-bottom: 20px;
            padding-left: 30px;
            border-left: 2px solid #e9ecef;
        }
        .activity-feed .feed-item:last-child {
            border-color: transparent;
            padding-bottom: 0;
        }
        .activity-feed .feed-item::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #556ee6;
        }
        .activity-feed .feed-item.feed-item-success::before {
            background-color: #34c38f;
        }
        .activity-feed .feed-item.feed-item-warning::before {
            background-color: #f9b851;
        }
        .activity-feed .feed-item.feed-item-danger::before {
            background-color: #f46a6a;
        }
        .activity-feed .feed-item.feed-item-info::before {
            background-color: #50a5f1;
        }
        .activity-feed .feed-item .date {
            display: block;
            position: relative;
            top: -5px;
            color: #8c98a8;
            text-transform: uppercase;
            font-size: 13px;
        }
        .activity-feed .feed-item .activity-text {
            position: relative;
            top: -3px;
        }
        .activity-badge {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .activity-badge.bg-primary { background-color: #556ee6; }
        .activity-badge.bg-success { background-color: #34c38f; }
        .activity-badge.bg-warning { background-color: #f9b851; }
        .activity-badge.bg-danger { background-color: #f46a6a; }
        .activity-badge.bg-info { background-color: #50a5f1; }
        .activity-badge.bg-secondary { background-color: #6c757d; }
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
                            <h4 class="mb-0 font-size-18">Dashboard</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Overview</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Welcome Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h4 class="mb-2">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>!</h4>
                                        <p class="mb-0">Here's what's happening with your business today.</p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <img src="assets/images/profile-img.png" alt="" height="80">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end welcome row -->

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-customers-chart" data-colors='["--bs-primary"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalCustomers) ?></h4>
                                    <p class="text-muted mb-0">Total Customers</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 2.5%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-suppliers-chart" data-colors='["--bs-info"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalSuppliers) ?></h4>
                                    <p class="text-muted mb-0">Total Suppliers</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 1.8%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-products-chart" data-colors='["--bs-success"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalProducts) ?></h4>
                                    <p class="text-muted mb-0">Total Products</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 3.2%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-invoices-chart" data-colors='["--bs-warning"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalInvoices) ?></h4>
                                    <p class="text-muted mb-0">Total Invoices</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-danger me-1"><i class="mdi mdi-arrow-down-bold"></i> 0.5%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Admin Activity Stats Row (visible only to admin) -->
                <?php if ($is_admin): ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-history font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($activityStats['total_activities'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Total Activities</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-account-group font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($activityStats['active_users'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Active Users</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-calendar-today font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($activityStats['today_activities'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Today's Activities</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- end admin stats row -->

                <!-- Revenue and Charts Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Revenue Overview</h4>
                                
                                <div class="row text-center mt-4">
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">₹<?= number_format($totalInvoiceAmount, 2) ?></h5>
                                        <p class="text-muted text-truncate">Total Revenue</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">₹<?= number_format($totalInvoiceAmount * 0.7, 2) ?></h5>
                                        <p class="text-muted text-truncate">This Month</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18"><?= number_format($totalCustomers) ?></h5>
                                        <p class="text-muted text-truncate">Active Customers</p>
                                    </div>
                                </div>

                                <div id="revenue-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Quick Actions</h4>

                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-customer.php" class="btn btn-primary btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-account-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Customer</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-supplier.php" class="btn btn-success btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-truck-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Supplier</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-product.php" class="btn btn-info btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-package-variant-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Product</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="create-invoice.php" class="btn btn-warning btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-file-document-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">New Invoice</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Activities Row -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Customers</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Joined</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentCustomers)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No recent customers</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentCustomers as $customer): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($customer['name']) ?></td>
                                                    <td><?= htmlspecialchars($customer['customer_code']) ?></td>
                                                    <td><?= date('d M Y', strtotime($customer['created_at'])) ?></td>
                                                    <td>
                                                        <a href="view-customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-customers.php" class="text-primary">View all customers <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Suppliers</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Joined</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentSuppliers)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No recent suppliers</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentSuppliers as $supplier): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($supplier['name']) ?></td>
                                                    <td><?= htmlspecialchars($supplier['supplier_code']) ?></td>
                                                    <td><?= date('d M Y', strtotime($supplier['created_at'])) ?></td>
                                                    <td>
                                                        <a href="view-supplier.php?id=<?= $supplier['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-suppliers.php" class="text-primary">View all suppliers <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Invoices and Activity Logs Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Invoices</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Customer</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentInvoices)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No recent invoices</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentInvoices as $invoice): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                    <td><?= htmlspecialchars($invoice['customer_name'] ?? 'N/A') ?></td>
                                                    <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                    <td>₹<?= number_format($invoice['total_amount'], 2) ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        switch($invoice['status']) {
                                                            case 'paid':
                                                                $statusClass = 'success';
                                                                break;
                                                            case 'partially_paid':
                                                                $statusClass = 'warning';
                                                                break;
                                                            case 'overdue':
                                                                $statusClass = 'danger';
                                                                break;
                                                            default:
                                                                $statusClass = 'secondary';
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-invoices.php" class="text-primary">View all invoices <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Logs Section - Visible only to admin -->
                    <div class="col-xl-4">
                        <?php if ($is_admin): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Activity Logs</h4>
                                
                                <?php if (empty($recentActivities)): ?>
                                    <div class="text-center py-4">
                                        <i class="mdi mdi-history" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No activities found</h5>
                                    </div>
                                <?php else: ?>
                                    <ol class="activity-feed">
                                        <?php foreach ($recentActivities as $activity): ?>
                                            <?php
                                            // Determine activity type for styling
                                            $feedClass = 'feed-item';
                                            switch($activity['activity_type']) {
                                                case 'create':
                                                    $feedClass .= ' feed-item-success';
                                                    break;
                                                case 'update':
                                                    $feedClass .= ' feed-item-info';
                                                    break;
                                                case 'delete':
                                                    $feedClass .= ' feed-item-danger';
                                                    break;
                                                case 'login':
                                                    $feedClass .= ' feed-item-primary';
                                                    break;
                                                default:
                                                    $feedClass .= ' feed-item-secondary';
                                            }
                                            
                                            // Format time
                                            $time = strtotime($activity['created_at']);
                                            $now = time();
                                            $diff = $now - $time;
                                            
                                            if ($diff < 60) {
                                                $timeDisplay = 'Just now';
                                            } elseif ($diff < 3600) {
                                                $minutes = floor($diff / 60);
                                                $timeDisplay = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
                                            } elseif ($diff < 86400) {
                                                $hours = floor($diff / 3600);
                                                $timeDisplay = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                                            } else {
                                                $timeDisplay = date('d M Y, h:i A', $time);
                                            }
                                            ?>
                                            <li class="<?= $feedClass ?>">
                                                <span class="date"><?= $timeDisplay ?></span>
                                                <span class="activity-text">
                                                    <strong><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></strong>
                                                    <?php if ($activity['user_role']): ?>
                                                        <span class="badge bg-soft-secondary text-secondary"><?= ucfirst($activity['user_role']) ?></span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <?= htmlspecialchars($activity['description'] ?? 'No description') ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                    <div class="text-center mt-3">
                                        <a href="activity-logs.php" class="text-primary">View all activities <i class="mdi mdi-arrow-right"></i></a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Low Stock Alert for non-admin users -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Low Stock Alert</h4>

                                <?php if (empty($lowStockProducts)): ?>
                                    <div class="text-center py-4">
                                        <i class="mdi mdi-check-circle text-success" style="font-size: 48px;"></i>
                                        <h5 class="mt-3">All products are well stocked!</h5>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Current Stock</th>
                                                    <th>Reorder Level</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lowStockProducts as $product): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($product['name']) ?></td>
                                                    <td class="text-danger font-weight-bold"><?= number_format($product['current_stock']) ?></td>
                                                    <td><?= number_format($product['reorder_level']) ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-danger text-danger">
                                                            <i class="mdi mdi-alert"></i> Low Stock
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center mt-3">
                                        <a href="manage-products.php" class="text-primary">Manage products <i class="mdi mdi-arrow-right"></i></a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
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

<!-- Chart JS -->
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Revenue Chart
    var options = {
        chart: {
            height: 280,
            type: 'area',
            toolbar: {
                show: false
            }
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            curve: 'smooth',
            width: 3
        },
        series: [{
            name: 'Revenue',
            data: [31, 40, 28, 51, 42, 109, 100]
        }],
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
        },
        colors: ['#556ee6'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.3
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#revenue-chart"), options);
    chart.render();

    // Mini charts for stat cards
    function generateMiniChart(elementId, color, data) {
        var options = {
            chart: {
                height: 40,
                width: 70,
                type: 'line',
                sparkline: {
                    enabled: true
                },
                toolbar: {
                    show: false
                }
            },
            colors: [color],
            series: [{
                data: data
            }],
            stroke: {
                curve: 'smooth',
                width: 2
            },
            fill: {
                opacity: 1
            }
        };
        
        var chart = new ApexCharts(document.querySelector(elementId), options);
        chart.render();
    }

    // Generate mini charts for each stat card
    generateMiniChart('#total-customers-chart', '#556ee6', [20, 25, 30, 28, 32, 35, 40]);
    generateMiniChart('#total-suppliers-chart', '#34c38f', [15, 18, 20, 22, 25, 28, 30]);
    generateMiniChart('#total-products-chart', '#50a5f1', [35, 40, 38, 42, 45, 48, 52]);
    generateMiniChart('#total-invoices-chart', '#f46a6a', [25, 22, 28, 24, 30, 28, 32]);

    // Auto-refresh activity logs every 30 seconds (optional)
    <?php if ($is_admin): ?>
    setInterval(function() {
        // You can implement AJAX refresh here if needed
        console.log('Auto-refresh activity logs');
    }, 30000);
    <?php endif; ?>
</script>

</body>
</html>