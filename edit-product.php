<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Get product ID from URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    header("Location: products.php");
    exit();
}

// Fetch product details
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name, g.gst_rate, g.hsn_code 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN gst_details g ON p.gst_id = g.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        $_SESSION['error_message'] = "Product not found.";
        header("Location: products.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching product: " . $e->getMessage());
    $_SESSION['error_message'] = "Error fetching product details.";
    header("Location: products.php");
    exit();
}

// Fetch categories for dropdown
try {
    $catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $catStmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Fetch GST details for dropdown
try {
    $gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
    $gstDetails = $gstStmt->fetchAll();
} catch (Exception $e) {
    $gstDetails = [];
    error_log("Error fetching GST details: " . $e->getMessage());
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $cost_price = !empty($_POST['cost_price']) ? floatval($_POST['cost_price']) : null;
    $reorder_level = floatval($_POST['reorder_level'] ?? 0);
    $current_stock = floatval($_POST['current_stock'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($name)) {
        $error = "Product name is required.";
    } elseif (empty($unit)) {
        $error = "Unit is required.";
    } elseif ($selling_price <= 0) {
        $error = "Selling price must be greater than 0.";
    } elseif ($reorder_level < 0) {
        $error = "Reorder level cannot be negative.";
    } elseif ($current_stock < 0) {
        $error = "Current stock cannot be negative.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if product name already exists for another product
            $checkStmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND id != ?");
            $checkStmt->execute([$name, $product_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("A product with this name already exists.");
            }
            
            // Update product
            $stmt = $pdo->prepare("
                UPDATE products SET 
                    name = ?, 
                    description = ?, 
                    category_id = ?, 
                    gst_id = ?, 
                    unit = ?, 
                    selling_price = ?, 
                    cost_price = ?, 
                    reorder_level = ?, 
                    current_stock = ?,
                    is_active = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $name,
                $description ?: null,
                $category_id,
                $gst_id,
                $unit,
                $selling_price,
                $cost_price,
                $reorder_level,
                $current_stock,
                $is_active,
                $_SESSION['user_id'],
                $product_id
            ]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'product_id' => $product_id,
                    'product_name' => $name,
                    'changes' => [
                        'name' => $name,
                        'selling_price' => $selling_price,
                        'current_stock' => $current_stock
                    ]
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "Product updated: " . $name,
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
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
                            <h4 class="mb-0 font-size-18">Edit Product</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                                    <li class="breadcrumb-item active">Edit Product</li>
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

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Product Information</h4>

                                <form method="POST" action="" id="productForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-box"></i></span>
                                                    <input type="text" 
                                                           name="name" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($product['name']) ?>"
                                                           placeholder="Enter product name"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Unit <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-rulers"></i></span>
                                                    <input type="text" 
                                                           name="unit" 
                                                           class="form-control" 
                                                           value="<?= htmlspecialchars($product['unit']) ?>"
                                                           placeholder="e.g., kg, pcs, box"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" 
                                                  class="form-control" 
                                                  rows="3" 
                                                  placeholder="Enter product description"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Category</label>
                                                <select name="category_id" class="form-control select2">
                                                    <option value="">Select Category</option>
                                                    <?php foreach ($categories as $cat): ?>
                                                        <option value="<?= $cat['id'] ?>" <?= $product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cat['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">GST Rate</label>
                                                <select name="gst_id" class="form-control select2" id="gstSelect">
                                                    <option value="">No GST</option>
                                                    <?php foreach ($gstDetails as $gst): ?>
                                                        <option value="<?= $gst['id'] ?>" 
                                                                data-rate="<?= $gst['gst_rate'] ?>"
                                                                data-hsn="<?= $gst['hsn_code'] ?>"
                                                                <?= $product['gst_id'] == $gst['id'] ? 'selected' : '' ?>>
                                                            <?= $gst['gst_rate'] ?>% - <?= htmlspecialchars($gst['hsn_code']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Selling Price (₹) <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" 
                                                           name="selling_price" 
                                                           class="form-control" 
                                                           value="<?= $product['selling_price'] ?>"
                                                           min="0.01" 
                                                           step="0.01"
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Cost Price (₹)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" 
                                                           name="cost_price" 
                                                           class="form-control" 
                                                           value="<?= $product['cost_price'] ?? '' ?>"
                                                           min="0" 
                                                           step="0.01">
                                                </div>
                                                <small class="text-muted">Purchase cost for profit calculation</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Current Stock</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-boxes"></i></span>
                                                    <input type="number" 
                                                           name="current_stock" 
                                                           class="form-control" 
                                                           value="<?= $product['current_stock'] ?>"
                                                           min="0" 
                                                           step="0.01">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Reorder Level</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="bi bi-exclamation-triangle"></i></span>
                                                    <input type="number" 
                                                           name="reorder_level" 
                                                           class="form-control" 
                                                           value="<?= $product['reorder_level'] ?>"
                                                           min="0" 
                                                           step="0.01">
                                                </div>
                                                <small class="text-muted">Alert when stock reaches this level</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <div class="form-check form-switch form-switch-md">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           name="is_active" 
                                                           id="is_active"
                                                           <?= $product['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_active">Active</label>
                                                </div>
                                                <small class="text-muted">Inactive products won't appear in dropdowns</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="row">
                                        <div class="col-12">
                                            <div class="text-end">
                                                <a href="products.php" class="btn btn-secondary me-2">
                                                    <i class="bi bi-arrow-left"></i> Cancel
                                                </a>
                                                <button type="submit" name="update_product" class="btn btn-primary" id="submitBtn">
                                                    <i class="bi bi-check-circle"></i>
                                                    <span id="btnText">Update Product</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Updating...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Sidebar - Product Info -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Product Information</h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td><strong>Product ID:</strong></td>
                                        <td>#<?= $product['id'] ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Created:</strong></td>
                                        <td><?= date('d M Y', strtotime($product['created_at'])) ?></td>
                                    </tr>
                                    <?php if ($product['gst_rate']): ?>
                                    <tr>
                                        <td><strong>GST Rate:</strong></td>
                                        <td><?= $product['gst_rate'] ?>%</td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($product['hsn_code']): ?>
                                    <tr>
                                        <td><strong>HSN Code:</strong></td>
                                        <td><?= $product['hsn_code'] ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>

                                <hr>

                                <h6 class="mb-3">Quick Tips</h6>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Selling price is required</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Cost price helps track profit</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Set reorder level for stock alerts</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Inactive products are hidden from selection</li>
                                </ul>
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

    // Form submission loading state
    document.getElementById('productForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        if (btn) {
            btn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (loading) loading.style.display = 'inline-block';
        }
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

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('productForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox' && input.type !== 'hidden' && input.type !== 'submit') {
                input.addEventListener('change', () => { formDirty = true; });
                input.addEventListener('input', () => { formDirty = true; });
            }
        });
        
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        form.addEventListener('submit', () => {
            formDirty = false;
        });
    }
</script>

</body>
</html>