<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Initialize variables
$error = '';
$success = '';
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$stock_status = isset($_GET['stock_status']) ? trim($_GET['stock_status']) : 'all';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'name';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'asc';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $product_id = (int)$_POST['product_id'];
    $adjustment_type = $_POST['adjustment_type'];
    $quantity = floatval($_POST['quantity']);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($quantity <= 0) {
        $error = "Quantity must be greater than 0.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get current stock
            $stockStmt = $pdo->prepare("SELECT name, current_stock FROM products WHERE id = ?");
            $stockStmt->execute([$product_id]);
            $product = $stockStmt->fetch();
            
            if (!$product) {
                throw new Exception("Product not found.");
            }
            
            $old_stock = $product['current_stock'];
            
            if ($adjustment_type === 'add') {
                $new_stock = $old_stock + $quantity;
                $transaction_type = 'in';
                $description = "Stock added: +$quantity";
            } elseif ($adjustment_type === 'remove') {
                if ($old_stock < $quantity) {
                    throw new Exception("Insufficient stock. Available: $old_stock");
                }
                $new_stock = $old_stock - $quantity;
                $transaction_type = 'out';
                $description = "Stock removed: -$quantity";
            } else {
                $new_stock = $quantity; // Set to exact quantity
                if ($new_stock > $old_stock) {
                    $transaction_type = 'in';
                    $description = "Stock adjusted from $old_stock to $new_stock";
                } else {
                    $transaction_type = 'out';
                    $description = "Stock adjusted from $old_stock to $new_stock";
                }
            }
            
            // Update product stock
            $updateStmt = $pdo->prepare("UPDATE products SET current_stock = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
            $updateStmt->execute([$new_stock, $_SESSION['user_id'], $product_id]);
            
            // Record stock transaction
            $transStmt = $pdo->prepare("
                INSERT INTO stock_transactions (
                    transaction_date, product_id, transaction_type, quantity,
                    reference_type, notes, created_by, created_at
                ) VALUES (
                    CURDATE(), ?, ?, ?, 'manual', ?, ?, NOW()
                )
            ");
            $transStmt->execute([$product_id, $transaction_type, $quantity, $reason ?: $description, $_SESSION['user_id']]);
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'product_id' => $product_id,
                'product_name' => $product['name'],
                'old_stock' => $old_stock,
                'new_stock' => $new_stock,
                'adjustment_type' => $adjustment_type,
                'quantity' => $quantity,
                'reason' => $reason
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Stock adjusted for {$product['name']}: $description",
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Stock adjusted successfully. New stock: $new_stock";
            header("Location: current-stock.php?" . http_build_query(['category_id' => $category_id, 'search' => $search, 'stock_status' => $stock_status, 'sort_by' => $sort_by, 'sort_order' => $sort_order, 'page' => $page]));
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to adjust stock: " . $e->getMessage();
            error_log("Stock adjustment error: " . $e->getMessage());
        }
    }
}

// Build the query
$query = "
    SELECT 
        p.*,
        c.name as category_name,
        u.full_name as created_by_name,
        u2.full_name as updated_by_name,
        (SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND transaction_type = 'in') as total_in,
        (SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND transaction_type = 'out') as total_out,
        (SELECT COUNT(*) FROM stock_transactions WHERE product_id = p.id) as transaction_count,
        (SELECT MAX(transaction_date) FROM stock_transactions WHERE product_id = p.id) as last_transaction_date
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    LEFT JOIN users u2 ON p.updated_by = u2.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM products p
    WHERE 1=1
";

$params = [];

if ($category_id > 0) {
    $query .= " AND p.category_id = ?";
    $countQuery .= " AND p.category_id = ?";
    $params[] = $category_id;
}

