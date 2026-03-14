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
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get filter parameters
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : date('Y-m-01'); // First day of current month
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : date('Y-m-d');
$filter_product = isset($_GET['filter_product']) ? $_GET['filter_product'] : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_reference = isset($_GET['filter_reference']) ? $_GET['filter_reference'] : '';

// Get all products for dropdown
try {
    $productsStmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    $products = $productsStmt->fetchAll();
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Get stock transactions with pagination
try {
    // Base query
    $query = "
        SELECT st.*, 
               p.name as product_name, 
               p.unit,
               p.category_id,
               c.name as category_name,
               u.full_name as created_by_name
        FROM stock_transactions st
        JOIN products p ON st.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON st.created_by = u.id
        WHERE 1=1
    ";
    
    $count_query = "
        SELECT COUNT(*) as total 
        FROM stock_transactions st
        JOIN products p ON st.product_id = p.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply date filters
    if (!empty($filter_date_from)) {
        $query .= " AND DATE(st.transaction_date) >= :date_from";
        $count_query .= " AND DATE(st.transaction_date) >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND DATE(st.transaction_date) <= :date_to";
        $count_query .= " AND DATE(st.transaction_date) <= :date_to";
        $params[':date_to'] = $filter_date_to;
    }
    
    // Apply product filter
    if (!empty($filter_product)) {
        $query .= " AND st.product_id = :product_id";
        $count_query .= " AND st.product_id = :product_id";
        $params[':product_id'] = $filter_product;
    }
    
    // Apply transaction type filter
    if (!empty($filter_type)) {
        $query .= " AND st.transaction_type = :transaction_type";
        $count_query .= " AND st.transaction_type = :transaction_type";
        $params[':transaction_type'] = $filter_type;
    }
    
    // Apply reference filter
    if (!empty($filter_reference)) {
        $query .= " AND (st.reference_type LIKE :reference OR st.reference_id LIKE :reference_id)";
        $count_query .= " AND (st.reference_type LIKE :reference OR st.reference_id LIKE :reference_id)";
        $params[':reference'] = '%' . $filter_reference . '%';
        $params[':reference_id'] = '%' . $filter_reference . '%';
    }
    
    // Get total records for pagination
    $countStmt = $pdo->prepare($count_query);
    $countStmt->execute($params);
    $total_records = $countStmt->fetch()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    
    // Add ordering and pagination
    $query .= " ORDER BY st.transaction_date DESC, st.created_at DESC LIMIT :offset, :limit";
    
    $stmt = $pdo->prepare($query);
    
    // Bind parameters for pagination
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    
    $stmt->execute();
    $transactions = $stmt->fetchAll();
    
    // Get summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END) as total_stock_in,
            SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END) as total_stock_out,
            SUM(CASE WHEN transaction_type = 'in' THEN total_value ELSE 0 END) as total_value_in,
            SUM(CASE WHEN transaction_type = 'out' THEN total_value ELSE 0 END) as total_value_out,
            COUNT(DISTINCT product_id) as products_affected
        FROM stock_transactions st
        WHERE 1=1
    ";
    
    $summary_params = [];
    if (!empty($filter_date_from)) {
        $summary_query .= " AND DATE(transaction_date) >= :date_from";
        $summary_params[':date_from'] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $summary_query .= " AND DATE(transaction_date) <= :date_to";
        $summary_params[':date_to'] = $filter_date_to;
    }
    if (!empty($filter_product)) {
        $summary_query .= " AND product_id = :product_id";
        $summary_params[':product_id'] = $filter_product;
    }
    
    $summaryStmt = $pdo->prepare($summary_query);
    $summaryStmt->execute($summary_params);
    $summary = $summaryStmt->fetch();
    
    // Get transaction trends for chart
    $trends_query = "
        SELECT 
            DATE(transaction_date) as trans_date,
            SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END) as daily_in,
            SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END) as daily_out,
            COUNT(*) as transaction_count
        FROM stock_transactions
        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(transaction_date)
        ORDER BY trans_date ASC
    ";
    $trendsStmt = $pdo->query($trends_query);
    $trends = $trendsStmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Stock transactions error: " . $e->getMessage());
    $transactions = [];
    $total_records = 0;
    $total_pages = 0;
    $summary = [
        'total_transactions' => 0,
        'total_stock_in' => 0,
        'total_stock_out' => 0,
        'total_value_in' => 0,
        'total_value_out' => 0,
        'products_affected' => 0
    ];
    $trends = [];
}

