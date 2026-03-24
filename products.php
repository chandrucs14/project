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
        
        // Check if product has any daybook items
        $checkDaybookStmt = $pdo->prepare("SELECT COUNT(*) FROM daybook_items WHERE product_id = ?");
        $checkDaybookStmt->execute([$product_id]);
        $daybookCount = $checkDaybookStmt->fetchColumn();
        
        if ($invoiceCount > 0 || $orderCount > 0 || $daybookCount > 0) {
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
$query = "SELECT p.*, c.name as category_name, u.full_name as created_by_name 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
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

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
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

// Helper function for safe output
function safe_echo($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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
        
        .gst-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .stock-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .stock-normal {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .stock-low {
            background-color: #fed7aa;
            color: #92400e;
        }
        
        .stock-out {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .product-name {
            font-weight: 600;
            color: #333;
        }
        
        .product-description {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }
        
        .action-buttons {
            white-space: nowrap;
        }
        
        .action-buttons .btn {
            margin: 0 2px;
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
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-package-variant" style="font-size: 32px; color: #667eea;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($stats['total'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Total Products</p>
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
                                        <h5 class="mb-1"><?= number_format($stats['active'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Active Products</p>
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
                                        <i class="mdi mdi-alert" style="font-size: 32px; color: #f59e0b;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($stats['low_stock'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Low Stock</p>
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
                                        <i class="mdi mdi-close-circle" style="font-size: 32px; color: #ef4444;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1"><?= number_format($stats['out_of_stock'] ?? 0) ?></h5>
                                        <p class="text-muted mb-0">Out of Stock</p>
                                    </div>
                                </div>
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
                                <div class="row align-items-end">
                                    <div class="col-md-9">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-5">
                                                    <div class="mb-3">
                                                        <label class="form-label">Search</label>
                                                        <input type="text" class="form-control" name="search" 
                                                               placeholder="Search by name or description..." 
                                                               value="<?= safe_echo($search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
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
                                                            <option value="all" <?= $stock_status === 'all' ? 'selected' : '' ?>>All Products</option>
                                                            <option value="low" <?= $stock_status === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                                            <option value="out" <?= $stock_status === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="mdi mdi-magnify me-1"></i> Apply Filters
                                                    </button>
                                                    <a href="products.php" class="btn btn-secondary ms-2">
                                                        <i class="mdi mdi-refresh me-1"></i> Reset
                                                    </a>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="add-product.php" class="btn btn-success">
                                                <i class="mdi mdi-plus me-1"></i> Add Product
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
                                        <i class="mdi mdi-package-variant" style="font-size: 64px; color: #dee2e6;"></i>
                                        <h5 class="mt-3 text-muted">No products found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="add-product.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-plus"></i> Add Your First Product
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-centered mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Product Details</th>
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
                                                            <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                                            <?php if (!empty($product['description'])): ?>
                                                                <div class="product-description"><?= htmlspecialchars(substr($product['description'], 0, 50)) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($product['category_name'] ?? 'N/A') ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info"><?= htmlspecialchars($product['unit']) ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>₹<?= number_format($product['selling_price'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <?= $product['cost_price'] ? '₹' . number_format($product['cost_price'], 2) : 'N/A' ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $stockClass = 'stock-normal';
                                                            $stockText = number_format($product['current_stock']);
                                                            if ($product['current_stock'] <= 0) {
                                                                $stockClass = 'stock-out';
                                                                $stockText .= ' (Out)';
                                                            } elseif ($product['current_stock'] <= $product['reorder_level'] && $product['reorder_level'] > 0) {
                                                                $stockClass = 'stock-low';
                                                                $stockText .= ' (Low)';
                                                            }
                                                            ?>
                                                            <span class="stock-badge <?= $stockClass ?>"><?= $stockText ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($product['gst_rate']): ?>
                                                                <span class="gst-badge"><?= $product['gst_rate'] ?>%</span>
                                                                <br>
                                                                <small class="text-muted"><?= $product['gst_type'] == 'inclusive' ? 'Inclusive' : 'Exclusive' ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($product['is_active']): ?>
                                                                <span class="status-active">Active</span>
                                                            <?php else: ?>
                                                                <span class="status-inactive">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="action-buttons">
                                                            <a href="edit-product.php?id=<?= $product['id'] ?>" 
                                                               class="btn btn-sm btn-soft-primary" 
                                                               data-bs-toggle="tooltip" 
                                                               title="Edit Product">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </a>
                                                            <button type="button" 
                                                                    onclick="confirmDelete(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')"
                                                                    class="btn btn-sm btn-soft-danger"
                                                                    data-bs-toggle="tooltip" 
                                                                    title="Delete Product">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
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
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    if ($startPage > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>">1</a>
                                                        </li>
                                                        <?php if ($startPage > 2): ?>
                                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($endPage < $totalPages): ?>
                                                        <?php if ($endPage < $totalPages - 1): ?>
                                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                                        <?php endif; ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>"><?= $totalPages ?></a>
                                                        </li>
                                                    <?php endif; ?>
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

                <!-- Quick Export Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Quick Actions</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                        <i class="mdi mdi-printer me-1"></i> Print List
                                    </button>
                                    <a href="export-products.php" class="btn btn-outline-success">
                                        <i class="mdi mdi-file-excel me-1"></i> Export to Excel
                                    </a>
                                    <a href="add-product.php" class="btn btn-outline-primary">
                                        <i class="mdi mdi-plus me-1"></i> Add New Product
                                    </a>
                                </div>
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
            html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>
                   <span class="text-danger">This action cannot be undone.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `products.php?delete=1&id=${id}&search=<?= urlencode($search) ?>&category=<?= $category ?>&stock_status=<?= $stock_status ?>&page=<?= $page ?>`;
            }
        });
    }

    // Auto-submit on filter change (optional)
    document.querySelector('select[name="category"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    document.querySelector('select[name="stock_status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
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
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>

</body>
</html>