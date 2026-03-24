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
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $gst_rate = !empty($_POST['gst_rate']) ? floatval($_POST['gst_rate']) : null;
    $gst_type = $_POST['gst_type'] ?? 'exclusive';
    $hsn_code = trim($_POST['hsn_code'] ?? '');
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
    } elseif ($gst_rate !== null && ($gst_rate < 0 || $gst_rate > 100)) {
        $error = "GST rate must be between 0 and 100.";
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
                    name = :name, 
                    description = :description, 
                    category_id = :category_id, 
                    gst_rate = :gst_rate,
                    gst_type = :gst_type,
                    hsn_code = :hsn_code,
                    unit = :unit, 
                    selling_price = :selling_price, 
                    cost_price = :cost_price, 
                    reorder_level = :reorder_level, 
                    current_stock = :current_stock,
                    is_active = :is_active,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $params = [
                ':name' => $name,
                ':description' => $description ?: null,
                ':category_id' => $category_id,
                ':gst_rate' => $gst_rate,
                ':gst_type' => $gst_type,
                ':hsn_code' => $hsn_code ?: null,
                ':unit' => $unit,
                ':selling_price' => $selling_price,
                ':cost_price' => $cost_price,
                ':reorder_level' => $reorder_level,
                ':current_stock' => $current_stock,
                ':is_active' => $is_active,
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $product_id
            ];
            
            $result = $stmt->execute($params);
            
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
                        'current_stock' => $current_stock,
                        'gst_rate' => $gst_rate,
                        'gst_type' => $gst_type,
                        'hsn_code' => $hsn_code
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
            error_log("Product update error: " . $e->getMessage());
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

<head>
    <style>
        .gst-info-box {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .gst-preview {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .gst-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .radio-group {
            display: flex;
            gap: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .radio-group label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .radio-group input[type="radio"] {
            cursor: pointer;
        }
        
        .info-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }
        
        .info-card i {
            font-size: 20px;
            color: #667eea;
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
                            <h4 class="mb-0 font-size-18">Edit Product</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
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
                                <p class="card-title-desc">Update the product details below</p>

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
                                                           value="<?= safe_echo($product['name']) ?>">
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
                                                        <option value="PIECES" <?= ($product['unit'] == 'PIECES') ? 'selected' : '' ?>>Pieces</option>
                                                        <option value="KG" <?= ($product['unit'] == 'KG') ? 'selected' : '' ?>>Kilogram (KG)</option>
                                                        <option value="TON" <?= ($product['unit'] == 'TON') ? 'selected' : '' ?>>Ton</option>
                                                        <option value="LITER" <?= ($product['unit'] == 'LITER') ? 'selected' : '' ?>>Liter</option>
                                                        <option value="METER" <?= ($product['unit'] == 'METER') ? 'selected' : '' ?>>Meter</option>
                                                        <option value="SQUARE_FT" <?= ($product['unit'] == 'SQUARE_FT') ? 'selected' : '' ?>>Square Feet</option>
                                                        <option value="CUBIC_FT" <?= ($product['unit'] == 'CUBIC_FT') ? 'selected' : '' ?>>Cubic Feet</option>
                                                        <option value="BAG" <?= ($product['unit'] == 'BAG') ? 'selected' : '' ?>>Bag</option>
                                                        <option value="BOX" <?= ($product['unit'] == 'BOX') ? 'selected' : '' ?>>Box</option>
                                                        <option value="DOZEN" <?= ($product['unit'] == 'DOZEN') ? 'selected' : '' ?>>Dozen</option>
                                                        <option value="OTHER" <?= ($product['unit'] == 'OTHER') ? 'selected' : '' ?>>Other</option>
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
                                                  placeholder="Enter product description"><?= safe_echo($product['description'] ?? '') ?></textarea>
                                    </div>

                                    <!-- Category -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Category</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-tag"></i></span>
                                                    <select name="category_id" class="form-control">
                                                        <option value="">Select Category</option>
                                                        <?php foreach ($categories as $category): ?>
                                                            <option value="<?= $category['id'] ?>" <?= ($product['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($category['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- GST Information Section -->
                                    <div class="gst-info-box">
                                        <h5 class="font-size-14 mb-3">
                                            <i class="mdi mdi-percent me-2"></i>GST Information
                                        </h5>
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">GST Rate (%)</label>
                                                    <input type="number" 
                                                           name="gst_rate" 
                                                           id="gst_rate"
                                                           class="form-control" 
                                                           placeholder="e.g., 18"
                                                           min="0"
                                                           max="100"
                                                           step="0.01"
                                                           value="<?= safe_echo($product['gst_rate'] ?? '') ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">HSN/SAC Code</label>
                                                    <input type="text" 
                                                           name="hsn_code" 
                                                           id="hsn_code"
                                                           class="form-control" 
                                                           placeholder="e.g., 68022190"
                                                           value="<?= safe_echo($product['hsn_code'] ?? '') ?>">
                                                    <small class="text-muted">Harmonized System of Nomenclature code</small>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">GST Type</label>
                                                    <div class="radio-group">
                                                        <label>
                                                            <input type="radio" 
                                                                   name="gst_type" 
                                                                   value="exclusive" 
                                                                   <?= (!isset($product['gst_type']) || $product['gst_type'] == 'exclusive') ? 'checked' : '' ?>>
                                                            Exclusive (GST Extra)
                                                        </label>
                                                        <label>
                                                            <input type="radio" 
                                                                   name="gst_type" 
                                                                   value="inclusive"
                                                                   <?= (isset($product['gst_type']) && $product['gst_type'] == 'inclusive') ? 'checked' : '' ?>>
                                                            Inclusive (GST Included)
                                                        </label>
                                                    </div>
                                                    <small class="text-muted">
                                                        Exclusive: GST added to price | Inclusive: GST included in price
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- GST Preview -->
                                        <div class="gst-preview" id="gstPreview" style="display: none;">
                                            <strong><i class="mdi mdi-calculator me-1"></i>GST Calculation Preview:</strong>
                                            <div class="row mt-2">
                                                <div class="col-md-12">
                                                    <div id="gstPreviewDetails">-</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pricing Information -->
                                    <h5 class="font-size-14 mb-3 mt-4">Pricing Information</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Selling Price (₹)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                                    <input type="number" 
                                                           name="selling_price" 
                                                           id="selling_price"
                                                           class="form-control" 
                                                           placeholder="0.00"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($product['selling_price']) ?>">
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
                                                           value="<?= safe_echo($product['cost_price'] ?? '') ?>">
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
                                                           value="<?= safe_echo($product['current_stock'] ?? '0') ?>">
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
                                                           value="<?= safe_echo($product['reorder_level'] ?? '') ?>">
                                                </div>
                                                <small class="text-muted">Alert when stock falls below this level</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Profit Margin Display -->
                                    <?php if (isset($product['selling_price']) && isset($product['cost_price']) && $product['selling_price'] > 0 && $product['cost_price'] > 0): ?>
                                        <?php
                                        $selling = floatval($product['selling_price']);
                                        $cost = floatval($product['cost_price']);
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
                                                           <?= $product['is_active'] ? 'checked' : '' ?>>
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
                                                <button type="submit" name="update_product" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
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
                                <h5 class="card-title mb-4">Product Information</h5>
                                
                                <div class="info-card mb-3">
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="mdi mdi-barcode-scan me-2"></i>
                                        <strong>Product ID:</strong>
                                        <span class="ms-auto">#<?= $product['id'] ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="mdi mdi-calendar me-2"></i>
                                        <strong>Created:</strong>
                                        <span class="ms-auto"><?= date('d M Y, h:i A', strtotime($product['created_at'])) ?></span>
                                    </div>
                                    <?php if ($product['gst_rate']): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="mdi mdi-percent me-2"></i>
                                        <strong>GST Rate:</strong>
                                        <span class="ms-auto"><span class="gst-badge"><?= $product['gst_rate'] ?>%</span></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($product['gst_type']): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <i class="mdi mdi-calculator me-2"></i>
                                        <strong>GST Type:</strong>
                                        <span class="ms-auto"><?= $product['gst_type'] == 'exclusive' ? 'Exclusive (GST Extra)' : 'Inclusive (GST Included)' ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($product['hsn_code']): ?>
                                    <div class="d-flex align-items-center">
                                        <i class="mdi mdi-code-tags me-2"></i>
                                        <strong>HSN Code:</strong>
                                        <span class="ms-auto"><?= $product['hsn_code'] ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <hr>

                                <h6 class="mb-3">Quick Tips</h6>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Selling price is required</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Cost price helps track profit</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Set reorder level for stock alerts</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Inactive products are hidden from selection</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> GST rate between 0-100%</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Choose GST type correctly for accurate tax calculation</li>
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

<script>
    // GST Preview functionality
    function updateGSTPreview() {
        const gstRate = parseFloat($('#gst_rate').val()) || 0;
        const gstType = $('input[name="gst_type"]:checked').val();
        const sellingPrice = parseFloat($('#selling_price').val()) || 100;
        
        if (gstRate > 0) {
            let gstAmount, totalPrice;
            
            if (gstType === 'inclusive') {
                // GST is included in price
                gstAmount = (sellingPrice * gstRate) / (100 + gstRate);
                totalPrice = sellingPrice;
                $('#gstPreviewDetails').html(`
                    <small>Price: ₹${sellingPrice.toFixed(2)} (Including GST)</small><br>
                    <small>GST Amount: ₹${gstAmount.toFixed(2)} (${gstRate}%)</small><br>
                    <small>Base Price: ₹${(sellingPrice - gstAmount).toFixed(2)}</small>
                `);
            } else {
                // GST is exclusive
                gstAmount = (sellingPrice * gstRate) / 100;
                totalPrice = sellingPrice + gstAmount;
                $('#gstPreviewDetails').html(`
                    <small>Base Price: ₹${sellingPrice.toFixed(2)}</small><br>
                    <small>GST Amount: ₹${gstAmount.toFixed(2)} (${gstRate}%)</small><br>
                    <small>Total Price (with GST): ₹${totalPrice.toFixed(2)}</small>
                `);
            }
            
            $('#gstPreview').show();
        } else {
            $('#gstPreview').hide();
        }
    }
    
    // When GST rate or type changes
    $('#gst_rate, input[name="gst_type"]').on('change input', function() {
        updateGSTPreview();
    });
    
    // When selling price changes
    $('#selling_price').on('input', function() {
        updateGSTPreview();
    });
    
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
                location.reload(); // Reload to get original values
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
                    if (alert.parentNode) alert.remove();
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
            if (input.type !== 'checkbox' && input.type !== 'radio') {
                originalValues[input.name] = input.value;
            } else if (input.type === 'checkbox') {
                originalValues[input.name] = input.checked;
            } else if (input.type === 'radio' && input.checked) {
                originalValues[input.name] = input.value;
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
                if (input.type !== 'checkbox' && input.type !== 'radio') {
                    if (input.value !== originalValues[input.name]) {
                        formDirty = true;
                    }
                } else if (input.type === 'checkbox') {
                    if (input.checked !== originalValues[input.name]) {
                        formDirty = true;
                    }
                } else if (input.type === 'radio' && input.checked) {
                    if (input.value !== originalValues[input.name]) {
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
    
    // Initialize GST preview if values exist
    if ($('#gst_rate').val() > 0) {
        updateGSTPreview();
    }
</script>

</body>
</html>