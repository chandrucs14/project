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

// Fetch categories for dropdown - removed is_active condition as it doesn't exist
$catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $catStmt->fetchAll();

// Fetch GST details for dropdown
$gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
$gstDetails = $gstStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    // Get and sanitize form data
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $gst_id = !empty($_POST['gst_id']) ? (int)$_POST['gst_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $selling_price = !empty($_POST['selling_price']) ? floatval($_POST['selling_price']) : null;
    $cost_price = !empty($_POST['cost_price']) ? floatval($_POST['cost_price']) : null;
    $reorder_level = !empty($_POST['reorder_level']) ? floatval($_POST['reorder_level']) : null;
    $current_stock = !empty($_POST['current_stock']) ? floatval($_POST['current_stock']) : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($name)) {
        $error = "Product name is required.";
    } elseif (empty($unit)) {
        $error = "Unit is required.";
    } elseif ($selling_price !== null && $selling_price < 0) {
        $error = "Selling price cannot be negative.";
    } elseif ($cost_price !== null && $cost_price < 0) {
        $error = "Cost price cannot be negative.";
    } elseif ($reorder_level !== null && $reorder_level < 0) {
        $error = "Reorder level cannot be negative.";
    } elseif ($current_stock < 0) {
        $error = "Current stock cannot be negative.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if product name already exists
            $checkStmt = $pdo->prepare("SELECT id FROM products WHERE name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                throw new Exception("A product with this name already exists.");
            }
            
            // Insert product
            $stmt = $pdo->prepare("
                INSERT INTO products (
                    name, description, category_id, gst_id, unit,
                    selling_price, cost_price, reorder_level, current_stock,
                    is_active, created_by, created_at
                ) VALUES (
                    :name, :description, :category_id, :gst_id, :unit,
                    :selling_price, :cost_price, :reorder_level, :current_stock,
                    :is_active, :created_by, NOW()
                )
            ");
            
            $params = [
                ':name' => $name,
                ':description' => $description ?: null,
                ':category_id' => $category_id,
                ':gst_id' => $gst_id,
                ':unit' => $unit,
                ':selling_price' => $selling_price,
                ':cost_price' => $cost_price,
                ':reorder_level' => $reorder_level,
                ':current_stock' => $current_stock,
                ':is_active' => $is_active,
                ':created_by' => $_SESSION['user_id']
            ];
            
            $result = $stmt->execute($params);
            
            if ($result) {
                $product_id = $pdo->lastInsertId();
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at
                    ) VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'product_id' => $product_id,
                    'product_name' => $name,
                    'unit' => $unit,
                    'selling_price' => $selling_price,
                    'cost_price' => $cost_price
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
            } else {
                $pdo->rollBack();
                $error = "Failed to create product. Please try again.";
            }
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
            error_log("PDO Error: " . $e->getMessage());
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
            error_log("Product creation error: " . $e->getMessage());
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

// Helper function for safe output
function safe_echo($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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
                            <h4 class="mb-0 font-size-18">Add New Product</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                                    <li class="breadcrumb-item active">Add Product</li>
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
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Product Information</h4>
                                <p class="card-title-desc">Enter the details of the new product</p>

                                <form method="POST" action="" id="productForm">
                                    <!-- Basic Information -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-package-variant"></i></span>
                                                    <input type="text" 
                                                           name="name" 
                                                           class="form-control" 
                                                           placeholder="Enter product name" 
                                                           required
                                                           value="<?= safe_echo($_POST['name'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Unit <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-scale-balance"></i></span>
                                                    <select name="unit" class="form-control" required>
                                                        <option value="">Select Unit</option>
                                                        <option value="PIECES" <?= (isset($_POST['unit']) && $_POST['unit'] == 'PIECES') ? 'selected' : '' ?>>Pieces</option>
                                                        <option value="KG" <?= (isset($_POST['unit']) && $_POST['unit'] == 'KG') ? 'selected' : '' ?>>Kilogram (KG)</option>
                                                        <option value="TON" <?= (isset($_POST['unit']) && $_POST['unit'] == 'TON') ? 'selected' : '' ?>>Ton</option>
                                                        <option value="LITER" <?= (isset($_POST['unit']) && $_POST['unit'] == 'LITER') ? 'selected' : '' ?>>Liter</option>
                                                        <option value="METER" <?= (isset($_POST['unit']) && $_POST['unit'] == 'METER') ? 'selected' : '' ?>>Meter</option>
                                                        <option value="SQUARE_FT" <?= (isset($_POST['unit']) && $_POST['unit'] == 'SQUARE_FT') ? 'selected' : '' ?>>Square Feet</option>
                                                        <option value="CUBIC_FT" <?= (isset($_POST['unit']) && $_POST['unit'] == 'CUBIC_FT') ? 'selected' : '' ?>>Cubic Feet</option>
                                                        <option value="BAG" <?= (isset($_POST['unit']) && $_POST['unit'] == 'BAG') ? 'selected' : '' ?>>Bag</option>
                                                        <option value="BOX" <?= (isset($_POST['unit']) && $_POST['unit'] == 'BOX') ? 'selected' : '' ?>>Box</option>
                                                        <option value="DOZEN" <?= (isset($_POST['unit']) && $_POST['unit'] == 'DOZEN') ? 'selected' : '' ?>>Dozen</option>
                                                        <option value="OTHER" <?= (isset($_POST['unit']) && $_POST['unit'] == 'OTHER') ? 'selected' : '' ?>>Other</option>
                                                    </select>
                                                </div>
                                                <small class="text-muted">Select the unit of measurement</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" 
                                                  class="form-control" 
                                                  rows="3"
                                                  placeholder="Enter product description"><?= safe_echo($_POST['description'] ?? '') ?></textarea>
                                    </div>

                                    <!-- Category and GST -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Category</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-tag"></i></span>
                                                    <select name="category_id" class="form-control">
                                                        <option value="">Select Category</option>
                                                        <?php foreach ($categories as $category): ?>
                                                            <option value="<?= $category['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($category['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">GST Rate</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-percent"></i></span>
                                                    <select name="gst_id" class="form-control">
                                                        <option value="">Select GST Rate</option>
                                                        <?php foreach ($gstDetails as $gst): ?>
                                                            <option value="<?= $gst['id'] ?>" <?= (isset($_POST['gst_id']) && $_POST['gst_id'] == $gst['id']) ? 'selected' : '' ?>>
                                                                <?= $gst['gst_rate'] ?>% (<?= htmlspecialchars($gst['hsn_code']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pricing Information -->
                                    <h5 class="font-size-14 mb-3">Pricing Information</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Selling Price (₹)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                                    <input type="number" 
                                                           name="selling_price" 
                                                           class="form-control" 
                                                           placeholder="0.00"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($_POST['selling_price'] ?? '') ?>">
                                                </div>
                                                <small class="text-muted">Price at which product is sold to customers</small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Cost Price (₹)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                                    <input type="number" 
                                                           name="cost_price" 
                                                           class="form-control" 
                                                           placeholder="0.00"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($_POST['cost_price'] ?? '') ?>">
                                                </div>
                                                <small class="text-muted">Purchase cost from supplier</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Stock Information -->
                                    <h5 class="font-size-14 mb-3">Stock Information</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Current Stock</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-package"></i></span>
                                                    <input type="number" 
                                                           name="current_stock" 
                                                           class="form-control" 
                                                           placeholder="0"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($_POST['current_stock'] ?? '0') ?>">
                                                </div>
                                                <small class="text-muted">Current quantity in stock</small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Reorder Level</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-alert"></i></span>
                                                    <input type="number" 
                                                           name="reorder_level" 
                                                           class="form-control" 
                                                           placeholder="0"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($_POST['reorder_level'] ?? '') ?>">
                                                </div>
                                                <small class="text-muted">Alert when stock falls below this level</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Profit Margin Display -->
                                    <?php if (isset($_POST['selling_price']) && isset($_POST['cost_price']) && $_POST['selling_price'] > 0 && $_POST['cost_price'] > 0): ?>
                                        <?php
                                        $selling = floatval($_POST['selling_price']);
                                        $cost = floatval($_POST['cost_price']);
                                        $profit = $selling - $cost;
                                        $margin = ($selling > 0) ? ($profit / $selling) * 100 : 0;
                                        ?>
                                        <div class="alert alert-info">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <strong>Profit:</strong> ₹<?= number_format($profit, 2) ?>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Margin:</strong> <?= number_format($margin, 2) ?>%
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Markup:</strong> <?= $cost > 0 ? number_format(($profit / $cost) * 100, 2) : 0 ?>%
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Status -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <div class="form-check form-switch form-switch-md">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           name="is_active" 
                                                           id="is_active" 
                                                           checked>
                                                    <label class="form-check-label" for="is_active">Active Status</label>
                                                </div>
                                                <small class="text-muted d-block">Toggle to activate or deactivate this product</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Info Alert -->
                                    <div class="alert alert-info">
                                        <i class="mdi mdi-information me-2"></i>
                                        Make sure to verify all product details before saving. Product name must be unique.
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="text-end">
                                                <a href="products.php" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="reset" class="btn btn-warning me-2" id="resetBtn">
                                                    <i class="mdi mdi-undo me-1"></i> Reset
                                                </button>
                                                <button type="submit" name="add_product" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Save Product</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Saving...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Quick Tips -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Quick Tips</h4>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-package-variant text-primary" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">Product Name</h6>
                                                <p class="text-muted">Use descriptive names that are easy to identify</p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-currency-inr text-success" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">Pricing</h6>
                                                <p class="text-muted">Selling price should be higher than cost price for profit</p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-alert text-warning" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1">Reorder Level</h6>
                                                <p class="text-muted">Set reorder level to get alerts when stock is low</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end quick tips -->

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

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Form submission loading state
    document.getElementById('productForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        setTimeout(function() {
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        }, 100);
    });
    
    // Reset button confirmation
    document.getElementById('resetBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Reset Form?',
            text: 'Are you sure you want to reset all fields? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, reset it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('productForm').reset();
                Swal.fire({
                    title: 'Reset!',
                    text: 'Form has been reset.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (!alert.classList.contains('alert-info')) {
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
    
    // Price validation
    const sellingPrice = document.querySelector('input[name="selling_price"]');
    const costPrice = document.querySelector('input[name="cost_price"]');
    
    if (sellingPrice) {
        sellingPrice.addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });
    }
    
    if (costPrice) {
        costPrice.addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });
    }
    
    // Stock validation
    const currentStock = document.querySelector('input[name="current_stock"]');
    const reorderLevel = document.querySelector('input[name="reorder_level"]');
    
    if (currentStock) {
        currentStock.addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });
    }
    
    if (reorderLevel) {
        reorderLevel.addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });
    }
    
    // Auto-capitalize product name
    const nameInput = document.querySelector('input[name="name"]');
    if (nameInput) {
        nameInput.addEventListener('input', function(e) {
            // Capitalize first letter of each word
            this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
        });
    }
    
    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('productForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, textarea, select');
        const originalValues = {};
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox') {
                originalValues[input.name] = input.value;
            } else {
                originalValues[input.name] = input.checked;
            }
            
            input.addEventListener('change', () => {
                checkFormDirty();
            });
            input.addEventListener('input', () => {
                checkFormDirty();
            });
        });
        
        function checkFormDirty() {
            formDirty = false;
            formInputs.forEach(input => {
                if (input.type !== 'checkbox') {
                    if (input.value !== originalValues[input.name]) {
                        formDirty = true;
                    }
                } else {
                    if (input.checked !== originalValues[input.name]) {
                        formDirty = true;
                    }
                }
            });
        }
        
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // Reset dirty flag on form submit
        form.addEventListener('submit', () => {
            formDirty = false;
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('productForm').submit();
        }
        
        // Escape to reset
        if (e.key === 'Escape') {
            e.preventDefault();
            document.getElementById('resetBtn')?.click();
        }
    });
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>

</body>
</html>