// Handle AJAX request for transaction details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT st.*, 
                   p.name as product_name, 
                   p.unit,
                   p.category_id,
                   c.name as category_name,
                   u1.full_name as created_by_name,
                   u2.full_name as updated_by_name
            FROM stock_transactions st
            JOIN products p ON st.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN users u1 ON st.created_by = u1.id
            LEFT JOIN users u2 ON st.updated_by = u2.id
            WHERE st.id = :id
        ");
        $stmt->execute([':id' => $_GET['id']]);
        $details = $stmt->fetch();
        
        if ($details) {
            // Get reference details if available
            if ($details['reference_type'] && $details['reference_id']) {
                switch ($details['reference_type']) {
                    case 'invoice':
                        $refStmt = $pdo->prepare("
                            SELECT i.invoice_number, i.invoice_date, c.name as customer_name
                            FROM invoices i
                            LEFT JOIN customers c ON i.customer_id = c.id
                            WHERE i.id = :id
                        ");
                        break;
                    case 'purchase_order':
                        $refStmt = $pdo->prepare("
                            SELECT po.po_number, po.order_date, s.name as supplier_name
                            FROM purchase_orders po
                            LEFT JOIN suppliers s ON po.supplier_id = s.id
                            WHERE po.id = :id
                        ");
                        break;
                    default:
                        $refStmt = null;
                }
                
                if ($refStmt) {
                    $refStmt->execute([':id' => $details['reference_id']]);
                    $details['reference_details'] = $refStmt->fetch();
                }
            }
            
            echo json_encode(['success' => true, 'data' => $details]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle form submission for adding manual transaction
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_transaction') {
        try {
            $pdo->beginTransaction();
            
            $transaction_date = $_POST['transaction_date'];
            $product_id = $_POST['product_id'];
            $transaction_type = $_POST['transaction_type'];
            $quantity = floatval($_POST['quantity']);
            $unit_price = !empty($_POST['unit_price']) ? floatval($_POST['unit_price']) : null;
            $notes = $_POST['notes'] ?? null;
            $reference_type = !empty($_POST['reference_type']) ? $_POST['reference_type'] : null;
            $reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
            
            // Calculate total value
            $total_value = $unit_price ? $quantity * $unit_price : null;
            
            // Insert transaction
            $insertStmt = $pdo->prepare("
                INSERT INTO stock_transactions 
                (transaction_date, product_id, transaction_type, quantity, reference_type, 
                 reference_id, unit_price, total_value, notes, created_by, created_at) 
                VALUES 
                (:transaction_date, :product_id, :transaction_type, :quantity, :reference_type,
                 :reference_id, :unit_price, :total_value, :notes, :created_by, NOW())
            ");
            
            $insertStmt->execute([
                ':transaction_date' => $transaction_date,
                ':product_id' => $product_id,
                ':transaction_type' => $transaction_type,
                ':quantity' => $quantity,
                ':reference_type' => $reference_type,
                ':reference_id' => $reference_id,
                ':unit_price' => $unit_price,
                ':total_value' => $total_value,
                ':notes' => $notes,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $transaction_id = $pdo->lastInsertId();
            
            // Update product current stock
            if ($transaction_type === 'in') {
                $updateStmt = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock + :quantity,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :product_id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock - :quantity,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :product_id
                ");
            }
            
            $updateStmt->execute([
                ':quantity' => $quantity,
                ':updated_by' => $_SESSION['user_id'] ?? null,
                ':product_id' => $product_id
            ]);
            
            // Update or create daywise stock record
            $daywiseCheck = $pdo->prepare("
                SELECT id FROM daywise_stock 
                WHERE stock_date = :stock_date AND product_id = :product_id
            ");
            $daywiseCheck->execute([
                ':stock_date' => $transaction_date,
                ':product_id' => $product_id
            ]);
            $daywiseExists = $daywiseCheck->fetch();
            
            if ($daywiseExists) {
                // Update existing daywise record
                if ($transaction_type === 'in') {
                    $daywiseStmt = $pdo->prepare("
                        UPDATE daywise_stock 
                        SET stock_in = stock_in + :quantity,
                            closing_stock = opening_stock + (stock_in + :quantity) - stock_out,
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE id = :id
                    ");
                } else {
                    $daywiseStmt = $pdo->prepare("
                        UPDATE daywise_stock 
                        SET stock_out = stock_out + :quantity,
                            closing_stock = opening_stock + stock_in - (stock_out + :quantity),
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE id = :id
                    ");
                }
                $daywiseStmt->execute([
                    ':quantity' => $quantity,
                    ':updated_by' => $_SESSION['user_id'] ?? null,
                    ':id' => $daywiseExists['id']
                ]);
            } else {
                // Create new daywise record
                // Get current stock as opening
                $productStmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = :id");
                $productStmt->execute([':id' => $product_id]);
                $current_stock = $productStmt->fetch()['current_stock'];
                
                $opening_stock = $current_stock - ($transaction_type === 'in' ? $quantity : 0) + ($transaction_type === 'out' ? $quantity : 0);
                
                $daywiseInsert = $pdo->prepare("
                    INSERT INTO daywise_stock 
                    (stock_date, product_id, opening_stock, stock_in, stock_out, closing_stock, created_by, created_at)
                    VALUES
                    (:stock_date, :product_id, :opening_stock, :stock_in, :stock_out, :closing_stock, :created_by, NOW())
                ");
                
                $stock_in = $transaction_type === 'in' ? $quantity : 0;
                $stock_out = $transaction_type === 'out' ? $quantity : 0;
                $closing_stock = $opening_stock + $stock_in - $stock_out;
                
                $daywiseInsert->execute([
                    ':stock_date' => $transaction_date,
                    ':product_id' => $product_id,
                    ':opening_stock' => $opening_stock,
                    ':stock_in' => $stock_in,
                    ':stock_out' => $stock_out,
                    ':closing_stock' => $closing_stock,
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 3, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "Manual stock transaction added: " . $transaction_type . " for product ID: " . $product_id,
                ':activity_data' => json_encode([
                    'transaction_id' => $transaction_id,
                    'transaction_date' => $transaction_date,
                    'product_id' => $product_id,
                    'type' => $transaction_type,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            $message = "Stock transaction added successfully!";
            $messageType = "success";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            error_log("Add transaction error: " . $e->getMessage());
        }
    }
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
                            <h4 class="mb-0 font-size-18">Stock Transactions</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Inventory</a></li>
                                    <li class="breadcrumb-item active">Stock Transactions</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Alert Message -->
                <?php if (!empty($message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($message) ?>
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
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                                        <i class="mdi mdi-plus"></i> Add Manual Transaction
                                    </button>
                                    <button type="button" class="btn btn-success" onclick="exportTransactions()">
                                        <i class="mdi mdi-export"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="printReport()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Transactions</h4>
                                <form method="GET" action="stock-transactions.php" class="row">
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_product" class="form-label">Product</label>
                                            <select class="form-control" id="filter_product" name="filter_product">
                                                <option value="">All Products</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?= $product['id'] ?>" <?= $filter_product == $product['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($product['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_type" class="form-label">Transaction Type</label>
                                            <select class="form-control" id="filter_type" name="filter_type">
                                                <option value="">All Types</option>
                                                <option value="in" <?= $filter_type == 'in' ? 'selected' : '' ?>>Stock In</option>
                                                <option value="out" <?= $filter_type == 'out' ? 'selected' : '' ?>>Stock Out</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_reference" class="form-label">Reference</label>
                                            <input type="text" class="form-control" id="filter_reference" name="filter_reference" placeholder="Invoice/PO #" value="<?= htmlspecialchars($filter_reference) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-1">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="mdi mdi-filter"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-counter font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Transactions</p>
                                        <h4><?= number_format($summary['total_transactions'] ?? 0) ?></h4>
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
                                                <i class="mdi mdi-arrow-down-bold font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Stock In</p>
                                        <h4><?= number_format($summary['total_stock_in'] ?? 0, 2) ?></h4>
                                        <small class="text-success">₹<?= number_format($summary['total_value_in'] ?? 0, 2) ?></small>
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
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="mdi mdi-arrow-up-bold font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Stock Out</p>
                                        <h4><?= number_format($summary['total_stock_out'] ?? 0, 2) ?></h4>
                                        <small class="text-danger">₹<?= number_format($summary['total_value_out'] ?? 0, 2) ?></small>
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
                                                <i class="mdi mdi-package-variant font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Products Affected</p>
                                        <h4><?= number_format($summary['products_affected'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end summary cards -->

                <!-- Transactions Chart -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Transaction Trends (Last 30 Days)</h4>
                                <div id="transactions-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end chart -->

                <!-- Transactions Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Transaction History</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <form method="GET" action="stock-transactions.php" class="d-inline-block">
                                                <select name="per_page" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                    <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                                    <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20 per page</option>
                                                    <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                                    <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                                </select>
                                                <?php foreach ($_GET as $key => $value): ?>
                                                    <?php if ($key != 'per_page' && $key != 'page'): ?>
                                                    <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
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
                                                <th>Date & Time</th>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Type</th>
                                                <th>Quantity</th>
                                                <th>Unit</th>
                                                <th>Reference</th>
                                                <th>Unit Price</th>
                                                <th>Total Value</th>
                                                <th>Created By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($transactions)): ?>
                                                <tr>
                                                    <td colspan="11" class="text-center text-muted py-4">
                                                        <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                        <p class="mt-2">No transactions found for selected filters</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($transactions as $transaction): ?>
                                                <tr>
                                                    <td>
                                                        <div><?= date('d M Y', strtotime($transaction['transaction_date'])) ?></div>
                                                        <small class="text-muted"><?= date('h:i A', strtotime($transaction['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <h5 class="font-size-14 mb-1"><?= htmlspecialchars($transaction['product_name']) ?></h5>
                                                    </td>
                                                    <td><?= htmlspecialchars($transaction['category_name'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <?php if ($transaction['transaction_type'] === 'in'): ?>
                                                            <span class="badge bg-soft-success text-success">Stock In</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-danger text-danger">Stock Out</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="<?= $transaction['transaction_type'] === 'in' ? 'text-success' : 'text-danger' ?> font-weight-bold">
                                                        <?= $transaction['transaction_type'] === 'in' ? '+' : '-' ?><?= number_format($transaction['quantity'], 2) ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($transaction['unit']) ?></td>
                                                    <td>
                                                        <?php if ($transaction['reference_type'] && $transaction['reference_id']): ?>
                                                            <span class="badge bg-soft-info text-info">
                                                                <?= htmlspecialchars($transaction['reference_type']) ?> #<?= $transaction['reference_id'] ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Manual</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($transaction['unit_price']): ?>
                                                            ₹<?= number_format($transaction['unit_price'], 2) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($transaction['total_value']): ?>
                                                            ₹<?= number_format($transaction['total_value'], 2) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($transaction['created_by_name'] ?? 'System') ?></small>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-soft-primary" onclick="viewTransaction(<?= $transaction['id'] ?>)">
                                                            <i class="mdi mdi-eye"></i>
                                                        </button>
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

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTransactionModalLabel">Add Manual Stock Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="stock-transactions.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_transaction">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="transaction_date" class="form-label">Transaction Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="transaction_type" class="form-label">Transaction Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="transaction_type" name="transaction_type" required>
                                    <option value="">Select Type</option>
                                    <option value="in">Stock In (Purchase/Receipt)</option>
                                    <option value="out">Stock Out (Sale/Issue)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Product <span class="text-danger">*</span></label>
                                <select class="form-control" id="product_id" name="product_id" required>
                                    <option value="">Select Product</option>
                                    <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="quantity" name="quantity" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="unit_price" class="form-label">Unit Price (Optional)</label>
                                <input type="number" step="0.01" class="form-control" id="unit_price" name="unit_price">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="total_value" class="form-label">Total Value (Auto-calculated)</label>
                                <input type="number" step="0.01" class="form-control" id="total_value" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="reference_type" class="form-label">Reference Type</label>
                                <select class="form-control" id="reference_type" name="reference_type">
                                    <option value="">None (Manual)</option>
                                    <option value="invoice">Invoice</option>
                                    <option value="purchase_order">Purchase Order</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="reference_id" class="form-label">Reference ID/Number</label>
                                <input type="text" class="form-control" id="reference_id" name="reference_id" placeholder="Enter ID or number">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="modal fade" id="viewTransactionModal" tabindex="-1" aria-labelledby="viewTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTransactionModalLabel">Transaction Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="transactionDetails">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

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

    // Auto-calculate total value
    document.getElementById('quantity').addEventListener('input', calculateTotal);
    document.getElementById('unit_price').addEventListener('input', calculateTotal);

    function calculateTotal() {
        var quantity = parseFloat(document.getElementById('quantity').value) || 0;
        var unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
        var total = quantity * unitPrice;
        document.getElementById('total_value').value = total.toFixed(2);
    }

    // View transaction details
    function viewTransaction(id) {
        var modal = new bootstrap.Modal(document.getElementById('viewTransactionModal'));
        modal.show();
        
        document.getElementById('transactionDetails').innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
        
        fetch('stock-transactions.php?ajax=get_details&id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayTransactionDetails(data.data);
                } else {
                    document.getElementById('transactionDetails').innerHTML = '<div class="alert alert-danger">Error loading transaction details</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('transactionDetails').innerHTML = '<div class="alert alert-danger">Error loading transaction details</div>';
            });
    }

    function displayTransactionDetails(transaction) {
        var html = `
            <div class="table-responsive">
                <table class="table table-bordered">
                    <tr>
                        <th width="40%">Transaction ID</th>
                        <td>#${transaction.id}</td>
                    </tr>
                    <tr>
                        <th>Date & Time</th>
                        <td>${new Date(transaction.created_at).toLocaleString()}</td>
                    </tr>
                    <tr>
                        <th>Transaction Date</th>
                        <td>${new Date(transaction.transaction_date).toLocaleDateString()}</td>
                    </tr>
                    <tr>
                        <th>Product</th>
                        <td>${transaction.product_name} (${transaction.category_name || 'N/A'})</td>
                    </tr>
                    <tr>
                        <th>Transaction Type</th>
                        <td>
                            ${transaction.transaction_type === 'in' ? 
                                '<span class="badge bg-success">Stock In</span>' : 
                                '<span class="badge bg-danger">Stock Out</span>'}
                        </td>
                    </tr>
                    <tr>
                        <th>Quantity</th>
                        <td class="${transaction.transaction_type === 'in' ? 'text-success' : 'text-danger'} font-weight-bold">
                            ${transaction.transaction_type === 'in' ? '+' : '-'}${transaction.quantity} ${transaction.unit}
                        </td>
                    </tr>
        `;
        
        if (transaction.unit_price) {
            html += `
                    <tr>
                        <th>Unit Price</th>
                        <td>₹${transaction.unit_price.toFixed(2)}</td>
                    </tr>
            `;
        }
        
        if (transaction.total_value) {
            html += `
                    <tr>
                        <th>Total Value</th>
                        <td>₹${transaction.total_value.toFixed(2)}</td>
                    </tr>
            `;
        }
        
        if (transaction.reference_type && transaction.reference_id) {
            html += `
                    <tr>
                        <th>Reference</th>
                        <td>
                            <span class="badge bg-info">${transaction.reference_type} #${transaction.reference_id}</span>
            `;
            
            if (transaction.reference_details) {
                if (transaction.reference_type === 'invoice') {
                    html += `<br><small>Invoice: ${transaction.reference_details.invoice_number}<br>Customer: ${transaction.reference_details.customer_name}</small>`;
                } else if (transaction.reference_type === 'purchase_order') {
                    html += `<br><small>PO: ${transaction.reference_details.po_number}<br>Supplier: ${transaction.reference_details.supplier_name}</small>`;
                }
            }
            
            html += `
                        </td>
                    </tr>
            `;
        }
        
        if (transaction.notes) {
            html += `
                    <tr>
                        <th>Notes</th>
                        <td>${transaction.notes}</td>
                    </tr>
            `;
        }
        
        html += `
                    <tr>
                        <th>Created By</th>
                        <td>${transaction.created_by_name || 'System'}</td>
                    </tr>
        `;
        
        if (transaction.updated_by_name) {
            html += `
                    <tr>
                        <th>Last Updated</th>
                        <td>${new Date(transaction.updated_at).toLocaleString()} by ${transaction.updated_by_name}</td>
                    </tr>
            `;
        }
        
        html += `
                </table>
            </div>
        `;
        
        document.getElementById('transactionDetails').innerHTML = html;
    }

    // Transactions Chart
    <?php if (!empty($trends)): ?>
    var trends = <?= json_encode($trends) ?>;
    
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
                data: trends.map(item => parseFloat(item.daily_in))
            },
            {
                name: 'Stock Out',
                type: 'column',
                data: trends.map(item => parseFloat(item.daily_out))
            },
            {
                name: 'Transaction Count',
                type: 'line',
                data: trends.map(item => parseInt(item.transaction_count))
            }
        ],
        stroke: {
            width: [0, 0, 3],
            curve: 'smooth'
        },
        plotOptions: {
            bar: {
                columnWidth: '50%'
            }
        },
        fill: {
            opacity: [1, 1, 1]
        },
        labels: trends.map(item => {
            var date = new Date(item.trans_date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        colors: ['#34c38f', '#f46a6a', '#556ee6'],
        xaxis: {
            type: 'category',
            tickAmount: 10
        },
        yaxis: [
            {
                title: {
                    text: 'Stock Quantity'
                }
            },
            {
                opposite: true,
                title: {
                    text: 'Transaction Count'
                }
            }
        ],
        tooltip: {
            shared: true,
            intersect: false
        }
    };

    var chart = new ApexCharts(document.querySelector("#transactions-chart"), options);
    chart.render();
    <?php endif; ?>

    // Export transactions
    function exportTransactions() {
        var params = new URLSearchParams(window.location.search);
        window.location.href = 'export-stock-transactions.php?' + params.toString();
    }

    // Print report
    function printReport() {
        window.print();
    }
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'stock-transactions.php?' . http_build_query($params);
}
?>

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
}
</style>

</body>
</html>