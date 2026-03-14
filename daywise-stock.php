<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get filter parameters
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$filter_product = isset($_GET['filter_product']) ? $_GET['filter_product'] : '';
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';

// Get all products for dropdown
try {
    $productsStmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    $products = $productsStmt->fetchAll();
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Get all categories for dropdown
try {
    $categoriesStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $categoriesStmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get day-wise stock data
try {
    $query = "
        SELECT ds.*, p.name as product_name, p.unit, c.name as category_name,
               p.current_stock as current_inventory_stock
        FROM daywise_stock ds
        JOIN products p ON ds.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filter_date)) {
        $query .= " AND ds.stock_date = :stock_date";
        $params[':stock_date'] = $filter_date;
    }
    
    if (!empty($filter_product)) {
        $query .= " AND ds.product_id = :product_id";
        $params[':product_id'] = $filter_product;
    }
    
    if (!empty($filter_category)) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $filter_category;
    }
    
    $query .= " ORDER BY ds.stock_date DESC, p.name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $daywiseStocks = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT product_id) as total_products,
            SUM(opening_stock) as total_opening,
            SUM(stock_in) as total_stock_in,
            SUM(stock_out) as total_stock_out,
            SUM(closing_stock) as total_closing
        FROM daywise_stock 
        WHERE stock_date = :stock_date
    ");
    $summaryStmt->execute([':stock_date' => $filter_date]);
    $summary = $summaryStmt->fetch();
    
    // Get stock transaction history for the selected date
    $transactionsStmt = $pdo->prepare("
        SELECT st.*, p.name as product_name, p.unit
        FROM stock_transactions st
        JOIN products p ON st.product_id = p.id
        WHERE DATE(st.transaction_date) = :transaction_date
        ORDER BY st.created_at DESC
        LIMIT 20
    ");
    $transactionsStmt->execute([':transaction_date' => $filter_date]);
    $recentTransactions = $transactionsStmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Daywise stock error: " . $e->getMessage());
    $daywiseStocks = [];
    $summary = ['total_products' => 0, 'total_opening' => 0, 'total_stock_in' => 0, 'total_stock_out' => 0, 'total_closing' => 0];
    $recentTransactions = [];
}

