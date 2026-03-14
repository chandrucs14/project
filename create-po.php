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

// Generate unique PO number
function generatePONumber($pdo) {
    $prefix = 'PO';
    $year = date('y');
    $month = date('m');
    
    // Get the last PO number
    $stmt = $pdo->query("SELECT po_number FROM purchase_orders WHERE po_number LIKE 'PO%' ORDER BY id DESC LIMIT 1");
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        // Extract the sequence number (last 4 digits)
        $sequence = intval(substr($lastNumber, -4)) + 1;
        $newSequence = str_pad($sequence, 4, '0', STR_PAD_LEFT);
    } else {
        $newSequence = '0001';
    }
    
    return $prefix . $year . $month . $newSequence;
}

// Fetch suppliers for dropdown
$suppStmt = $pdo->query("SELECT id, name, supplier_code, company_name, phone, email, address, city, state, gst_number FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppStmt->fetchAll();

// Fetch products for dropdown
$prodStmt = $pdo->query("
    SELECT p.*, c.name as category_name, g.gst_rate 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN gst_details g ON p.gst_id = g.id 
    WHERE p.is_active = 1 
    ORDER BY p.name
");
$products = $prodStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_po'])) {
    
    $supplier_id = (int)$_POST['supplier_id'];
    $order_date = $_POST['order_date'];
    $expected_delivery = !empty($_POST['expected_delivery']) ? $_POST['expected_delivery'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Get product items from form
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    
    // Validation
    if ($supplier_id <= 0) {
        $error = "Please select a supplier.";
    } elseif (empty($product_ids)) {
        $error = "Please add at least one product to the purchase order.";
    } elseif (empty($order_date)) {
        $error = "Order date is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get supplier details
            $suppStmt = $pdo->prepare("SELECT name, supplier_code FROM suppliers WHERE id = ?");
            $suppStmt->execute([$supplier_id]);
            $supplier = $suppStmt->fetch();
            
            if (!$supplier) {
                throw new Exception("Supplier not found.");
            }
            
            // Calculate totals
            $subtotal = 0;
            $gst_total = 0;
            $total_amount = 0;
            $items = [];
            
            foreach ($product_ids as $index => $product_id) {
                if (empty($product_id)) continue;
                
                $quantity = floatval($quantities[$index] ?? 0);
                $price = floatval($prices[$index] ?? 0);
                
                if ($quantity <= 0 || $price <= 0) continue;
                
                // Get product details for GST calculation
                $prodStmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                $prodStmt->execute([$product_id]);
                $product = $prodStmt->fetch();
                
                if (!$product) continue;
                
                // Get GST rate if applicable
                $gst_rate = 0;
                $gst_amount = 0;
                if ($product['gst_id']) {
                    $gstStmt = $pdo->prepare("SELECT gst_rate FROM gst_details WHERE id = ?");
                    $gstStmt->execute([$product['gst_id']]);
                    $gst = $gstStmt->fetch();
                    $gst_rate = $gst['gst_rate'] ?? 0;
                    $gst_amount = ($price * $quantity) * ($gst_rate / 100);
                }
                
                $item_total = $price * $quantity;
                $subtotal += $item_total;
                $gst_total += $gst_amount;
                
                $items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'gst_id' => $product['gst_id'],
                    'gst_amount' => $gst_amount,
                    'total' => $item_total + $gst_amount
                ];
            }
            
            $total_amount = $subtotal + $gst_total;
            
            // Generate PO number
            $po_number = generatePONumber($pdo);
            
            // Insert purchase order
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    po_number, supplier_id, order_date, expected_delivery, 
                    subtotal, gst_total, discount_amount, total_amount, 
                    status, notes, created_by, created_at
                ) VALUES (
                    :po_number, :supplier_id, :order_date, :expected_delivery,
                    :subtotal, :gst_total, 0, :total_amount,
                    'draft', :notes, :created_by, NOW()
                )
            ");
            
            $params = [
                ':po_number' => $po_number,
                ':supplier_id' => $supplier_id,
                ':order_date' => $order_date,
                ':expected_delivery' => $expected_delivery,
                ':subtotal' => $subtotal,
                ':gst_total' => $gst_total,
                ':total_amount' => $total_amount,
                ':notes' => $notes ?: null,
                ':created_by' => $_SESSION['user_id']
            ];
            
            $result = $stmt->execute($params);
            
            if ($result) {
                $po_id = $pdo->lastInsertId();
                
                // Insert purchase order items
                $itemStmt = $pdo->prepare("
                    INSERT INTO purchase_order_items (
                        purchase_order_id, product_id, quantity, received_quantity, 
                        unit_price, gst_id, gst_amount, total_price, created_at
                    ) VALUES (
                        ?, ?, ?, 0, ?, ?, ?, ?, NOW()
                    )
                ");
                
                foreach ($items as $item) {
                    $itemStmt->execute([
                        $po_id,
                        $item['product_id'],
                        $item['quantity'],
                        $item['price'],
                        $item['gst_id'],
                        $item['gst_amount'],
                        $item['total']
                    ]);
                }
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'po_id' => $po_id,
                    'po_number' => $po_number,
                    'supplier_name' => $supplier['name'],
                    'total_amount' => $total_amount,
                    'item_count' => count($items)
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "New purchase order created: #$po_number for " . $supplier['name'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "Purchase order created successfully. PO Number: " . $po_number;
                header("Location: view-purchase-order.php?id=" . $po_id);
                exit();
            } else {
                $pdo->rollBack();
                $error = "Failed to create purchase order. Please try again.";
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
            error_log("PO creation error: " . $e->getMessage());
        }
    }
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
        .product-row {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            position: relative;
            border-left: 4px solid #556ee6;
        }
        .remove-product {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
            color: #dc3545;
            font-size: 20px;
        }
        .remove-product:hover {
            color: #c82333;
        }
        .totals-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .totals-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .totals-item.total {
            font-size: 20px;
            font-weight: bold;
            border-top: 1px solid rgba(255,255,255,0.3);
            padding-top: 10px;
            margin-top: 10px;
        }
        .supplier-info {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #34c38f;
        }
        .product-info-badge {
            background-color: #e9ecef;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
        }
        .gst-badge {
            background-color: #556ee6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
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
                            <h4 class="mb-0 font-size-18">Create Purchase Order</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="purchase-orders.php">Purchase Orders</a></li>
                                    <li class="breadcrumb-item active">Create PO</li>
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
                                <h4 class="card-title mb-4">Purchase Order Information</h4>

                                <form method="POST" action="" id="poForm">
                                    <!-- Supplier Selection -->
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                            <select name="supplier_id" id="supplier_id" class="form-control" required onchange="loadSupplierDetails(this.value)">
                                                <option value="">Choose supplier...</option>
                                                <?php foreach ($suppliers as $supp): ?>
                                                    <option value="<?= $supp['id'] ?>" 
                                                            data-code="<?= $supp['supplier_code'] ?>"
                                                            data-company="<?= $supp['company_name'] ?>"
                                                            data-phone="<?= $supp['phone'] ?>"
                                                            data-email="<?= $supp['email'] ?>"
                                                            data-address="<?= $supp['address'] ?>"
                                                            data-city="<?= $supp['city'] ?>"
                                                            data-state="<?= $supp['state'] ?>"
                                                            data-gst="<?= $supp['gst_number'] ?>">
                                                        <?= htmlspecialchars($supp['name']) ?> 
                                                        <?php if ($supp['company_name']): ?>
                                                            (<?= htmlspecialchars($supp['company_name']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">&nbsp;</label>
                                            <a href="add-supplier.php" class="btn btn-success w-100" target="_blank">
                                                <i class="mdi mdi-truck-plus me-1"></i> New Supplier
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Supplier Details Display -->
                                    <div id="supplier_details" class="supplier-info" style="display: none;">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Code:</strong> <span id="supp_code"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Phone:</strong> <span id="supp_phone"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Email:</strong> <span id="supp_email"></span>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-4">
                                                <strong>GST:</strong> <span id="supp_gst"></span>
                                            </div>
                                            <div class="col-md-8">
                                                <strong>Address:</strong> <span id="supp_address"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- PO Dates -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Order Date <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Expected Delivery Date</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="expected_delivery" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Products Section -->
                                    <h5 class="font-size-14 mb-3">Products</h5>
                                    
                                    <div id="products-container">
                                        <!-- Product rows will be added here -->
                                        <div class="product-row" id="product-row-0">
                                            <div class="remove-product" onclick="removeProduct(0)" title="Remove Product">
                                                <i class="mdi mdi-close-circle"></i>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-5">
                                                    <div class="mb-2">
                                                        <label class="form-label">Select Product</label>
                                                        <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, 0)" required>
                                                            <option value="">Choose product...</option>
                                                            <?php foreach ($products as $prod): ?>
                                                                <option value="<?= $prod['id'] ?>" 
                                                                        data-price="<?= $prod['cost_price'] ?? $prod['selling_price'] ?>"
                                                                        data-stock="<?= $prod['current_stock'] ?>"
                                                                        data-unit="<?= $prod['unit'] ?>"
                                                                        data-gst="<?= $prod['gst_rate'] ?? 0 ?>"
                                                                        data-category="<?= $prod['category_name'] ?? 'Uncategorized' ?>">
                                                                    <?= htmlspecialchars($prod['name']) ?> 
                                                                    <small>(<?= htmlspecialchars($prod['category_name'] ?? 'Uncategorized') ?>)</small>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" name="quantity[]" class="form-control quantity" value="1" min="0.01" step="0.01" onchange="calculateRow(0)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Unit Price (₹)</label>
                                                        <input type="number" name="price[]" class="form-control price" value="0" min="0" step="0.01" onchange="calculateRow(0)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-2">
                                                        <label class="form-label">Total (₹)</label>
                                                        <input type="text" class="form-control row-total" id="row-total-0" value="0.00" readonly>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <small class="text-muted">
                                                        <i class="mdi mdi-package-variant me-1"></i>Current Stock: <span id="stock-0">0</span> <span id="unit-0"></span>
                                                        <span class="product-info-badge" id="category-0"></span>
                                                    </small>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted">
                                                        <i class="mdi mdi-percent me-1"></i>GST: <span id="gst-0">0%</span>
                                                        <span class="gst-badge" id="gst-amount-0">₹0.00</span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Add More Products Button -->
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <button type="button" class="btn btn-primary" onclick="addProduct()">
                                                <i class="mdi mdi-plus me-1"></i> Add More Product
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label class="form-label">PO Notes / Instructions</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions for supplier..."></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <hr>
                                            <div class="text-end">
                                                <a href="purchase-orders.php" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="submit" name="create_po" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Create Purchase Order</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Creating...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary Sidebar -->
                    <div class="col-lg-4">
                        <div class="totals-card">
                            <h5 class="text-white mb-3">PO Summary</h5>
                            <div class="totals-item">
                                <span>Subtotal:</span>
                                <span id="summary-subtotal">₹0.00</span>
                            </div>
                            <div class="totals-item">
                                <span>GST Total:</span>
                                <span id="summary-gst">₹0.00</span>
                            </div>
                            <div class="totals-item total">
                                <span>Total Amount:</span>
                                <span id="summary-total">₹0.00</span>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Quick Tips</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Select a supplier first</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Add products to the order</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Cost price will be auto-filled</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> GST is calculated automatically</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> PO will be created as 'Draft'</li>
                                </ul>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Recent Products</h5>
                                <div class="list-group">
                                    <?php 
                                    $recentStmt = $pdo->query("SELECT name, cost_price, selling_price FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
                                    $recentProducts = $recentStmt->fetchAll();
                                    foreach ($recentProducts as $rp): 
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><?= htmlspecialchars($rp['name']) ?></span>
                                            <span class="badge bg-primary">₹<?= number_format($rp['cost_price'] ?? $rp['selling_price'], 2) ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">PO Status Guide</h5>
                                <div class="mb-2">
                                    <span class="badge bg-secondary">Draft</span> - Initial creation
                                </div>
                                <div class="mb-2">
                                    <span class="badge bg-primary">Sent</span> - Sent to supplier
                                </div>
                                <div class="mb-2">
                                    <span class="badge bg-info">Confirmed</span> - Supplier confirmed
                                </div>
                                <div class="mb-2">
                                    <span class="badge bg-warning">Partially Received</span> - Some items received
                                </div>
                                <div class="mb-2">
                                    <span class="badge bg-success">Completed</span> - Fully received
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

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    let productCount = 1;
    
    // Form submission loading state
    document.getElementById('poForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        btn.disabled = true;
        btnText.style.display = 'none';
        loading.style.display = 'inline-block';
    });
    
    // Add product row
    function addProduct() {
        const container = document.getElementById('products-container');
        const newRow = document.createElement('div');
        newRow.className = 'product-row';
        newRow.id = `product-row-${productCount}`;
        newRow.innerHTML = `
            <div class="remove-product" onclick="removeProduct(${productCount})" title="Remove Product">
                <i class="mdi mdi-close-circle"></i>
            </div>
            <div class="row">
                <div class="col-md-5">
                    <div class="mb-2">
                        <label class="form-label">Select Product</label>
                        <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, ${productCount})" required>
                            <option value="">Choose product...</option>
                            <?php foreach ($products as $prod): ?>
                                <option value="<?= $prod['id'] ?>" 
                                        data-price="<?= $prod['cost_price'] ?? $prod['selling_price'] ?>"
                                        data-stock="<?= $prod['current_stock'] ?>"
                                        data-unit="<?= $prod['unit'] ?>"
                                        data-gst="<?= $prod['gst_rate'] ?? 0 ?>"
                                        data-category="<?= $prod['category_name'] ?? 'Uncategorized' ?>">
                                    <?= htmlspecialchars($prod['name']) ?> 
                                    <small>(<?= htmlspecialchars($prod['category_name'] ?? 'Uncategorized') ?>)</small>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-2">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity[]" class="form-control quantity" value="1" min="0.01" step="0.01" onchange="calculateRow(${productCount})" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-2">
                        <label class="form-label">Unit Price (₹)</label>
                        <input type="number" name="price[]" class="form-control price" value="0" min="0" step="0.01" onchange="calculateRow(${productCount})" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-2">
                        <label class="form-label">Total (₹)</label>
                        <input type="text" class="form-control row-total" id="row-total-${productCount}" value="0.00" readonly>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="mdi mdi-package-variant me-1"></i>Current Stock: <span id="stock-${productCount}">0</span> <span id="unit-${productCount}"></span>
                        <span class="product-info-badge" id="category-${productCount}"></span>
                    </small>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="mdi mdi-percent me-1"></i>GST: <span id="gst-${productCount}">0%</span>
                        <span class="gst-badge" id="gst-amount-${productCount}">₹0.00</span>
                    </small>
                </div>
            </div>
        `;
        container.appendChild(newRow);
        productCount++;
    }
    
    // Remove product row
    function removeProduct(index) {
        if (document.querySelectorAll('.product-row').length > 1) {
            Swal.fire({
                title: 'Remove Product?',
                text: 'Are you sure you want to remove this product?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f46a6a',
                cancelButtonColor: '#556ee6',
                confirmButtonText: 'Yes, remove it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(`product-row-${index}`).remove();
                    calculateTotals();
                }
            });
        } else {
            Swal.fire({
                title: 'Cannot Remove',
                text: 'At least one product is required',
                icon: 'warning',
                confirmButtonColor: '#556ee6'
            });
        }
    }
    
    // Load product details when selected
    function loadProductDetails(select, index) {
        const selected = select.options[select.selectedIndex];
        if (selected.value) {
            const price = selected.dataset.price;
            const stock = selected.dataset.stock;
            const unit = selected.dataset.unit;
            const gst = selected.dataset.gst;
            const category = selected.dataset.category;
            
            document.querySelector(`#product-row-${index} .price`).value = price;
            document.querySelector(`#stock-${index}`).textContent = stock;
            document.querySelector(`#unit-${index}`).textContent = unit;
            document.querySelector(`#gst-${index}`).textContent = gst + '%';
            document.querySelector(`#category-${index}`).textContent = category;
            
            calculateRow(index);
        }
    }
    
    // Calculate row total
    function calculateRow(index) {
        const row = document.getElementById(`product-row-${index}`);
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const gstRate = parseFloat(document.querySelector(`#gst-${index}`)?.textContent) || 0;
        
        const subtotal = quantity * price;
        const gstAmount = subtotal * (gstRate / 100);
        const total = subtotal + gstAmount;
        
        row.querySelector('.row-total').value = total.toFixed(2);
        document.querySelector(`#gst-amount-${index}`).textContent = '₹' + gstAmount.toFixed(2);
        
        calculateTotals();
    }
    
    // Calculate all totals
    function calculateTotals() {
        let subtotal = 0;
        let gstTotal = 0;
        
        document.querySelectorAll('.product-row').forEach((row, index) => {
            const rowTotal = parseFloat(row.querySelector('.row-total').value) || 0;
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            const gstRate = parseFloat(document.querySelector(`#gst-${index}`)?.textContent) || 0;
            
            const rowSubtotal = quantity * price;
            const rowGst = rowSubtotal * (gstRate / 100);
            
            subtotal += rowSubtotal;
            gstTotal += rowGst;
        });
        
        const total = subtotal + gstTotal;
        
        document.getElementById('summary-subtotal').textContent = '₹' + subtotal.toFixed(2);
        document.getElementById('summary-gst').textContent = '₹' + gstTotal.toFixed(2);
        document.getElementById('summary-total').textContent = '₹' + total.toFixed(2);
    }
    
    // Load supplier details
    function loadSupplierDetails(supplierId) {
        const select = document.getElementById('supplier_id');
        const selected = select.options[select.selectedIndex];
        
        if (supplierId) {
            const code = selected.dataset.code || 'N/A';
            const company = selected.dataset.company || 'N/A';
            const phone = selected.dataset.phone || 'N/A';
            const email = selected.dataset.email || 'N/A';
            const address = selected.dataset.address || '';
            const city = selected.dataset.city || '';
            const state = selected.dataset.state || '';
            const gst = selected.dataset.gst || 'N/A';
            
            document.getElementById('supp_code').textContent = code;
            document.getElementById('supp_phone').textContent = phone;
            document.getElementById('supp_email').textContent = email;
            document.getElementById('supp_gst').textContent = gst;
            
            let fullAddress = address;
            if (city) fullAddress += ', ' + city;
            if (state) fullAddress += ', ' + state;
            document.getElementById('supp_address').textContent = fullAddress;
            
            document.getElementById('supplier_details').style.display = 'block';
        } else {
            document.getElementById('supplier_details').style.display = 'none';
        }
    }
    
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
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Form validation before submit
    document.getElementById('poForm')?.addEventListener('submit', function(e) {
        const supplierId = document.getElementById('supplier_id').value;
        if (!supplierId) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please select a supplier',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        let hasProducts = false;
        let hasValidProduct = false;
        
        document.querySelectorAll('.product-select').forEach(select => {
            if (select.value) {
                hasProducts = true;
                const row = select.closest('.product-row');
                const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                const price = parseFloat(row.querySelector('.price').value) || 0;
                
                if (quantity > 0 && price > 0) {
                    hasValidProduct = true;
                }
            }
        });
        
        if (!hasProducts) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please add at least one product',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (!hasValidProduct) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please enter valid quantity and price for all products',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('poForm').submit();
        }
    });

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('poForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox') {
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