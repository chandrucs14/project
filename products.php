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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle add product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $reorder_level = floatval($_POST['reorder_level'] ?? 0);
    $current_stock = floatval($_POST['current_stock'] ?? 0);
    
    if (empty($name)) {
        $error = "Product name is required.";
    } elseif (empty($unit)) {
        $error = "Unit is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if product name already exists
            $checkStmt = $pdo->prepare("SELECT id FROM products WHERE name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                throw new Exception("A product with this name already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO products (name, description, category_id, gst_id, unit, 
                    selling_price, cost_price, reorder_level, current_stock, 
                    is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $name, $description ?: null, $category_id, $gst_id, $unit,
                $selling_price ?: null, $cost_price ?: null, $reorder_level ?: null,
                $current_stock ?: 0, $_SESSION['user_id']
            ]);
            
            if ($result) {
                $product_id = $pdo->lastInsertId();
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'product_id' => $product_id,
                    'product_name' => $name
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    'New product created: ' . $name,
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Product created successfully.";
                header("Location: products.php");
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

// Handle edit product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $product_id = (int)$_POST['product_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $reorder_level = floatval($_POST['reorder_level'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error = "Product name is required.";
    } elseif (empty($unit)) {
        $error = "Unit is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if product name already exists for another product
            $checkStmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND id != ?");
            $checkStmt->execute([$name, $product_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("A product with this name already exists.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE products SET 
                    name = ?, description = ?, category_id = ?, gst_id = ?, unit = ?,
                    selling_price = ?, cost_price = ?, reorder_level = ?, is_active = ?,
                    updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $name, $description ?: null, $category_id, $gst_id, $unit,
                $selling_price ?: null, $cost_price ?: null, $reorder_level ?: null,
                $is_active, $_SESSION['user_id'], $product_id
            ]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'product_id' => $product_id,
                    'product_name' => $name
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    'Product updated: ' . $name,
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Product updated successfully.";
                header("Location: products.php");
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

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if product has any invoice items
        $checkInvoiceStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE product_id = ?");
        $checkInvoiceStmt->execute([$product_id]);
        $invoiceCount = $checkInvoiceStmt->fetchColumn();
        
        // Check if product has any order items
        $checkOrderStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
        $checkOrderStmt->execute([$product_id]);
        $orderCount = $checkOrderStmt->fetchColumn();
        
        if ($invoiceCount > 0 || $orderCount > 0) {
            throw new Exception("Cannot delete product because it has associated transactions.");
        }
        
        // Get product details for logging
        $getStmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $getStmt->execute([$product_id]);
        $product = $getStmt->fetch();
        
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $result = $stmt->execute([$product_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'product_id' => $product_id,
                'product_name' => $product['name']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Product deleted: " . $product['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Product deleted successfully.";
        }
        
        header("Location: products.php?" . http_build_query(['search' => $search, 'category' => $category, 'stock_status' => $stock_status, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: products.php?" . http_build_query(['search' => $search, 'category' => $category, 'stock_status' => $stock_status, 'page' => $page]));
        exit();
    }
}

// Build query
$query = "SELECT p.*, c.name as category_name, g.gst_rate, g.hsn_code, u.full_name as created_by_name 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
          LEFT JOIN gst_details g ON p.gst_id = g.id 
          LEFT JOIN users u ON p.created_by = u.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM products WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $countQuery .= " AND (name LIKE ? OR description LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
}

if ($category > 0) {
    $query .= " AND p.category_id = ?";
    $countQuery .= " AND category_id = ?";
    $params[] = $category;
}

if ($stock_status === 'low') {
    $query .= " AND p.current_stock <= p.reorder_level AND p.reorder_level > 0";
    $countQuery .= " AND current_stock <= reorder_level AND reorder_level > 0";
} elseif ($stock_status === 'out') {
    $query .= " AND p.current_stock <= 0";
    $countQuery .= " AND current_stock <= 0";
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get categories for filter and dropdown
$catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $catStmt->fetchAll();

// Get GST details for dropdown
$gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
$gstDetails = $gstStmt->fetchAll();

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN current_stock <= reorder_level AND reorder_level > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock
    FROM products
");
$stats = $statsStmt->fetch();

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
                            <h4 class="mb-0 font-size-18">Manage Products</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Products</li>
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
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total'] ?? 0) ?></h3>
                                Total Products
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['low_stock'] ?? 0) ?></h3>
                                Low Stock
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2"><?= number_format($stats['out_of_stock'] ?? 0) ?></h3>
                                Out of Stock
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
                                                        <label class="form-label">Search</label>
                                                        <input type="text" class="form-control" name="search" 
                                                               placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Category</label>
                                                        <select name="category" class="form-control">
                                                            <option value="0">All Categories</option>
                                                            <?php foreach ($categories as $cat): ?>
                                                                <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($cat['name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Stock Status</label>
                                                        <select name="stock_status" class="form-control">
                                                            <option value="all" <?= $stock_status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="low" <?= $stock_status === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                                            <option value="out" <?= $stock_status === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="add-product.php" class="btn btn-success">
                                                <i class="mdi mdi-package-variant-plus me-1"></i> Add Product
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Product List</h4>
                                
                                <?php if (empty($products)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-package-variant" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No products found</h5>
                                        <a href="add-product.php" class="btn btn-primary mt-2">Add Product</a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Unit</th>
                                                    <th>Price</th>
                                                    <th>Cost</th>
                                                    <th>Stock</th>
                                                    <th>GST</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                            <br><small class="text-muted"><?= htmlspecialchars($product['description'] ?? '') ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                                        <td><?= htmlspecialchars($product['unit']) ?></td>
                                                        <td>₹<?= number_format($product['selling_price'] ?? 0, 2) ?></td>
                                                        <td>₹<?= number_format($product['cost_price'] ?? 0, 2) ?></td>
                                                        <td>
                                                            <?= number_format($product['current_stock']) ?>
                                                            <?php if ($product['current_stock'] <= $product['reorder_level'] && $product['reorder_level'] > 0): ?>
                                                                <span class="badge bg-soft-danger text-danger ms-1">Low</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= $product['gst_rate'] ? $product['gst_rate'] . '%' : 'N/A' ?></td>
                                                        <td>
                                                            <?php if ($product['is_active']): ?>
                                                                <span class="badge bg-soft-success text-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <a href="edit-product.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </a>
                                                            <a href="javascript:void(0);" 
                                                               onclick="confirmDelete(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')"
                                                               class="btn btn-sm btn-soft-danger">
                                                                <i class="mdi mdi-delete"></i>
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>">
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

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Delete Product?',
            html: `Are you sure you want to delete <strong>${name}</strong>?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `products.php?delete=1&id=${id}`;
            }
        });
    }

    // Auto-submit on filter change
    document.querySelector('select[name="category"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    document.querySelector('select[name="stock_status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
</script>

</body>
</html>