// Handle form submission for adding/updating daywise stock
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
                $stock_date = $_POST['stock_date'];
                $product_id = $_POST['product_id'];
                $opening_stock = floatval($_POST['opening_stock']);
                $stock_in = floatval($_POST['stock_in'] ?? 0);
                $stock_out = floatval($_POST['stock_out'] ?? 0);
                $closing_stock = $opening_stock + $stock_in - $stock_out;
                
                // Check if record exists
                $checkStmt = $pdo->prepare("SELECT id FROM daywise_stock WHERE stock_date = :stock_date AND product_id = :product_id");
                $checkStmt->execute([':stock_date' => $stock_date, ':product_id' => $product_id]);
                $existing = $checkStmt->fetch();
                
                if ($existing && $_POST['action'] === 'add') {
                    $message = "Stock record for this date and product already exists!";
                    $messageType = "danger";
                } else {
                    if ($existing) {
                        // Update existing
                        $updateStmt = $pdo->prepare("
                            UPDATE daywise_stock 
                            SET opening_stock = :opening_stock, 
                                stock_in = :stock_in, 
                                stock_out = :stock_out, 
                                closing_stock = :closing_stock,
                                updated_at = NOW(),
                                updated_by = :updated_by
                            WHERE id = :id
                        ");
                        $updateStmt->execute([
                            ':opening_stock' => $opening_stock,
                            ':stock_in' => $stock_in,
                            ':stock_out' => $stock_out,
                            ':closing_stock' => $closing_stock,
                            ':updated_by' => $_SESSION['user_id'] ?? null,
                            ':id' => $existing['id']
                        ]);
                        $message = "Stock record updated successfully!";
                        $messageType = "success";
                    } else {
                        // Insert new
                        $insertStmt = $pdo->prepare("
                            INSERT INTO daywise_stock 
                            (stock_date, product_id, opening_stock, stock_in, stock_out, closing_stock, created_by) 
                            VALUES 
                            (:stock_date, :product_id, :opening_stock, :stock_in, :stock_out, :closing_stock, :created_by)
                        ");
                        $insertStmt->execute([
                            ':stock_date' => $stock_date,
                            ':product_id' => $product_id,
                            ':opening_stock' => $opening_stock,
                            ':stock_in' => $stock_in,
                            ':stock_out' => $stock_out,
                            ':closing_stock' => $closing_stock,
                            ':created_by' => $_SESSION['user_id'] ?? null
                        ]);
                        $message = "Stock record added successfully!";
                        $messageType = "success";
                    }
                    
                    // Log activity
                    $logStmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                        VALUES (:user_id, 3, :description, :activity_data, :created_by)
                    ");
                    $logStmt->execute([
                        ':user_id' => $_SESSION['user_id'] ?? null,
                        ':description' => "Daywise stock " . ($existing ? 'updated' : 'added') . " for product ID: " . $product_id,
                        ':activity_data' => json_encode([
                            'stock_date' => $stock_date,
                            'product_id' => $product_id,
                            'opening_stock' => $opening_stock,
                            'stock_in' => $stock_in,
                            'stock_out' => $stock_out,
                            'closing_stock' => $closing_stock
                        ]),
                        ':created_by' => $_SESSION['user_id'] ?? null
                    ]);
                    
                    // Refresh the page to show updated data
                    header("Location: daywise-stock.php?filter_date=" . urlencode($stock_date) . "&message=" . urlencode($message) . "&message_type=success");
                    exit();
                }
            } elseif ($_POST['action'] === 'delete' && isset($_POST['record_id'])) {
                // Delete record
                $deleteStmt = $pdo->prepare("DELETE FROM daywise_stock WHERE id = :id");
                $deleteStmt->execute([':id' => $_POST['record_id']]);
                
                $message = "Stock record deleted successfully!";
                $messageType = "success";
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, created_by)
                    VALUES (:user_id, 5, :description, :created_by)
                ");
                $logStmt->execute([
                    ':user_id' => $_SESSION['user_id'] ?? null,
                    ':description' => "Daywise stock deleted ID: " . $_POST['record_id'],
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            error_log("Daywise stock operation error: " . $e->getMessage());
        }
    }
}

