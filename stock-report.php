<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get report type from query parameter
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'current_stock';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';

// Get all categories for dropdown
try {
    $categoriesStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $categoriesStmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get all products for dropdown
try {
    $productsStmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    $products = $productsStmt->fetchAll();
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Initialize report data
$report_data = [];
$report_summary = [];
$chart_data = [];

// Generate report based on type
try {
    switch ($report_type) {
        case 'current_stock':
            // Current Stock Report
            $query = "
                SELECT 
                    p.id,
                    p.name as product_name,
                    p.description,
                    p.unit,
                    p.current_stock,
                    p.reorder_level,
                    p.selling_price,
                    p.cost_price,
                    c.id as category_id,
                    c.name as category_name,
                    g.gst_rate,
                    (p.current_stock * p.cost_price) as stock_value,
                    (p.current_stock * p.selling_price) as sales_value,
                    CASE 
                        WHEN p.current_stock <= p.reorder_level AND p.reorder_level > 0 THEN 'Low Stock'
                        WHEN p.current_stock = 0 THEN 'Out of Stock'
                        ELSE 'In Stock'
                    END as stock_status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN gst_details g ON p.gst_id = g.id
                WHERE p.is_active = 1
            ";
            
            $params = [];
            
            if (!empty($category_id)) {
                $query .= " AND p.category_id = :category_id";
                $params[':category_id'] = $category_id;
            }
            
            if (!empty($product_id)) {
                $query .= " AND p.id = :product_id";
                $params[':product_id'] = $product_id;
            }
            
            $query .= " ORDER BY c.name, p.name";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_stock_value = 0;
            $total_sales_value = 0;
            $low_stock_count = 0;
            $out_of_stock_count = 0;
            
            foreach ($report_data as $item) {
                $total_stock_value += $item['stock_value'] ?? 0;
                $total_sales_value += $item['sales_value'] ?? 0;
                if ($item['stock_status'] == 'Low Stock') $low_stock_count++;
                if ($item['stock_status'] == 'Out of Stock') $out_of_stock_count++;
            }
            
            $report_summary = [
                'total_products' => count($report_data),
                'total_stock_value' => $total_stock_value,
                'total_sales_value' => $total_sales_value,
                'low_stock_count' => $low_stock_count,
                'out_of_stock_count' => $out_of_stock_count
            ];
            
            // Chart data - Stock by Category
            $chart_query = "
                SELECT 
                    c.name as category_name,
                    SUM(p.current_stock * p.cost_price) as category_value,
                    COUNT(p.id) as product_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1
                GROUP BY c.id, c.name
                ORDER BY category_value DESC
            ";
            $chartStmt = $pdo->query($chart_query);
            $chart_data = $chartStmt->fetchAll();
            break;
            
        case 'stock_movement':
            // Stock Movement Report
            $query = "
                SELECT 
                    DATE(st.transaction_date) as movement_date,
                    p.name as product_name,
                    c.name as category_name,
                    st.transaction_type,
                    st.quantity,
                    st.unit_price,
                    st.total_value,
                    st.reference_type,
                    st.reference_id,
                    st.notes,
                    u.full_name as created_by_name
                FROM stock_transactions st
                JOIN products p ON st.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN users u ON st.created_by = u.id
                WHERE DATE(st.transaction_date) BETWEEN :date_from AND :date_to
            ";
            
            $params = [
                ':date_from' => $date_from,
                ':date_to' => $date_to
            ];
            
            if (!empty($category_id)) {
                $query .= " AND p.category_id = :category_id";
                $params[':category_id'] = $category_id;
            }
            
            if (!empty($product_id)) {
                $query .= " AND p.id = :product_id";
                $params[':product_id'] = $product_id;
            }
            
            $query .= " ORDER BY st.transaction_date DESC, st.created_at DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_stock_in = 0;
            $total_stock_out = 0;
            $total_value_in = 0;
            $total_value_out = 0;
            
            foreach ($report_data as $item) {
                if ($item['transaction_type'] == 'in') {
                    $total_stock_in += $item['quantity'];
                    $total_value_in += $item['total_value'] ?? 0;
                } else {
                    $total_stock_out += $item['quantity'];
                    $total_value_out += $item['total_value'] ?? 0;
                }
            }
            
            $report_summary = [
                'total_transactions' => count($report_data),
                'total_stock_in' => $total_stock_in,
                'total_stock_out' => $total_stock_out,
                'total_value_in' => $total_value_in,
                'total_value_out' => $total_value_out,
                'net_movement' => $total_stock_in - $total_stock_out,
                'net_value' => $total_value_in - $total_value_out
            ];
            
            // Chart data - Daily movement
            $chart_query = "
                SELECT 
                    DATE(transaction_date) as mov_date,
                    SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END) as daily_in,
                    SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END) as daily_out
                FROM stock_transactions
                WHERE transaction_date BETWEEN :date_from AND :date_to
                GROUP BY DATE(transaction_date)
                ORDER BY mov_date
            ";
            $chartStmt = $pdo->prepare($chart_query);
            $chartStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
            $chart_data = $chartStmt->fetchAll();
            break;
            
        case 'valuation':
            // Stock Valuation Report
            $query = "
                SELECT 
                    p.id,
                    p.name as product_name,
                    c.name as category_name,
                    p.unit,
                    p.current_stock,
                    p.cost_price,
                    p.selling_price,
                    (p.current_stock * p.cost_price) as total_cost,
                    (p.current_stock * p.selling_price) as total_sales_value,
                    ((p.selling_price - p.cost_price) * p.current_stock) as potential_profit,
                    CASE 
                        WHEN p.cost_price > 0 THEN ((p.selling_price - p.cost_price) / p.cost_price * 100)
                        ELSE 0
                    END as profit_margin_percent
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1 AND p.current_stock > 0
            ";
            
            $params = [];
            
            if (!empty($category_id)) {
                $query .= " AND p.category_id = :category_id";
                $params[':category_id'] = $category_id;
            }
            
            $query .= " ORDER BY total_cost DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_cost_value = 0;
            $total_sales_value = 0;
            $total_potential_profit = 0;
            $avg_margin = 0;
            
            foreach ($report_data as $item) {
                $total_cost_value += $item['total_cost'] ?? 0;
                $total_sales_value += $item['total_sales_value'] ?? 0;
                $total_potential_profit += $item['potential_profit'] ?? 0;
            }
            
            if (count($report_data) > 0) {
                $avg_margin = ($total_sales_value - $total_cost_value) / $total_cost_value * 100;
            }
            
            $report_summary = [
                'total_products' => count($report_data),
                'total_cost_value' => $total_cost_value,
                'total_sales_value' => $total_sales_value,
                'total_potential_profit' => $total_potential_profit,
                'average_margin' => $avg_margin
            ];
            
            // Chart data - Valuation by Category
            $chart_query = "
                SELECT 
                    c.name as category_name,
                    SUM(p.current_stock * p.cost_price) as category_cost,
                    SUM(p.current_stock * p.selling_price) as category_sales,
                    COUNT(p.id) as product_count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1 AND p.current_stock > 0
                GROUP BY c.id, c.name
                ORDER BY category_cost DESC
            ";
            $chartStmt = $pdo->query($chart_query);
            $chart_data = $chartStmt->fetchAll();
            break;
            
        case 'slow_moving':
            // Slow Moving/Dead Stock Report
            $query = "
                SELECT 
                    p.id,
                    p.name as product_name,
                    c.name as category_name,
                    p.unit,
                    p.current_stock,
                    p.reorder_level,
                    COALESCE((
                        SELECT SUM(quantity) 
                        FROM stock_transactions st 
                        WHERE st.product_id = p.id 
                        AND st.transaction_type = 'out'
                        AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    ), 0) as last_30_days_sales,
                    COALESCE((
                        SELECT SUM(quantity) 
                        FROM stock_transactions st 
                        WHERE st.product_id = p.id 
                        AND st.transaction_type = 'out'
                        AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                    ), 0) as last_90_days_sales,
                    (
                        SELECT MAX(transaction_date) 
                        FROM stock_transactions st 
                        WHERE st.product_id = p.id 
                        AND st.transaction_type = 'out'
                    ) as last_sale_date,
                    DATEDIFF(CURDATE(), MAX(st.transaction_date)) as days_since_last_sale
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN stock_transactions st ON p.id = st.product_id AND st.transaction_type = 'out'
                WHERE p.is_active = 1
                GROUP BY p.id, p.name, c.name, p.unit, p.current_stock, p.reorder_level
                HAVING last_90_days_sales < p.current_stock OR last_90_days_sales = 0
                ORDER BY days_since_last_sale DESC
            ";
            
            $stmt = $pdo->query($query);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $slow_moving_count = 0;
            $dead_stock_count = 0;
            $total_dead_stock_value = 0;
            
            foreach ($report_data as $item) {
                if ($item['last_90_days_sales'] == 0) {
                    $dead_stock_count++;
                    $total_dead_stock_value += $item['current_stock']; // This should be multiplied by cost price ideally
                } elseif ($item['last_90_days_sales'] < $item['current_stock']) {
                    $slow_moving_count++;
                }
            }
            
            $report_summary = [
                'total_products' => count($report_data),
                'slow_moving_count' => $slow_moving_count,
                'dead_stock_count' => $dead_stock_count,
                'total_dead_stock_value' => $total_dead_stock_value
            ];
            break;
            
        case 'reorder':
            // Reorder Level Report
            $query = "
                SELECT 
                    p.id,
                    p.name as product_name,
                    c.name as category_name,
                    p.unit,
                    p.current_stock,
                    p.reorder_level,
                    (p.reorder_level - p.current_stock) as quantity_to_order,
                    p.cost_price,
                    ((p.reorder_level - p.current_stock) * p.cost_price) as estimated_cost,
                    COALESCE((
                        SELECT AVG(quantity) 
                        FROM stock_transactions st 
                        WHERE st.product_id = p.id 
                        AND st.transaction_type = 'out'
                        AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    ), 0) as avg_daily_sales
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1 
                AND p.reorder_level > 0
                AND p.current_stock <= p.reorder_level
                ORDER BY (p.reorder_level - p.current_stock) DESC
            ";
            
            $stmt = $pdo->query($query);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_products_to_reorder = 0;
            $total_quantity_to_order = 0;
            $total_estimated_cost = 0;
            
            foreach ($report_data as $item) {
                if ($item['quantity_to_order'] > 0) {
                    $total_products_to_reorder++;
                    $total_quantity_to_order += $item['quantity_to_order'];
                    $total_estimated_cost += $item['estimated_cost'] ?? 0;
                }
            }
            
            $report_summary = [
                'total_products' => $total_products_to_reorder,
                'total_quantity_to_order' => $total_quantity_to_order,
                'total_estimated_cost' => $total_estimated_cost,
                'critical_count' => count(array_filter($report_data, function($item) {
                    return $item['current_stock'] == 0;
                }))
            ];
            break;
    }
    
    // Log activity
    $logStmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
        VALUES (:user_id, 6, :description, :activity_data, :created_by)
    ");
    $logStmt->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':description' => "Generated stock report: " . str_replace('_', ' ', ucfirst($report_type)),
        ':activity_data' => json_encode([
            'report_type' => $report_type,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'category_id' => $category_id,
            'product_id' => $product_id
        ]),
        ':created_by' => $_SESSION['user_id'] ?? null
    ]);
    
} catch (Exception $e) {
    error_log("Stock report error: " . $e->getMessage());
    $report_data = [];
    $report_summary = [];
    $chart_data = [];
    $error_message = "Error generating report: " . $e->getMessage();
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($report_data, $report_type);
    exit();
}