if (!empty($search)) {
    $query .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
    $countQuery .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($stock_status !== 'all') {
    if ($stock_status === 'low') {
        $query .= " AND p.current_stock <= p.reorder_level AND p.reorder_level > 0";
        $countQuery .= " AND p.current_stock <= p.reorder_level AND p.reorder_level > 0";
    } elseif ($stock_status === 'out') {
        $query .= " AND p.current_stock <= 0";
        $countQuery .= " AND p.current_stock <= 0";
    } elseif ($stock_status === 'overstock') {
        $query .= " AND p.current_stock > p.reorder_level * 3 AND p.reorder_level > 0";
        $countQuery .= " AND p.current_stock > p.reorder_level * 3 AND p.reorder_level > 0";
    }
}

// Sorting
$allowed_sort = ['name', 'current_stock', 'category_name', 'selling_price', 'reorder_level'];
if (in_array($sort_by, $allowed_sort)) {
    if ($sort_by === 'category_name') {
        $query .= " ORDER BY c.name $sort_order";
    } else {
        $query .= " ORDER BY p.$sort_by $sort_order";
    }
} else {
    $query .= " ORDER BY p.name ASC";
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get categories for filter
$catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $catStmt->fetchAll();

// Get statistics (matching index.php UI style)
$statsQuery = "
    SELECT 
        COUNT(*) as total_products,
        SUM(current_stock) as total_units,
        SUM(current_stock * cost_price) as total_cost_value,
        SUM(current_stock * selling_price) as total_selling_value,
        SUM(CASE WHEN current_stock <= reorder_level AND reorder_level > 0 THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
        SUM(CASE WHEN current_stock > reorder_level * 3 AND reorder_level > 0 THEN 1 ELSE 0 END) as overstock_count,
        COALESCE(AVG(current_stock), 0) as avg_stock_per_product
    FROM products
    WHERE is_active = 1
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get top 5 low stock items
$lowStockStmt = $pdo->query("
    SELECT p.id, p.name, p.current_stock, p.reorder_level, p.unit, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.current_stock <= p.reorder_level AND p.reorder_level > 0 AND p.is_active = 1
    ORDER BY (p.current_stock / p.reorder_level) ASC
    LIMIT 5
");
$lowStockItems = $lowStockStmt->fetchAll();

// Get top 5 products by stock value
$topValueStmt = $pdo->query("
    SELECT p.id, p.name, p.current_stock, p.cost_price, p.selling_price, 
           (p.current_stock * p.cost_price) as stock_value,
           c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
    ORDER BY stock_value DESC
    LIMIT 5
");
$topValueItems = $topValueStmt->fetchAll();

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
    <style>
        .stock-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .stock-normal {
            background-color: #34c38f;
        }
        .stock-low {
            background-color: #f9b851;
        }
        .stock-critical {
            background-color: #f46a6a;
        }
        .stock-out {
            background-color: #dc3545;
        }
        .stock-over {
            background-color: #556ee6;
        }
        .value-positive {
            color: #34c38f;
            font-weight: 600;
        }
        .value-negative {
            color: #f46a6a;
            font-weight: 600;
        }
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        /* Index.php style stats cards */
        .card.text-center {
            transition: transform 0.3s;
        }
        .card.text-center:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .card.text-center .card-body {
            padding: 1.25rem;
        }
        .text-info {
            color: #50a5f1 !important;
        }
        .text-purple {
            color: #6f42c1 !important;
        }
        .text-primary {
            color: #556ee6 !important;
        }
        .text-danger {
            color: #f46a6a !important;
        }
        .sort-link {
            color: #495057;
            text-decoration: none;
        }
        .sort-link:hover {
            color: #556ee6;
        }
        .sort-link i {
            margin-left: 5px;
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
                            <h4 class="mb-0 font-size-18">Current Stock</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                                    <li class="breadcrumb-item active">Current Stock</li>
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

                <!-- Stock Alerts -->
                <?php if ($stats['low_stock_count'] > 0 || $stats['out_of_stock_count'] > 0): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <i class="mdi mdi-alert me-2"></i>
                            <strong>Stock Alerts:</strong>
                            <?php if ($stats['out_of_stock_count'] > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $stats['out_of_stock_count'] ?> Out of Stock</span>
                            <?php endif; ?>
                            <?php if ($stats['low_stock_count'] > 0): ?>
                                <span class="badge bg-warning ms-2"><?= $stats['low_stock_count'] ?> Low Stock</span>
                            <?php endif; ?>
                            <?php if ($stats['overstock_count'] > 0): ?>
                                <span class="badge bg-info ms-2"><?= $stats['overstock_count'] ?> Overstock</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards - Matching index.php UI style -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total_products'] ?? 0) ?></h3>
                                Total Products
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2"><?= number_format($stats['total_units'] ?? 0) ?></h3>
                                Total Units
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">₹<?= number_format($stats['total_cost_value'] ?? 0, 2) ?></h3>
                                Cost Value
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">₹<?= number_format($stats['total_selling_value'] ?? 0, 2) ?></h3>
                                Selling Value
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Filter and Actions Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-9">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-4">
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
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Stock Status</label>
                                                        <select name="stock_status" class="form-control">
                                                            <option value="all" <?= $stock_status === 'all' ? 'selected' : '' ?>>All Stock</option>
                                                            <option value="low" <?= $stock_status === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                                            <option value="out" <?= $stock_status === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                                            <option value="overstock" <?= $stock_status === 'overstock' ? 'selected' : '' ?>>Overstock</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-50">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                            <a href="current-stock.php" class="btn btn-secondary w-50">
                                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-md-8">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               name="search" 
                                                               placeholder="Search by product name, description or category..." 
                                                               value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="d-flex gap-2">
                                                        <select name="sort_by" class="form-control">
                                                            <option value="name" <?= $sort_by == 'name' ? 'selected' : '' ?>>Sort by Name</option>
                                                            <option value="current_stock" <?= $sort_by == 'current_stock' ? 'selected' : '' ?>>Sort by Stock</option>
                                                            <option value="category_name" <?= $sort_by == 'category_name' ? 'selected' : '' ?>>Sort by Category</option>
                                                            <option value="selling_price" <?= $sort_by == 'selling_price' ? 'selected' : '' ?>>Sort by Price</option>
                                                            <option value="reorder_level" <?= $sort_by == 'reorder_level' ? 'selected' : '' ?>>Sort by Reorder Level</option>
                                                        </select>
                                                        <select name="sort_order" class="form-control">
                                                            <option value="asc" <?= $sort_order == 'asc' ? 'selected' : '' ?>>Ascending</option>
                                                            <option value="desc" <?= $sort_order == 'desc' ? 'selected' : '' ?>>Descending</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="stock-transactions.php" class="btn btn-info">
                                                <i class="mdi mdi-history me-1"></i> View Transactions
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Stock Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Current Stock Levels</h4>
                                
                                <?php if (empty($products)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-package-variant" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No products found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Current Stock</th>
                                                    <th>Reorder Level</th>
                                                    <th>Stock Status</th>
                                                    <th>Stock Value (Cost)</th>
                                                    <th>Stock Value (Selling)</th>
                                                    <th>Last Transaction</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): ?>
                                                    <?php
                                                    $stock_percentage = $product['reorder_level'] > 0 ? ($product['current_stock'] / $product['reorder_level']) * 100 : 100;
                                                    
                                                    if ($product['current_stock'] <= 0) {
                                                        $stock_class = 'stock-out';
                                                        $stock_text = 'Out of Stock';
                                                        $badge_class = 'danger';
                                                    } elseif ($product['current_stock'] <= $product['reorder_level'] && $product['reorder_level'] > 0) {
                                                        $stock_class = 'stock-critical';
                                                        $stock_text = 'Critical';
                                                        $badge_class = 'danger';
                                                    } elseif ($product['current_stock'] <= $product['reorder_level'] * 2 && $product['reorder_level'] > 0) {
                                                        $stock_class = 'stock-low';
                                                        $stock_text = 'Low';
                                                        $badge_class = 'warning';
                                                    } elseif ($product['current_stock'] > $product['reorder_level'] * 3 && $product['reorder_level'] > 0) {
                                                        $stock_class = 'stock-over';
                                                        $stock_text = 'Overstock';
                                                        $badge_class = 'info';
                                                    } else {
                                                        $stock_class = 'stock-normal';
                                                        $stock_text = 'Normal';
                                                        $badge_class = 'success';
                                                    }
                                                    
                                                    $cost_value = $product['current_stock'] * ($product['cost_price'] ?? 0);
                                                    $selling_value = $product['current_stock'] * ($product['selling_price'] ?? 0);
                                                    $potential_profit = $selling_value - $cost_value;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($product['description'] ?? '') ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="stock-indicator <?= $stock_class ?>"></span>
                                                                <strong class="me-2"><?= number_format($product['current_stock']) ?></strong>
                                                                <small class="text-muted"><?= $product['unit'] ?></small>
                                                            </div>
                                                            <?php if ($product['reorder_level'] > 0): ?>
                                                                <div class="progress">
                                                                    <div class="progress-bar bg-<?= $badge_class ?>" 
                                                                         role="progressbar" 
                                                                         style="width: <?= min($stock_percentage, 100) ?>%;" 
                                                                         aria-valuenow="<?= $stock_percentage ?>" 
                                                                         aria-valuemin="0" 
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= $product['reorder_level'] ? number_format($product['reorder_level']) . ' ' . $product['unit'] : 'Not set' ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-<?= $badge_class ?> text-<?= $badge_class ?>">
                                                                <?= $stock_text ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div>₹<?= number_format($cost_value, 2) ?></div>
                                                            <small class="text-muted">@ ₹<?= number_format($product['cost_price'] ?? 0, 2) ?>/unit</small>
                                                        </td>
                                                        <td>
                                                            <div>₹<?= number_format($selling_value, 2) ?></div>
                                                            <small class="<?= $potential_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                                                                Profit: ₹<?= number_format($potential_profit, 2) ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php if ($product['last_transaction_date']): ?>
                                                                <?= date('d-m-Y', strtotime($product['last_transaction_date'])) ?>
                                                                <br>
                                                                <small class="text-muted"><?= $product['transaction_count'] ?> transactions</small>
                                                            <?php else: ?>
                                                                <span class="text-muted">No transactions</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-soft-success" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#adjustStockModal"
                                                                        data-product-id="<?= $product['id'] ?>"
                                                                        data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                                                        data-current-stock="<?= $product['current_stock'] ?>"
                                                                        data-unit="<?= $product['unit'] ?>"
                                                                        onclick="prepareAdjustModal(this)">
                                                                    <i class="mdi mdi-pencil"></i> Adjust
                                                                </button>
                                                                <a href="product-stock-history.php?id=<?= $product['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-info" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="View History">
                                                                    <i class="mdi mdi-history"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- end table-responsive -->

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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&category_id=<?= $category_id ?>&search=<?= urlencode($search) ?>&stock_status=<?= $stock_status ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&category_id=<?= $category_id ?>&search=<?= urlencode($search) ?>&stock_status=<?= $stock_status ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&category_id=<?= $category_id ?>&search=<?= urlencode($search) ?>&stock_status=<?= $stock_status ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>">
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
                <!-- end row -->

                <!-- Low Stock and Top Value Widgets -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Low Stock Alert</h4>
                                
                                <?php if (empty($lowStockItems)): ?>
                                    <div class="text-center py-3">
                                        <i class="mdi mdi-check-circle text-success" style="font-size: 36px;"></i>
                                        <h5 class="mt-2">All products are well stocked!</h5>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Current Stock</th>
                                                    <th>Reorder Level</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lowStockItems as $item): ?>
                                                    <?php
                                                    $ratio = $item['reorder_level'] > 0 ? ($item['current_stock'] / $item['reorder_level']) * 100 : 0;
                                                    if ($item['current_stock'] <= 0) {
                                                        $badge = 'danger';
                                                        $status = 'Out of Stock';
                                                    } elseif ($ratio <= 50) {
                                                        $badge = 'danger';
                                                        $status = 'Critical';
                                                    } else {
                                                        $badge = 'warning';
                                                        $status = 'Low';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                                        <td><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></td>
                                                        <td>
                                                            <strong class="text-danger"><?= number_format($item['current_stock']) ?></strong>
                                                            <small><?= $item['unit'] ?></small>
                                                        </td>
                                                        <td><?= number_format($item['reorder_level']) ?> <?= $item['unit'] ?></td>
                                                        <td>
                                                            <span class="badge bg-soft-<?= $badge ?> text-<?= $badge ?>"><?= $status ?></span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-soft-primary" 
                                                                    onclick="quickAdjust(<?= $item['id'] ?>, '<?= htmlspecialchars($item['name']) ?>', <?= $item['current_stock'] ?>, '<?= $item['unit'] ?>')">
                                                                <i class="mdi mdi-pencil"></i> Adjust
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Top Stock Value</h4>
                                
                                <?php if (empty($topValueItems)): ?>
                                    <div class="text-center py-3">
                                        <i class="mdi mdi-package-variant" style="font-size: 36px; color: #ccc;"></i>
                                        <h5 class="mt-2">No products found</h5>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Stock</th>
                                                    <th>Unit Cost</th>
                                                    <th>Stock Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($topValueItems as $item): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                                        <td><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></td>
                                                        <td><?= number_format($item['current_stock']) ?></td>
                                                        <td>₹<?= number_format($item['cost_price'] ?? 0, 2) ?></td>
                                                        <td><strong>₹<?= number_format($item['stock_value'], 2) ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
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

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-pencil me-2"></i>Adjust Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="adjustStockForm">
                <input type="hidden" name="product_id" id="modal_product_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="modal_product_name" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="modal_current_stock" readonly>
                            <span class="input-group-text" id="modal_unit"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="adjustment_type" class="form-control" required>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                            <option value="set">Set to Exact Quantity</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason / Notes</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="e.g., Stock count adjustment, damaged goods, etc."></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="mdi mdi-information me-2"></i>
                        Stock adjustments will be recorded in transaction history.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="adjust_stock" class="btn btn-primary">Apply Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (!alert.classList.contains('alert-warning')) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 500);
            }
        });
    }, 5000);
    
    // Prepare adjust stock modal
    function prepareAdjustModal(button) {
        const productId = button.getAttribute('data-product-id');
        const productName = button.getAttribute('data-product-name');
        const currentStock = button.getAttribute('data-current-stock');
        const unit = button.getAttribute('data-unit');
        
        document.getElementById('modal_product_id').value = productId;
        document.getElementById('modal_product_name').value = productName;
        document.getElementById('modal_current_stock').value = currentStock;
        document.getElementById('modal_unit').textContent = unit;
    }
    
    // Quick adjust from low stock widget
    function quickAdjust(id, name, currentStock, unit) {
        document.getElementById('modal_product_id').value = id;
        document.getElementById('modal_product_name').value = name;
        document.getElementById('modal_current_stock').value = currentStock;
        document.getElementById('modal_unit').textContent = unit;
        
        $('#adjustStockModal').modal('show');
    }
    
    // Search with debounce
    let searchTimeout;
    document.querySelector('input[name="search"]')?.addEventListener('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });
    
    // Auto-submit on filter changes
    document.querySelector('select[name="category_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="stock_status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="sort_by"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="sort_order"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Form validation
    document.getElementById('adjustStockForm')?.addEventListener('submit', function(e) {
        const quantity = parseFloat(this.querySelector('input[name="quantity"]').value) || 0;
        if (quantity <= 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please enter a valid quantity',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.querySelector('input[name="search"]')?.focus();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value !== '') {
                searchInput.value = '';
                document.getElementById('filterForm').submit();
            }
        }
    });
</script>

</body>
</html>