// Check for message from redirect
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['message_type'] ?? 'info';
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
                            <h4 class="mb-0 font-size-18">Day-wise Stock Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Inventory</a></li>
                                    <li class="breadcrumb-item active">Day-wise Stock</li>
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

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Stock Records</h4>
                                <form method="GET" action="daywise-stock.php" class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_date" class="form-label">Date</label>
                                            <input type="date" class="form-control" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
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
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_category" class="form-label">Category</label>
                                            <select class="form-control" id="filter_category" name="filter_category">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['id'] ?>" <?= $filter_category == $category['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($category['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply Filter
                                                </button>
                                                <a href="daywise-stock.php" class="btn btn-secondary">
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
                                                <i class="mdi mdi-package-variant font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Products</p>
                                        <h4><?= number_format($summary['total_products'] ?? 0) ?></h4>
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
                                                <i class="mdi mdi-arrow-up-bold-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Stock In</p>
                                        <h4><?= number_format($summary['total_stock_in'] ?? 0, 2) ?></h4>
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
                                                <i class="mdi mdi-arrow-down-bold-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Stock Out</p>
                                        <h4><?= number_format($summary['total_stock_out'] ?? 0, 2) ?></h4>
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
                                                <i class="mdi mdi-format-list-bulleted font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Closing Stock</p>
                                        <h4><?= number_format($summary['total_closing'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end summary cards -->

                <!-- Add Stock Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Add/Update Daily Stock</h4>
                                <form method="POST" action="daywise-stock.php" class="row">
                                    <input type="hidden" name="action" value="add">
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="stock_date" class="form-label">Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="stock_date" name="stock_date" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
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
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="opening_stock" class="form-label">Opening Stock <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" class="form-control" id="opening_stock" name="opening_stock" required>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="stock_in" class="form-label">Stock In</label>
                                            <input type="number" step="0.01" class="form-control" id="stock_in" name="stock_in" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="stock_out" class="form-label">Stock Out</label>
                                            <input type="number" step="0.01" class="form-control" id="stock_out" name="stock_out" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-1">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="mdi mdi-plus"></i> Add
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end add stock form -->

                <!-- Day-wise Stock Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Stock Records for <?= date('d M Y', strtotime($filter_date)) ?></h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Opening Stock</th>
                                                <th>Stock In</th>
                                                <th>Stock Out</th>
                                                <th>Closing Stock</th>
                                                <th>Current Inventory</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($daywiseStocks)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                        <p class="mt-2">No stock records found for selected filters</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($daywiseStocks as $stock): ?>
                                                <tr>
                                                    <td>
                                                        <h5 class="font-size-14 mb-1"><?= htmlspecialchars($stock['product_name']) ?></h5>
                                                    </td>
                                                    <td><?= htmlspecialchars($stock['category_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($stock['unit']) ?></td>
                                                    <td class="font-weight-bold"><?= number_format($stock['opening_stock'], 2) ?></td>
                                                    <td class="text-success font-weight-bold">+<?= number_format($stock['stock_in'], 2) ?></td>
                                                    <td class="text-danger font-weight-bold">-<?= number_format($stock['stock_out'], 2) ?></td>
                                                    <td class="font-weight-bold"><?= number_format($stock['closing_stock'], 2) ?></td>
                                                    <td><?= number_format($stock['current_inventory_stock'] ?? 0, 2) ?></td>
                                                    <td>
                                                        <?php
                                                        $stockDiff = ($stock['current_inventory_stock'] ?? 0) - $stock['closing_stock'];
                                                        if (abs($stockDiff) < 0.01):
                                                        ?>
                                                            <span class="badge bg-soft-success text-success">Matched</span>
                                                        <?php elseif ($stockDiff > 0): ?>
                                                            <span class="badge bg-soft-warning text-warning">Extra +<?= number_format($stockDiff, 2) ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-danger text-danger">Short <?= number_format(abs($stockDiff), 2) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-soft-primary" onclick="editStock(<?= htmlspecialchars(json_encode($stock)) ?>)">
                                                            <i class="mdi mdi-pencil"></i>
                                                        </button>
                                                        <form method="POST" action="daywise-stock.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="record_id" value="<?= $stock['id'] ?>">
                                                            <input type="hidden" name="filter_date" value="<?= $filter_date ?>">
                                                            <button type="submit" class="btn btn-sm btn-soft-danger">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
                                                        </form>
                                                    </td>
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

                <!-- Recent Stock Transactions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Stock Transactions (<?= date('d M Y', strtotime($filter_date)) ?>)</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Time</th>
                                                <th>Product</th>
                                                <th>Type</th>
                                                <th>Quantity</th>
                                                <th>Unit</th>
                                                <th>Reference</th>
                                                <th>Unit Price</th>
                                                <th>Total Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentTransactions)): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted">No transactions found for this date</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentTransactions as $transaction): ?>
                                                <tr>
                                                    <td><?= date('h:i A', strtotime($transaction['created_at'])) ?></td>
                                                    <td><?= htmlspecialchars($transaction['product_name']) ?></td>
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
                                                        <?php 
                                                        if ($transaction['reference_type']) {
                                                            echo htmlspecialchars($transaction['reference_type'] . ' #' . $transaction['reference_id']);
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>₹<?= number_format($transaction['unit_price'] ?? 0, 2) ?></td>
                                                    <td>₹<?= number_format($transaction['total_value'] ?? 0, 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="stock-transactions.php" class="text-primary">View all transactions <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end recent transactions -->

                <!-- Stock Summary Chart -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Stock Movement Summary</h4>
                                <div id="stock-movement-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end chart -->

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Edit Stock Modal -->
<div class="modal fade" id="editStockModal" tabindex="-1" aria-labelledby="editStockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editStockModalLabel">Edit Stock Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="daywise-stock.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="record_id" id="edit_record_id">
                    
                    <div class="mb-3">
                        <label for="edit_stock_date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="edit_stock_date" name="stock_date" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_product_name" class="form-label">Product</label>
                        <input type="text" class="form-control" id="edit_product_name" readonly>
                        <input type="hidden" name="product_id" id="edit_product_id">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_opening_stock" class="form-label">Opening Stock <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" id="edit_opening_stock" name="opening_stock" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_stock_in" class="form-label">Stock In</label>
                        <input type="number" step="0.01" class="form-control" id="edit_stock_in" name="stock_in" value="0">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_stock_out" class="form-label">Stock Out</label>
                        <input type="number" step="0.01" class="form-control" id="edit_stock_out" name="stock_out" value="0">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_closing_stock" class="form-label">Closing Stock (Auto-calculated)</label>
                        <input type="number" step="0.01" class="form-control" id="edit_closing_stock" readonly>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Record</button>
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

<!-- Chart JS -->
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Edit stock function
    function editStock(stockData) {
        document.getElementById('edit_record_id').value = stockData.id;
        document.getElementById('edit_stock_date').value = stockData.stock_date;
        document.getElementById('edit_product_name').value = stockData.product_name;
        document.getElementById('edit_product_id').value = stockData.product_id;
        document.getElementById('edit_opening_stock').value = stockData.opening_stock;
        document.getElementById('edit_stock_in').value = stockData.stock_in;
        document.getElementById('edit_stock_out').value = stockData.stock_out;
        document.getElementById('edit_closing_stock').value = stockData.closing_stock;
        
        var modal = new bootstrap.Modal(document.getElementById('editStockModal'));
        modal.show();
    }

    // Auto-calculate closing stock in edit modal
    document.getElementById('edit_opening_stock').addEventListener('input', calculateClosing);
    document.getElementById('edit_stock_in').addEventListener('input', calculateClosing);
    document.getElementById('edit_stock_out').addEventListener('input', calculateClosing);

    function calculateClosing() {
        var opening = parseFloat(document.getElementById('edit_opening_stock').value) || 0;
        var stockIn = parseFloat(document.getElementById('edit_stock_in').value) || 0;
        var stockOut = parseFloat(document.getElementById('edit_stock_out').value) || 0;
        var closing = opening + stockIn - stockOut;
        document.getElementById('edit_closing_stock').value = closing.toFixed(2);
    }

    // Stock Movement Chart
    <?php if (!empty($daywiseStocks)): ?>
    var stockData = <?= json_encode(array_slice($daywiseStocks, 0, 10)) ?>;
    
    var options = {
        chart: {
            height: 350,
            type: 'bar',
            stacked: true,
            toolbar: {
                show: true
            }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '50%',
            },
        },
        dataLabels: {
            enabled: false
        },
        series: [
            {
                name: 'Opening Stock',
                data: stockData.map(item => parseFloat(item.opening_stock))
            },
            {
                name: 'Stock In',
                data: stockData.map(item => parseFloat(item.stock_in))
            },
            {
                name: 'Stock Out',
                data: stockData.map(item => -parseFloat(item.stock_out))
            },
            {
                name: 'Closing Stock',
                data: stockData.map(item => parseFloat(item.closing_stock))
            }
        ],
        xaxis: {
            categories: stockData.map(item => item.product_name.substring(0, 15) + (item.product_name.length > 15 ? '...' : '')),
            labels: {
                rotate: -45,
                trim: true
            }
        },
        yaxis: {
            title: {
                text: 'Stock Quantity'
            }
        },
        colors: ['#556ee6', '#34c38f', '#f46a6a', '#50a5f1'],
        legend: {
            position: 'top'
        },
        fill: {
            opacity: 1
        }
    };

    var chart = new ApexCharts(document.querySelector("#stock-movement-chart"), options);
    chart.render();
    <?php endif; ?>
</script>

</body>
</html>