// Export function
function exportToCSV($data, $report_type) {
    $filename = 'stock_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers based on report type
    switch ($report_type) {
        case 'current_stock':
            fputcsv($output, ['Product Name', 'Category', 'Unit', 'Current Stock', 'Reorder Level', 'Cost Price', 'Selling Price', 'Stock Value', 'Sales Value', 'Status']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['product_name'],
                    $row['category_name'],
                    $row['unit'],
                    $row['current_stock'],
                    $row['reorder_level'],
                    $row['cost_price'],
                    $row['selling_price'],
                    $row['stock_value'],
                    $row['sales_value'],
                    $row['stock_status']
                ]);
            }
            break;
            
        case 'stock_movement':
            fputcsv($output, ['Date', 'Product', 'Category', 'Type', 'Quantity', 'Unit Price', 'Total Value', 'Reference', 'Created By']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['movement_date'],
                    $row['product_name'],
                    $row['category_name'],
                    $row['transaction_type'],
                    $row['quantity'],
                    $row['unit_price'],
                    $row['total_value'],
                    $row['reference_type'] ? $row['reference_type'] . ' #' . $row['reference_id'] : 'Manual',
                    $row['created_by_name']
                ]);
            }
            break;
            
        case 'valuation':
            fputcsv($output, ['Product', 'Category', 'Unit', 'Current Stock', 'Cost Price', 'Selling Price', 'Total Cost', 'Sales Value', 'Potential Profit', 'Margin %']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['product_name'],
                    $row['category_name'],
                    $row['unit'],
                    $row['current_stock'],
                    $row['cost_price'],
                    $row['selling_price'],
                    $row['total_cost'],
                    $row['total_sales_value'],
                    $row['potential_profit'],
                    number_format($row['profit_margin_percent'], 2) . '%'
                ]);
            }
            break;
            
        case 'reorder':
            fputcsv($output, ['Product', 'Category', 'Unit', 'Current Stock', 'Reorder Level', 'Qty to Order', 'Est. Cost', 'Avg Daily Sales']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['product_name'],
                    $row['category_name'],
                    $row['unit'],
                    $row['current_stock'],
                    $row['reorder_level'],
                    $row['quantity_to_order'],
                    $row['estimated_cost'],
                    number_format($row['avg_daily_sales'], 2)
                ]);
            }
            break;
    }
    
    fclose($output);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

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
                            <h4 class="mb-0 font-size-18">Stock Reports</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Stock Reports</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Message -->
                <?php if (isset($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Type Selection -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Select Report Type</h4>
                                <div class="row">
                                    <div class="col-md-3">
                                        <a href="?report_type=current_stock" class="text-decoration-none">
                                            <div class="card bg-soft-primary border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-package-variant-closed text-primary" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Current Stock</h6>
                                                    <small class="text-muted">Current inventory levels</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?report_type=stock_movement" class="text-decoration-none">
                                            <div class="card bg-soft-success border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-transfer text-success" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Stock Movement</h6>
                                                    <small class="text-muted">In/Out transactions</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?report_type=valuation" class="text-decoration-none">
                                            <div class="card bg-soft-info border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-cash-multiple text-info" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Stock Valuation</h6>
                                                    <small class="text-muted">Cost & sales value</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?report_type=slow_moving" class="text-decoration-none">
                                            <div class="card bg-soft-warning border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-timer-sand text-warning" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Slow Moving</h6>
                                                    <small class="text-muted">Slow/Dead stock</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?report_type=reorder" class="text-decoration-none">
                                            <div class="card bg-soft-danger border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-alert-circle text-danger" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Reorder Level</h6>
                                                    <small class="text-muted">Items to reorder</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Report</h4>
                                <form method="GET" action="stock-report.php" class="row">
                                    <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="category_id" class="form-label">Category</label>
                                            <select class="form-control" id="category_id" name="category_id">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['id'] ?>" <?= $category_id == $category['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($category['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="product_id" class="form-label">Product</label>
                                            <select class="form-control" id="product_id" name="product_id">
                                                <option value="">All Products</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?= $product['id'] ?>" <?= $product_id == $product['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($product['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
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
                                                <a href="stock-report.php?report_type=<?= $report_type ?>" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <h4 class="mb-0">
                                <?php
                                switch($report_type) {
                                    case 'current_stock':
                                        echo 'Current Stock Report';
                                        break;
                                    case 'stock_movement':
                                        echo 'Stock Movement Report';
                                        break;
                                    case 'valuation':
                                        echo 'Stock Valuation Report';
                                        break;
                                    case 'slow_moving':
                                        echo 'Slow Moving Stock Report';
                                        break;
                                    case 'reorder':
                                        echo 'Reorder Level Report';
                                        break;
                                    default:
                                        echo 'Stock Report';
                                }
                                ?>
                            </h4>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <?php if (!empty($report_summary)): ?>
                <div class="row">
                    <?php if ($report_type == 'current_stock'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Products</h5>
                                <h4><?= number_format($report_summary['total_products']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Stock Value (Cost)</h5>
                                <h4>₹<?= number_format($report_summary['total_stock_value'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Sales Value</h5>
                                <h4>₹<?= number_format($report_summary['total_sales_value'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Low/Out Stock</h5>
                                <h4><?= $report_summary['low_stock_count'] ?> / <?= $report_summary['out_of_stock_count'] ?></h4>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'stock_movement'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Transactions</h5>
                                <h4><?= number_format($report_summary['total_transactions']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Stock In / Out</h5>
                                <h4><?= number_format($report_summary['total_stock_in'], 2) ?> / <?= number_format($report_summary['total_stock_out'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Value In / Out</h5>
                                <h4>₹<?= number_format($report_summary['total_value_in'], 2) ?> / ₹<?= number_format($report_summary['total_value_out'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Net Movement</h5>
                                <h4 class="<?= $report_summary['net_movement'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($report_summary['net_movement'], 2) ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'valuation'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Products with Stock</h5>
                                <h4><?= number_format($report_summary['total_products']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Cost Value</h5>
                                <h4>₹<?= number_format($report_summary['total_cost_value'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Sales Value</h5>
                                <h4>₹<?= number_format($report_summary['total_sales_value'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Potential Profit</h5>
                                <h4 class="text-success">₹<?= number_format($report_summary['total_potential_profit'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'reorder'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Products to Reorder</h5>
                                <h4><?= number_format($report_summary['total_products']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Qty to Order</h5>
                                <h4><?= number_format($report_summary['total_quantity_to_order'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Estimated Cost</h5>
                                <h4>₹<?= number_format($report_summary['total_estimated_cost'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Out of Stock</h5>
                                <h4 class="text-danger"><?= number_format($report_summary['critical_count']) ?></h4>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Chart Section -->
                <?php if (!empty($chart_data)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <?php
                                    switch($report_type) {
                                        case 'current_stock':
                                            echo 'Stock Value by Category';
                                            break;
                                        case 'stock_movement':
                                            echo 'Daily Stock Movement';
                                            break;
                                        case 'valuation':
                                            echo 'Valuation by Category';
                                            break;
                                    }
                                    ?>
                                </h4>
                                <div id="report-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Report Details</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success me-2">
                                                <i class="mdi mdi-export"></i> Export CSV
                                            </a>
                                            <button onclick="window.print()" class="btn btn-info">
                                                <i class="mdi mdi-printer"></i> Print
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <?php if ($report_type == 'current_stock'): ?>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Current Stock</th>
                                                <th>Reorder Level</th>
                                                <th>Cost Price</th>
                                                <th>Selling Price</th>
                                                <th>Stock Value</th>
                                                <th>Sales Value</th>
                                                <th>Status</th>
                                            </tr>
                                            <?php elseif ($report_type == 'stock_movement'): ?>
                                            <tr>
                                                <th>Date</th>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Type</th>
                                                <th>Quantity</th>
                                                <th>Unit Price</th>
                                                <th>Total Value</th>
                                                <th>Reference</th>
                                                <th>Created By</th>
                                            </tr>
                                            <?php elseif ($report_type == 'valuation'): ?>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Current Stock</th>
                                                <th>Cost Price</th>
                                                <th>Selling Price</th>
                                                <th>Total Cost</th>
                                                <th>Sales Value</th>
                                                <th>Potential Profit</th>
                                                <th>Margin %</th>
                                            </tr>
                                            <?php elseif ($report_type == 'slow_moving'): ?>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Current Stock</th>
                                                <th>Last 30 Days Sales</th>
                                                <th>Last 90 Days Sales</th>
                                                <th>Last Sale Date</th>
                                                <th>Days Since Last Sale</th>
                                                <th>Status</th>
                                            </tr>
                                            <?php elseif ($report_type == 'reorder'): ?>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Current Stock</th>
                                                <th>Reorder Level</th>
                                                <th>Qty to Order</th>
                                                <th>Est. Cost</th>
                                                <th>Avg Daily Sales</th>
                                                <th>Priority</th>
                                            </tr>
                                            <?php endif; ?>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No data available for this report</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($report_data as $row): ?>
                                                <tr>
                                                    <?php if ($report_type == 'current_stock'): ?>
                                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($row['unit']) ?></td>
                                                    <td class="font-weight-bold"><?= number_format($row['current_stock'], 2) ?></td>
                                                    <td><?= number_format($row['reorder_level'], 2) ?></td>
                                                    <td>₹<?= number_format($row['cost_price'], 2) ?></td>
                                                    <td>₹<?= number_format($row['selling_price'], 2) ?></td>
                                                    <td>₹<?= number_format($row['stock_value'], 2) ?></td>
                                                    <td>₹<?= number_format($row['sales_value'], 2) ?></td>
                                                    <td>
                                                        <?php if ($row['stock_status'] == 'Low Stock'): ?>
                                                            <span class="badge bg-soft-warning text-warning">Low Stock</span>
                                                        <?php elseif ($row['stock_status'] == 'Out of Stock'): ?>
                                                            <span class="badge bg-soft-danger text-danger">Out of Stock</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-success text-success">In Stock</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'stock_movement'): ?>
                                                    <td><?= date('d M Y', strtotime($row['movement_date'])) ?></td>
                                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <?php if ($row['transaction_type'] == 'in'): ?>
                                                            <span class="badge bg-soft-success text-success">Stock In</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-danger text-danger">Stock Out</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="<?= $row['transaction_type'] == 'in' ? 'text-success' : 'text-danger' ?>">
                                                        <?= $row['transaction_type'] == 'in' ? '+' : '-' ?><?= number_format($row['quantity'], 2) ?>
                                                    </td>
                                                    <td>₹<?= number_format($row['unit_price'] ?? 0, 2) ?></td>
                                                    <td>₹<?= number_format($row['total_value'] ?? 0, 2) ?></td>
                                                    <td>
                                                        <?php if ($row['reference_type']): ?>
                                                            <small><?= $row['reference_type'] ?> #<?= $row['reference_id'] ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">Manual</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><small><?= htmlspecialchars($row['created_by_name'] ?? 'System') ?></small></td>
                                                    
                                                    <?php elseif ($report_type == 'valuation'): ?>
                                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($row['unit']) ?></td>
                                                    <td><?= number_format($row['current_stock'], 2) ?></td>
                                                    <td>₹<?= number_format($row['cost_price'], 2) ?></td>
                                                    <td>₹<?= number_format($row['selling_price'], 2) ?></td>
                                                    <td>₹<?= number_format($row['total_cost'], 2) ?></td>
                                                    <td>₹<?= number_format($row['total_sales_value'], 2) ?></td>
                                                    <td class="text-success">₹<?= number_format($row['potential_profit'], 2) ?></td>
                                                    <td><?= number_format($row['profit_margin_percent'], 2) ?>%</td>
                                                    
                                                    <?php elseif ($report_type == 'slow_moving'): ?>
                                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($row['unit']) ?></td>
                                                    <td><?= number_format($row['current_stock'], 2) ?></td>
                                                    <td><?= number_format($row['last_30_days_sales'], 2) ?></td>
                                                    <td><?= number_format($row['last_90_days_sales'], 2) ?></td>
                                                    <td><?= $row['last_sale_date'] ? date('d M Y', strtotime($row['last_sale_date'])) : 'Never' ?></td>
                                                    <td>
                                                        <?php if ($row['days_since_last_sale']): ?>
                                                            <span class="<?= $row['days_since_last_sale'] > 90 ? 'text-danger' : 'text-warning' ?>">
                                                                <?= $row['days_since_last_sale'] ?> days
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['last_90_days_sales'] == 0): ?>
                                                            <span class="badge bg-soft-danger text-danger">Dead Stock</span>
                                                        <?php elseif ($row['last_90_days_sales'] < $row['current_stock']): ?>
                                                            <span class="badge bg-soft-warning text-warning">Slow Moving</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'reorder'): ?>
                                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($row['unit']) ?></td>
                                                    <td class="<?= $row['current_stock'] == 0 ? 'text-danger font-weight-bold' : '' ?>">
                                                        <?= number_format($row['current_stock'], 2) ?>
                                                    </td>
                                                    <td><?= number_format($row['reorder_level'], 2) ?></td>
                                                    <td class="font-weight-bold"><?= number_format($row['quantity_to_order'], 2) ?></td>
                                                    <td>₹<?= number_format($row['estimated_cost'] ?? 0, 2) ?></td>
                                                    <td><?= number_format($row['avg_daily_sales'], 2) ?></td>
                                                    <td>
                                                        <?php if ($row['current_stock'] == 0): ?>
                                                            <span class="badge bg-soft-danger text-danger">Critical</span>
                                                        <?php elseif ($row['quantity_to_order'] > 10): ?>
                                                            <span class="badge bg-soft-warning text-warning">High</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-info text-info">Normal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php endif; ?>
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
                <!-- end table -->

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

    <?php if (!empty($chart_data)): ?>
    // Report Chart
    var chartData = <?= json_encode($chart_data) ?>;
    
    <?php if ($report_type == 'current_stock'): ?>
    var options = {
        chart: {
            height: 350,
            type: 'pie'
        },
        series: chartData.map(item => parseFloat(item.category_value)),
        labels: chartData.map(item => item.category_name),
        colors: ['#556ee6', '#34c38f', '#f46a6a', '#50a5f1', '#f1b44c'],
        legend: {
            position: 'bottom'
        },
        responsive: [{
            breakpoint: 480,
            options: {
                chart: {
                    width: 200
                },
                legend: {
                    position: 'bottom'
                }
            }
        }]
    };
    <?php elseif ($report_type == 'stock_movement'): ?>
    var options = {
        chart: {
            height: 350,
            type: 'line',
            toolbar: {
                show: true
            }
        },
        series: [
            {
                name: 'Stock In',
                type: 'column',
                data: chartData.map(item => parseFloat(item.daily_in))
            },
            {
                name: 'Stock Out',
                type: 'column',
                data: chartData.map(item => parseFloat(item.daily_out))
            }
        ],
        stroke: {
            width: [0, 0],
            curve: 'smooth'
        },
        plotOptions: {
            bar: {
                columnWidth: '50%'
            }
        },
        fill: {
            opacity: [1, 1]
        },
        labels: chartData.map(item => {
            var date = new Date(item.mov_date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        colors: ['#34c38f', '#f46a6a'],
        xaxis: {
            type: 'category',
            tickAmount: 10
        },
        yaxis: {
            title: {
                text: 'Stock Quantity'
            }
        },
        tooltip: {
            shared: true,
            intersect: false
        }
    };
    <?php elseif ($report_type == 'valuation'): ?>
    var options = {
        chart: {
            height: 350,
            type: 'bar',
            stacked: true
        },
        series: [
            {
                name: 'Cost Value',
                data: chartData.map(item => parseFloat(item.category_cost))
            },
            {
                name: 'Sales Value',
                data: chartData.map(item => parseFloat(item.category_sales))
            }
        ],
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '50%',
            }
        },
        xaxis: {
            categories: chartData.map(item => item.category_name)
        },
        colors: ['#556ee6', '#34c38f'],
        legend: {
            position: 'top'
        }
    };
    <?php endif; ?>

    var chart = new ApexCharts(document.querySelector("#report-chart"), options);
    chart.render();
    <?php endif; ?>
</script>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, .apex-charts, 
    .page-title-right, .card-title .btn, .action-buttons {
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
        font-size: 10pt;
    }
}
</style>

</body>
</html>