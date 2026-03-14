<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



$error = '';
$success = '';

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : 'daily';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Get all customers for filter
$custStmt = $pdo->query("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $custStmt->fetchAll();

// Get all categories for filter
$catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $catStmt->fetchAll();

// Get all products for filter
$prodStmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
$products = $prodStmt->fetchAll();

// ========== SALES OVERVIEW ==========
$overviewQuery = "
    SELECT 
        COUNT(DISTINCT i.id) as total_invoices,
        COUNT(DISTINCT i.customer_id) as total_customers,
        COALESCE(SUM(i.total_amount), 0) as total_sales,
        COALESCE(SUM(i.paid_amount), 0) as total_collected,
        COALESCE(SUM(i.outstanding_amount), 0) as total_outstanding,
        COALESCE(AVG(i.total_amount), 0) as avg_invoice_value
    FROM invoices i
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
";

$overviewParams = [
    ':from_date' => $from_date,
    ':to_date' => $to_date
];

$overviewStmt = $pdo->prepare($overviewQuery);
$overviewStmt->execute($overviewParams);
$overview = $overviewStmt->fetch();

// ========== DAILY/MONTHLY SALES TREND ==========
if ($filter_type == 'daily') {
    $trendQuery = "
        SELECT 
            DATE(i.invoice_date) as date,
            COUNT(DISTINCT i.id) as invoice_count,
            COUNT(DISTINCT i.customer_id) as customer_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            COALESCE(SUM(i.paid_amount), 0) as total_paid,
            COALESCE(SUM(i.outstanding_amount), 0) as total_outstanding
        FROM invoices i
        WHERE i.status != 'cancelled'
        AND i.invoice_date BETWEEN :from_date AND :to_date
        GROUP BY DATE(i.invoice_date)
        ORDER BY date ASC
    ";
} else {
    $trendQuery = "
        SELECT 
            DATE_FORMAT(i.invoice_date, '%Y-%m') as date,
            COUNT(DISTINCT i.id) as invoice_count,
            COUNT(DISTINCT i.customer_id) as customer_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            COALESCE(SUM(i.paid_amount), 0) as total_paid,
            COALESCE(SUM(i.outstanding_amount), 0) as total_outstanding
        FROM invoices i
        WHERE i.status != 'cancelled'
        AND i.invoice_date BETWEEN :from_date AND :to_date
        GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
        ORDER BY date ASC
    ";
}

$trendStmt = $pdo->prepare($trendQuery);
$trendStmt->execute($overviewParams);
$trendData = $trendStmt->fetchAll();

// ========== TOP PRODUCTS ==========
$productQuery = "
    SELECT 
        p.id,
        p.name,
        p.unit,
        c.name as category_name,
        COUNT(DISTINCT ii.invoice_id) as sale_count,
        SUM(ii.quantity) as total_quantity,
        COALESCE(SUM(ii.total_price), 0) as total_sales,
        COALESCE(SUM(ii.gst_amount), 0) as total_gst,
        COALESCE(AVG(ii.unit_price), 0) as avg_price
    FROM invoice_items ii
    JOIN products p ON ii.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
";

$productParams = [
    ':from_date' => $from_date,
    ':to_date' => $to_date
];

if ($category_id > 0) {
    $productQuery .= " AND p.category_id = :category_id";
    $productParams[':category_id'] = $category_id;
}

if ($product_id > 0) {
    $productQuery .= " AND p.id = :product_id";
    $productParams[':product_id'] = $product_id;
}

$productQuery .= " GROUP BY p.id ORDER BY total_sales DESC LIMIT 10";

$productStmt = $pdo->prepare($productQuery);
$productStmt->execute($productParams);
$topProducts = $productStmt->fetchAll();

// ========== TOP CUSTOMERS ==========
$customerQuery = "
    SELECT 
        c.id,
        c.name,
        c.customer_code,
        c.phone,
        c.email,
        c.city,
        COUNT(DISTINCT i.id) as invoice_count,
        COALESCE(SUM(i.total_amount), 0) as total_purchases,
        COALESCE(SUM(i.paid_amount), 0) as total_paid,
        COALESCE(SUM(i.outstanding_amount), 0) as outstanding,
        MAX(i.invoice_date) as last_purchase_date
    FROM customers c
    JOIN invoices i ON c.id = i.customer_id
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
";

$customerParams = [
    ':from_date' => $from_date,
    ':to_date' => $to_date
];

if ($customer_id > 0) {
    $customerQuery .= " AND c.id = :customer_id";
    $customerParams[':customer_id'] = $customer_id;
}

$customerQuery .= " GROUP BY c.id ORDER BY total_purchases DESC LIMIT 10";

$customerStmt = $pdo->prepare($customerQuery);
$customerStmt->execute($customerParams);
$topCustomers = $customerStmt->fetchAll();

// ========== PAYMENT TYPE ANALYSIS ==========
$paymentQuery = "
    SELECT 
        i.payment_type,
        COUNT(*) as invoice_count,
        COALESCE(SUM(i.total_amount), 0) as total_amount,
        COALESCE(SUM(i.paid_amount), 0) as paid_amount,
        COALESCE(SUM(i.outstanding_amount), 0) as outstanding_amount
    FROM invoices i
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
    GROUP BY i.payment_type
    ORDER BY total_amount DESC
";

$paymentStmt = $pdo->prepare($paymentQuery);
$paymentStmt->execute($overviewParams);
$paymentData = $paymentStmt->fetchAll();

// ========== STATUS ANALYSIS ==========
$statusQuery = "
    SELECT 
        i.status,
        COUNT(*) as count,
        COALESCE(SUM(i.total_amount), 0) as total_amount,
        COALESCE(SUM(i.paid_amount), 0) as paid_amount,
        COALESCE(SUM(i.outstanding_amount), 0) as outstanding_amount
    FROM invoices i
    WHERE i.invoice_date BETWEEN :from_date AND :to_date
    GROUP BY i.status
    ORDER BY count DESC
";

$statusStmt = $pdo->prepare($statusQuery);
$statusStmt->execute($overviewParams);
$statusData = $statusStmt->fetchAll();

// ========== CATEGORY WISE SALES ==========
$categorySalesQuery = "
    SELECT 
        cat.id,
        cat.name,
        COUNT(DISTINCT ii.invoice_id) as invoice_count,
        COUNT(DISTINCT ii.product_id) as product_count,
        SUM(ii.quantity) as total_quantity,
        COALESCE(SUM(ii.total_price), 0) as total_sales,
        COALESCE(SUM(ii.gst_amount), 0) as total_gst
    FROM categories cat
    LEFT JOIN products p ON cat.id = p.category_id
    LEFT JOIN invoice_items ii ON p.id = ii.product_id
    LEFT JOIN invoices i ON ii.invoice_id = i.id AND i.status != 'cancelled' AND i.invoice_date BETWEEN :from_date AND :to_date
    GROUP BY cat.id
    HAVING total_sales > 0
    ORDER BY total_sales DESC
";

$categorySalesStmt = $pdo->prepare($categorySalesQuery);
$categorySalesStmt->execute($overviewParams);
$categorySales = $categorySalesStmt->fetchAll();

// ========== HOURLY/DOW ANALYSIS ==========
$timeAnalysisQuery = "
    SELECT 
        DAYNAME(i.invoice_date) as day_of_week,
        COUNT(*) as invoice_count,
        COALESCE(SUM(i.total_amount), 0) as total_sales,
        COALESCE(AVG(i.total_amount), 0) as avg_sale
    FROM invoices i
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
    GROUP BY DAYOFWEEK(i.invoice_date)
    ORDER BY DAYOFWEEK(i.invoice_date)
";

$timeStmt = $pdo->prepare($timeAnalysisQuery);
$timeStmt->execute($overviewParams);
$timeData = $timeStmt->fetchAll();

// ========== MONTHLY COMPARISON ==========
$monthlyQuery = "
    SELECT 
        DATE_FORMAT(i.invoice_date, '%Y-%m') as month,
        COUNT(DISTINCT i.id) as invoice_count,
        COUNT(DISTINCT i.customer_id) as customer_count,
        COALESCE(SUM(i.total_amount), 0) as total_sales,
        COALESCE(SUM(i.paid_amount), 0) as total_paid,
        COALESCE(SUM(i.outstanding_amount), 0) as total_outstanding,
        COALESCE(AVG(i.total_amount), 0) as avg_invoice
    FROM invoices i
    WHERE i.status != 'cancelled'
    AND i.invoice_date >= DATE_SUB(:to_date, INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
    ORDER BY month DESC
";

$monthlyStmt = $pdo->prepare($monthlyQuery);
$monthlyStmt->execute([':to_date' => $to_date]);
$monthlyData = $monthlyStmt->fetchAll();

// ========== GST ANALYSIS ==========
$gstAnalysisQuery = "
    SELECT 
        g.id,
        g.gst_rate,
        g.hsn_code,
        COUNT(DISTINCT ii.id) as item_count,
        SUM(ii.quantity) as total_quantity,
        COALESCE(SUM(ii.gst_amount), 0) as total_gst,
        COALESCE(SUM(ii.total_price - ii.gst_amount), 0) as taxable_value
    FROM gst_details g
    LEFT JOIN invoice_items ii ON g.id = ii.gst_id
    LEFT JOIN invoices i ON ii.invoice_id = i.id AND i.status != 'cancelled' AND i.invoice_date BETWEEN :from_date AND :to_date
    WHERE g.is_active = 1
    GROUP BY g.id
    HAVING total_gst > 0
    ORDER BY g.gst_rate
";

$gstStmt = $pdo->prepare($gstAnalysisQuery);
$gstStmt->execute($overviewParams);
$gstData = $gstStmt->fetchAll();

// Calculate growth percentages
$previousPeriodQuery = "
    SELECT COALESCE(SUM(i.total_amount), 0) as total_sales
    FROM invoices i
    WHERE i.status != 'cancelled'
    AND i.invoice_date BETWEEN :from_date AND :to_date
";

$prevFrom = date('Y-m-d', strtotime($from_date . ' -' . (strtotime($to_date) - strtotime($from_date)) / (60*60*24) . ' days'));
$prevTo = date('Y-m-d', strtotime($from_date . ' -1 day'));

$prevStmt = $pdo->prepare($previousPeriodQuery);
$prevStmt->execute([
    ':from_date' => $prevFrom,
    ':to_date' => $prevTo
]);
$previousSales = $prevStmt->fetchColumn();

$growth = 0;
if ($previousSales > 0) {
    $growth = (($overview['total_sales'] - $previousSales) / $previousSales) * 100;
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .stats-card:nth-child(2) {
            background: linear-gradient(135deg, #34c38f 0%, #0acf97 100%);
        }
        .stats-card:nth-child(3) {
            background: linear-gradient(135deg, #f46a6a 0%, #fa5c7c 100%);
        }
        .stats-card:nth-child(4) {
            background: linear-gradient(135deg, #f9b851 0%, #f7b84b 100%);
        }
        .stats-icon {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 48px;
            opacity: 0.2;
        }
        .stats-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .stats-label {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        .growth-positive {
            color: #34c38f;
        }
        .growth-negative {
            color: #f46a6a;
        }
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        .analytics-card {
            border-left: 4px solid #556ee6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .analytics-card .card-header {
            background-color: #f8f9fa;
            border-bottom: none;
            padding: 15px 20px;
        }
        .analytics-card .card-header h5 {
            margin: 0;
            color: #556ee6;
        }
        .badge-success {
            background-color: #34c38f;
            color: white;
        }
        .badge-warning {
            background-color: #f9b851;
            color: white;
        }
        .badge-danger {
            background-color: #f46a6a;
            color: white;
        }
        .badge-info {
            background-color: #50a5f1;
            color: white;
        }
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .table-analytics th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .progress {
            height: 8px;
        }
    </style>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
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
                            <h4 class="mb-0 font-size-18">Sales Analytics</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Analytics</li>
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

                <!-- Filter Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="filter-section">
                            <form method="GET" action="" id="filterForm">
                                <div class="row align-items-end">
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">From Date</label>
                                            <input type="date" class="form-control" name="from_date" value="<?= $from_date ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">To Date</label>
                                            <input type="date" class="form-control" name="to_date" value="<?= $to_date ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">Filter By</label>
                                            <select name="filter_type" class="form-control">
                                                <option value="daily" <?= $filter_type == 'daily' ? 'selected' : '' ?>>Daily</option>
                                                <option value="monthly" <?= $filter_type == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">Customer</label>
                                            <select name="customer_id" class="form-control">
                                                <option value="0">All Customers</option>
                                                <?php foreach ($customers as $cust): ?>
                                                    <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($cust['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">Category</label>
                                            <select name="category_id" class="form-control">
                                                <option value="0">All Categories</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($cat['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="mdi mdi-filter me-1"></i> Apply Filters
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- end filter section -->

                <!-- Overview Stats -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="mdi mdi-cash-multiple"></i>
                            </div>
                            <div class="stats-value">₹<?= number_format($overview['total_sales'], 2) ?></div>
                            <p class="stats-label">Total Sales</p>
                            <small class="growth-<?= $growth >= 0 ? 'positive' : 'negative' ?>">
                                <i class="mdi mdi-arrow-<?= $growth >= 0 ? 'up' : 'down' ?>"></i>
                                <?= number_format(abs($growth), 1) ?>% vs previous period
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="mdi mdi-receipt"></i>
                            </div>
                            <div class="stats-value"><?= number_format($overview['total_invoices']) ?></div>
                            <p class="stats-label">Total Invoices</p>
                            <small class="text-white">Avg: ₹<?= number_format($overview['avg_invoice_value'], 2) ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="mdi mdi-account-group"></i>
                            </div>
                            <div class="stats-value"><?= number_format($overview['total_customers']) ?></div>
                            <p class="stats-label">Active Customers</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="mdi mdi-currency-inr"></i>
                            </div>
                            <div class="stats-value">₹<?= number_format($overview['total_outstanding'], 2) ?></div>
                            <p class="stats-label">Outstanding Amount</p>
                            <small class="text-white">Collected: ₹<?= number_format($overview['total_collected'], 2) ?></small>
                        </div>
                    </div>
                </div>
                <!-- end stats row -->

                <!-- Sales Trend Chart -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Sales Trend (<?= $filter_type == 'daily' ? 'Daily' : 'Monthly' ?>)</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="salesTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Payment Methods</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="paymentChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end chart row -->

                <!-- Top Products and Customers -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5>Top Selling Products</h5>
                                <a href="products.php" class="btn btn-sm btn-primary">View All Products</a>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Sales</th>
                                                <th>GST</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($topProducts)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No sales data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($topProducts as $product): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                        <br><small class="text-muted"><?= $product['sale_count'] ?> sales</small>
                                                    </td>
                                                    <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= number_format($product['total_quantity']) ?> <?= $product['unit'] ?></td>
                                                    <td>₹<?= number_format($product['total_sales'], 2) ?></td>
                                                    <td>₹<?= number_format($product['total_gst'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5>Top Customers</h5>
                                <a href="manage-customers.php" class="btn btn-sm btn-primary">View All Customers</a>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Customer</th>
                                                <th>Invoices</th>
                                                <th>Purchases</th>
                                                <th>Outstanding</th>
                                                <th>Last Purchase</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($topCustomers)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No customer data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($topCustomers as $customer): ?>
                                                <tr>
                                                    <td>
                                                        <a href="view-customer.php?id=<?= $customer['id'] ?>" class="text-primary">
                                                            <?= htmlspecialchars($customer['name']) ?>
                                                        </a>
                                                        <br><small class="text-muted"><?= htmlspecialchars($customer['customer_code']) ?></small>
                                                    </td>
                                                    <td><?= $customer['invoice_count'] ?></td>
                                                    <td>₹<?= number_format($customer['total_purchases'], 2) ?></td>
                                                    <td class="<?= $customer['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                                                        ₹<?= number_format($customer['outstanding'], 2) ?>
                                                    </td>
                                                    <td><?= date('d-m-Y', strtotime($customer['last_purchase_date'])) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Category Analysis and GST Analysis -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Category Wise Sales</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Products</th>
                                                <th>Invoices</th>
                                                <th>Quantity</th>
                                                <th>Sales</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($categorySales)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No category data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($categorySales as $category): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($category['name']) ?></strong></td>
                                                    <td><?= $category['product_count'] ?></td>
                                                    <td><?= $category['invoice_count'] ?></td>
                                                    <td><?= number_format($category['total_quantity']) ?></td>
                                                    <td>₹<?= number_format($category['total_sales'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>GST Analysis</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>GST Rate</th>
                                                <th>HSN Code</th>
                                                <th>Items</th>
                                                <th>Taxable Value</th>
                                                <th>GST Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($gstData)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No GST data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($gstData as $gst): ?>
                                                <tr>
                                                    <td><span class="badge bg-info"><?= $gst['gst_rate'] ?>%</span></td>
                                                    <td><?= htmlspecialchars($gst['hsn_code']) ?></td>
                                                    <td><?= $gst['item_count'] ?></td>
                                                    <td>₹<?= number_format($gst['taxable_value'], 2) ?></td>
                                                    <td>₹<?= number_format($gst['total_gst'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Invoice Status and Day of Week Analysis -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Invoice Status Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Count</th>
                                                <th>Total Amount</th>
                                                <th>Paid</th>
                                                <th>Outstanding</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($statusData)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No status data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($statusData as $status): ?>
                                                <tr>
                                                    <td>
                                                        <?php
                                                        $badgeClass = '';
                                                        switch($status['status']) {
                                                            case 'paid':
                                                                $badgeClass = 'success';
                                                                break;
                                                            case 'draft':
                                                                $badgeClass = 'secondary';
                                                                break;
                                                            case 'sent':
                                                                $badgeClass = 'primary';
                                                                break;
                                                            case 'partially_paid':
                                                                $badgeClass = 'warning';
                                                                break;
                                                            case 'overdue':
                                                                $badgeClass = 'danger';
                                                                break;
                                                            case 'cancelled':
                                                                $badgeClass = 'dark';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $badgeClass ?> text-<?= $badgeClass ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $status['status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $status['count'] ?></td>
                                                    <td>₹<?= number_format($status['total_amount'], 2) ?></td>
                                                    <td class="text-success">₹<?= number_format($status['paid_amount'], 2) ?></td>
                                                    <td class="text-danger">₹<?= number_format($status['outstanding_amount'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Day of Week Analysis</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Invoices</th>
                                                <th>Total Sales</th>
                                                <th>Average</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($timeData)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php 
                                                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                                foreach ($timeData as $time): 
                                                ?>
                                                <tr>
                                                    <td><strong><?= $time['day_of_week'] ?></strong></td>
                                                    <td><?= $time['invoice_count'] ?></td>
                                                    <td>₹<?= number_format($time['total_sales'], 2) ?></td>
                                                    <td>₹<?= number_format($time['avg_sale'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Monthly Comparison -->
                <div class="row">
                    <div class="col-12">
                        <div class="card analytics-card">
                            <div class="card-header">
                                <h5>Last 6 Months Performance</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-analytics table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Invoices</th>
                                                <th>Customers</th>
                                                <th>Total Sales</th>
                                                <th>Paid</th>
                                                <th>Outstanding</th>
                                                <th>Average</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($monthlyData)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">No monthly data available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($monthlyData as $month): ?>
                                                <tr>
                                                    <td><strong><?= date('F Y', strtotime($month['month'] . '-01')) ?></strong></td>
                                                    <td><?= $month['invoice_count'] ?></td>
                                                    <td><?= $month['customer_count'] ?></td>
                                                    <td>₹<?= number_format($month['total_sales'], 2) ?></td>
                                                    <td class="text-success">₹<?= number_format($month['total_paid'], 2) ?></td>
                                                    <td class="text-danger">₹<?= number_format($month['total_outstanding'], 2) ?></td>
                                                    <td>₹<?= number_format($month['avg_invoice'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
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

<script>
    // Prepare data for charts
    const trendLabels = <?= json_encode(array_column($trendData, 'date')) ?>;
    const trendSales = <?= json_encode(array_column($trendData, 'total_sales')) ?>;
    const trendInvoices = <?= json_encode(array_column($trendData, 'invoice_count')) ?>;
    
    // Sales Trend Chart
    const ctx1 = document.getElementById('salesTrendChart').getContext('2d');
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Sales (₹)',
                data: trendSales,
                borderColor: '#556ee6',
                backgroundColor: 'rgba(85, 110, 230, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Number of Invoices',
                data: trendInvoices,
                borderColor: '#34c38f',
                backgroundColor: 'rgba(52, 195, 143, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Sales (₹)'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Number of Invoices'
                    }
                }
            }
        }
    });

    // Payment Methods Chart
    const paymentLabels = <?= json_encode(array_column($paymentData, 'payment_type')) ?>;
    const paymentAmounts = <?= json_encode(array_column($paymentData, 'total_amount')) ?>;
    
    const ctx2 = document.getElementById('paymentChart').getContext('2d');
    new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: paymentLabels.map(label => {
                const labels = {
                    'cash': 'Cash',
                    'credit': 'Credit',
                    'bank_transfer': 'Bank Transfer',
                    'cheque': 'Cheque',
                    'online': 'Online'
                };
                return labels[label] || label;
            }),
            datasets: [{
                data: paymentAmounts,
                backgroundColor: [
                    '#556ee6',
                    '#34c38f',
                    '#f46a6a',
                    '#f9b851',
                    '#50a5f1'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Auto-submit on filter changes
    document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="category_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="filter_type"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });

    // Date validation
    document.querySelector('input[name="from_date"]')?.addEventListener('change', function() {
        const toDate = document.querySelector('input[name="to_date"]');
        if (toDate.value < this.value) {
            toDate.value = this.value;
        }
    });
    
    document.querySelector('input[name="to_date"]')?.addEventListener('change', function() {
        const fromDate = document.querySelector('input[name="from_date"]');
        if (fromDate.value > this.value) {
            fromDate.value = this.value;
        }
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 5000);
</script>

</body>
</html>