<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get PO ID from URL
$po_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($po_id <= 0) {
    header("Location: purchase-orders.php?error=1");
    exit();
}

// Initialize variables
$errors = [];
$success_message = '';

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, company_name, gst_number FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get products for dropdown
try {
    $productsStmt = $pdo->query("
        SELECT p.*, c.name as category_name, g.gst_rate, g.id as gst_id 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN gst_details g ON p.gst_id = g.id 
        WHERE p.is_active = 1 
        ORDER BY p.name
    ");
    $products = $productsStmt->fetchAll();
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Get GST details
try {
    $gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
    $gstDetails = $gstStmt->fetchAll();
} catch (Exception $e) {
    $gstDetails = [];
    error_log("Error fetching GST details: " . $e->getMessage());
}

// Fetch existing purchase order
try {
    $stmt = $pdo->prepare("
        SELECT po.*, 
               s.name as supplier_name,
               s.gst_number as supplier_gst
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = :id
    ");
    $stmt->execute([':id' => $po_id]);
    $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase_order) {
        header("Location: purchase-orders.php?error=2");
        exit();
    }

    // Check if PO can be edited (only draft or sent status)
    if (!in_array(strtolower($purchase_order['status']), ['draft', 'sent'])) {
        $errors['status'] = "Cannot edit purchase order with status: " . $purchase_order['status'];
    }

    // Fetch PO items
    $itemsStmt = $pdo->prepare("
        SELECT poi.*, p.name as product_name, p.unit, g.gst_rate, g.id as gst_id
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        LEFT JOIN gst_details g ON poi.gst_id = g.id
        WHERE poi.purchase_order_id = :po_id
        ORDER BY poi.id ASC
    ");
    $itemsStmt->execute([':po_id' => $po_id]);
    $items = $itemsStmt->fetchAll();

} catch (Exception $e) {
    error_log("Error fetching purchase order: " . $e->getMessage());
    header("Location: purchase-orders.php?error=3");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_po') {
    
    if (!in_array(strtolower($purchase_order['status']), ['draft', 'sent'])) {
        $errors['status'] = "Cannot edit purchase order with status: " . $purchase_order['status'];
    } else {
        
        // Get form data
        $po_data = [
            'order_date' => $_POST['order_date'] ?? '',
            'expected_delivery' => $_POST['expected_delivery'] ?? null,
            'supplier_id' => intval($_POST['supplier_id'] ?? 0),
            'payment_terms' => $_POST['payment_terms'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'terms' => $_POST['terms'] ?? '',
            'discount_type' => $_POST['discount_type'] ?? 'percentage',
            'discount_value' => floatval($_POST['discount_value'] ?? 0),
            'shipping_charge' => floatval($_POST['shipping_charge'] ?? 0)
        ];
        
        // Get items from JSON
        $items_json = $_POST['items'] ?? '[]';
        $new_items = json_decode($items_json, true);
        
        // Validation
        if (empty($po_data['supplier_id'])) {
            $errors['supplier'] = 'Please select a supplier';
        }
        
        if (empty($po_data['order_date'])) {
            $errors['order_date'] = 'Order date is required';
        }
        
        if (empty($new_items) || count($new_items) === 0) {
            $errors['items'] = 'Please add at least one item';
        }
        
        // Calculate totals
        $subtotal = 0;
        $gst_total = 0;
        
        foreach ($new_items as $item) {
            $quantity = floatval($item['quantity'] ?? 0);
            $unit_price = floatval($item['unit_price'] ?? 0);
            $gst_amount = floatval($item['gst_amount'] ?? 0);
            
            $subtotal += $quantity * $unit_price;
            $gst_total += $gst_amount;
        }
        
        // Calculate discount
        if ($po_data['discount_type'] === 'percentage') {
            $discount_amount = ($subtotal + $gst_total) * ($po_data['discount_value'] / 100);
        } else {
            $discount_amount = $po_data['discount_value'];
        }
        
        $total_amount = $subtotal + $gst_total - $discount_amount + $po_data['shipping_charge'];
        
        // If no errors, update database
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Update purchase order
                $updateStmt = $pdo->prepare("
                    UPDATE purchase_orders SET
                        order_date = :order_date,
                        expected_delivery = :expected_delivery,
                        supplier_id = :supplier_id,
                        payment_terms = :payment_terms,
                        notes = :notes,
                        terms = :terms,
                        subtotal = :subtotal,
                        gst_total = :gst_total,
                        discount_type = :discount_type,
                        discount_value = :discount_value,
                        discount_amount = :discount_amount,
                        shipping_charge = :shipping_charge,
                        total_amount = :total_amount,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                
                $updateStmt->execute([
                    ':order_date' => $po_data['order_date'],
                    ':expected_delivery' => $po_data['expected_delivery'],
                    ':supplier_id' => $po_data['supplier_id'],
                    ':payment_terms' => $po_data['payment_terms'],
                    ':notes' => $po_data['notes'],
                    ':terms' => $po_data['terms'],
                    ':subtotal' => $subtotal,
                    ':gst_total' => $gst_total,
                    ':discount_type' => $po_data['discount_type'],
                    ':discount_value' => $po_data['discount_value'],
                    ':discount_amount' => $discount_amount,
                    ':shipping_charge' => $po_data['shipping_charge'],
                    ':total_amount' => $total_amount,
                    ':updated_by' => $_SESSION['user_id'] ?? null,
                    ':id' => $po_id
                ]);
                
                // Delete existing items
                $deleteItemsStmt = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = :po_id");
                $deleteItemsStmt->execute([':po_id' => $po_id]);
                
                // Insert new items
                $itemStmt = $pdo->prepare("
                    INSERT INTO purchase_order_items (
                        purchase_order_id, product_id, quantity, unit_price, 
                        gst_id, gst_amount, total_price, created_by, created_at
                    ) VALUES (
                        :po_id, :product_id, :quantity, :unit_price,
                        :gst_id, :gst_amount, :total_price, :created_by, NOW()
                    )
                ");
                
                foreach ($new_items as $item) {
                    $quantity = floatval($item['quantity']);
                    $unit_price = floatval($item['unit_price']);
                    $gst_amount = floatval($item['gst_amount']);
                    $total_price = ($quantity * $unit_price) + $gst_amount;
                    
                    $itemStmt->execute([
                        ':po_id' => $po_id,
                        ':product_id' => intval($item['product_id']),
                        ':quantity' => $quantity,
                        ':unit_price' => $unit_price,
                        ':gst_id' => !empty($item['gst_id']) ? intval($item['gst_id']) : null,
                        ':gst_amount' => $gst_amount,
                        ':total_price' => $total_price,
                        ':created_by' => $_SESSION['user_id'] ?? null
                    ]);
                }
                
                // Log activity if table exists
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by, created_at)
                        VALUES (:user_id, 2, :description, :activity_data, :created_by, NOW())
                    ");
                    $logStmt->execute([
                        ':user_id' => $_SESSION['user_id'] ?? null,
                        ':description' => "Purchase Order updated: " . $purchase_order['po_number'],
                        ':activity_data' => json_encode([
                            'po_id' => $po_id,
                            'po_number' => $purchase_order['po_number'],
                            'supplier_id' => $po_data['supplier_id'],
                            'total_amount' => $total_amount
                        ]),
                        ':created_by' => $_SESSION['user_id'] ?? null
                    ]);
                } catch (Exception $logError) {
                    // Log table might not exist, continue
                    error_log("Activity log error: " . $logError->getMessage());
                }
                
                $pdo->commit();
                
                // Redirect to view page
                header("Location: view-purchase-order.php?id=" . $po_id . "&updated=1");
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors['database'] = "Failed to update purchase order: " . $e->getMessage();
                error_log("PO update error: " . $e->getMessage());
            }
        }
    }
}

// Get status badge class
function getStatusBadge($status) {
    $badges = [
        'draft' => 'secondary',
        'sent' => 'info',
        'confirmed' => 'primary',
        'received' => 'success',
        'cancelled' => 'danger',
        'partial' => 'warning'
    ];
    return $badges[strtolower($status)] ?? 'secondary';
}

// Get status icon
function getStatusIcon($status) {
    $icons = [
        'draft' => 'mdi-pencil',
        'sent' => 'mdi-send',
        'confirmed' => 'mdi-check-circle',
        'received' => 'mdi-truck-check',
        'cancelled' => 'mdi-close-circle',
        'partial' => 'mdi-package-variant'
    ];
    return $icons[strtolower($status)] ?? 'mdi-information';
}

// Format currency
function formatCurrency($amount) {
    return '₹' . number_format(floatval($amount), 2);
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
    <style>
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.5rem 1rem;
        }
        
        .select2-container .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        #supplierInfoCard {
            border-left: 4px solid #556ee6;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
        }
        
        .sticky-top {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .invalid-feedback {
            display: block;
        }
        
        .bg-soft-info {
            background-color: rgba(23, 162, 184, 0.1);
        }
        
        @media print {
            .vertical-menu, .topbar, .footer, .btn, .modal, 
            .page-title-right, .card-title .btn, .no-print {
                display: none !important;
            }
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
                            <h4 class="mb-0 font-size-18">Edit Purchase Order</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="purchase-orders.php">Purchase Orders</a></li>
                                    <li class="breadcrumb-item"><a href="view-purchase-order.php?id=<?= $po_id ?>">View PO</a></li>
                                    <li class="breadcrumb-item active">Edit PO</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i>Purchase order updated successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($errors['status'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($errors['status']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors['database'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($errors['database']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Bar -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                    <div>
                                        <h5 class="mb-1">PO #<?= htmlspecialchars($purchase_order['po_number']) ?></h5>
                                        <p class="text-muted mb-0">Created on <?= date('d M Y', strtotime($purchase_order['order_date'])) ?></p>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <span class="badge bg-<?= getStatusBadge($purchase_order['status']) ?> p-3">
                                            <i class="mdi <?= getStatusIcon($purchase_order['status']) ?> me-1"></i>
                                            <?= ucfirst($purchase_order['status']) ?>
                                        </span>
                                        <a href="view-purchase-order.php?id=<?= $po_id ?>" class="btn btn-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Back
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Form -->
                <?php if (!isset($errors['status'])): ?>
                <form method="POST" action="edit-purchase-order.php?id=<?= $po_id ?>" id="poForm">
                    <input type="hidden" name="action" value="update_po">
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Basic Details Card -->
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Purchase Order Details</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="order_date" class="form-label">Order Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['order_date']) ? 'is-invalid' : '' ?>" 
                                                       id="order_date" name="order_date" value="<?= htmlspecialchars($purchase_order['order_date']) ?>" required>
                                                <?php if (isset($errors['order_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['order_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="expected_delivery" class="form-label">Expected Delivery</label>
                                                <input type="date" class="form-control" id="expected_delivery" name="expected_delivery" 
                                                       value="<?= htmlspecialchars($purchase_order['expected_delivery'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="payment_terms" class="form-label">Payment Terms</label>
                                                <input type="text" class="form-control" id="payment_terms" name="payment_terms" 
                                                       value="<?= htmlspecialchars($purchase_order['payment_terms'] ?? '') ?>"
                                                       placeholder="e.g., Net 30">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="supplier_id" class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                                <select class="form-control select2 <?= isset($errors['supplier']) ? 'is-invalid' : '' ?>" 
                                                        id="supplier_id" name="supplier_id" required>
                                                    <option value="">Search and select supplier...</option>
                                                    <?php foreach ($suppliers as $supplier): ?>
                                                    <option value="<?= $supplier['id'] ?>" 
                                                            data-gst="<?= htmlspecialchars($supplier['gst_number'] ?? '') ?>"
                                                            <?= $purchase_order['supplier_id'] == $supplier['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($supplier['name']) ?> 
                                                        <?= $supplier['company_name'] ? ' (' . htmlspecialchars($supplier['company_name']) . ')' : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['supplier'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['supplier'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Supplier Info Card -->
                                    <div id="supplierInfoCard" class="mt-2" style="display: <?= $purchase_order['supplier_id'] ? 'block' : 'none' ?>;">
                                        <p class="mb-1"><i class="mdi mdi-certificate text-primary me-1"></i> <strong>GST Number:</strong> <span id="supplierGst"><?= htmlspecialchars($purchase_order['supplier_gst'] ?? '-') ?></span></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Items Card -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h4 class="card-title mb-0">Order Items</h4>
                                        <button type="button" class="btn btn-primary" id="addItemBtn">
                                            <i class="mdi mdi-plus"></i> Add Item
                                        </button>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-centered mb-0" id="itemsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Quantity</th>
                                                    <th>Unit Price</th>
                                                    <th>GST %</th>
                                                    <th>GST Amount</th>
                                                    <th>Total</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                                <!-- Items will be loaded here -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (isset($errors['items'])): ?>
                                    <div class="text-danger mt-2"><?= $errors['items'] ?></div>
                                    <?php endif; ?>

                                    <input type="hidden" name="items" id="itemsInput" value="">
                                </div>
                            </div>

                            <!-- Notes and Terms -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="notes" class="form-label">Notes</label>
                                                <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes..."><?= htmlspecialchars($purchase_order['notes'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="terms" class="form-label">Terms & Conditions</label>
                                                <textarea class="form-control" id="terms" name="terms" rows="3" placeholder="Terms and conditions..."><?= htmlspecialchars($purchase_order['terms'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Summary Card -->
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Order Summary</h4>

                                    <div class="mb-4">
                                        <label class="form-label">Discount</label>
                                        <div class="row g-2">
                                            <div class="col-5">
                                                <select class="form-control" id="discount_type" name="discount_type">
                                                    <option value="percentage" <?= ($purchase_order['discount_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= ($purchase_order['discount_type'] ?? '') == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="number" class="form-control" id="discount_value" name="discount_value" 
                                                       value="<?= htmlspecialchars($purchase_order['discount_value'] ?? 0) ?>" min="0" step="0.01">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="shipping_charge" class="form-label">Shipping Charge</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control" id="shipping_charge" name="shipping_charge" 
                                                   value="<?= htmlspecialchars($purchase_order['shipping_charge'] ?? 0) ?>" min="0" step="0.01">
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-borderless mb-0">
                                            <tr>
                                                <td>Subtotal:</td>
                                                <td class="text-end" id="summarySubtotal"><?= formatCurrency($purchase_order['subtotal'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td>GST Total:</td>
                                                <td class="text-end" id="summaryGst"><?= formatCurrency($purchase_order['gst_total'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Discount:</td>
                                                <td class="text-end" id="summaryDiscount">-<?= formatCurrency($purchase_order['discount_amount'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Shipping:</td>
                                                <td class="text-end" id="summaryShipping"><?= formatCurrency($purchase_order['shipping_charge'] ?? 0) ?></td>
                                            </tr>
                                            <tr class="border-top">
                                                <th>Total Amount:</th>
                                                <th class="text-end" id="summaryTotal"><?= formatCurrency($purchase_order['total_amount'] ?? 0) ?></th>
                                            </tr>
                                        </table>
                                    </div>

                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="mdi mdi-content-save"></i> Update Purchase Order
                                        </button>
                                        <a href="view-purchase-order.php?id=<?= $po_id ?>" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Card -->
                            <div class="card bg-soft-info">
                                <div class="card-body">
                                    <h5 class="card-title mb-3">
                                        <i class="mdi mdi-information me-1"></i> 
                                        Edit Information
                                    </h5>
                                    <p class="mb-2">Current Status: <span class="badge bg-<?= getStatusBadge($purchase_order['status']) ?>"><?= ucfirst($purchase_order['status']) ?></span></p>
                                    <p class="text-muted mb-0">
                                        <i class="mdi mdi-alert-circle me-1"></i>
                                        You can only edit orders in <strong>Draft</strong> or <strong>Sent</strong> status.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

                <!-- Product Selection Modal -->
                <div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Select Product</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <input type="text" class="form-control" id="productSearch" placeholder="Search products...">
                                </div>
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover">
                                        <thead class="sticky-top bg-white">
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Price</th>
                                                <th>GST %</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="productList">
                                            <?php foreach ($products as $product): ?>
                                            <tr class="product-row" data-product-id="<?= $product['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                                data-product-price="<?= $product['purchase_price'] ?? $product['selling_price'] ?>"
                                                data-product-gst="<?= $product['gst_rate'] ?? 0 ?>"
                                                data-product-gst-id="<?= $product['gst_id'] ?? '' ?>">
                                                <td><?= htmlspecialchars($product['name']) ?></td>
                                                <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($product['unit']) ?></td>
                                                <td>₹<?= number_format($product['purchase_price'] ?? $product['selling_price'], 2) ?></td>
                                                <td><?= $product['gst_rate'] ?? 0 ?>%</td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary select-product">
                                                        <i class="mdi mdi-plus-circle"></i> Select
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

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
    // Initialize Select2
    $(document).ready(function() {
        $('#supplier_id').select2({
            placeholder: 'Search and select supplier...',
            allowClear: true,
            width: '100%'
        });
        
        // Load existing items
        loadItems();
        
        // Set initial supplier info
        const selectedSupplier = $('#supplier_id').find(':selected');
        if (selectedSupplier.val()) {
            const gst = selectedSupplier.data('gst') || '-';
            $('#supplierGst').text(gst);
            $('#supplierInfoCard').show();
        }
    });

    // Load existing items from PHP
    function loadItems() {
        <?php 
        $itemCount = 0;
        foreach ($items as $item): 
            $itemCount++;
        ?>
        addItemToTable(
            <?= $item['product_id'] ?>,
            '<?= htmlspecialchars(addslashes($item['product_name'])) ?>',
            <?= $item['unit_price'] ?>,
            <?= $item['gst_rate'] ?? 0 ?>,
            <?= $item['gst_id'] ?: 'null' ?>,
            <?= $item['quantity'] ?>
        );
        <?php endforeach; ?>
        
        <?php if ($itemCount > 0): ?>
        // Update summary after loading items
        setTimeout(updateSummary, 100);
        <?php endif; ?>
    }

    // Supplier selection handler
    $('#supplier_id').on('change', function() {
        const selected = $(this).find(':selected');
        if (selected.val()) {
            const gst = selected.data('gst') || '-';
            $('#supplierGst').text(gst);
            $('#supplierInfoCard').show();
        } else {
            $('#supplierInfoCard').hide();
        }
    });

    // Add item button click
    $('#addItemBtn').click(function() {
        $('#productModal').modal('show');
    });

    // Product search
    $('#productSearch').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.product-row').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    });

    // Select product
    $(document).on('click', '.select-product', function() {
        const row = $(this).closest('.product-row');
        addItemToTable(
            row.data('product-id'),
            row.data('product-name'),
            row.data('product-price'),
            row.data('product-gst'),
            row.data('product-gst-id'),
            1 // Default quantity
        );
        $('#productModal').modal('hide');
        $('#productSearch').val('');
        $('.product-row').show();
    });

    // Add item to table
    function addItemToTable(productId, productName, price, gstRate, gstId, quantity = 1) {
        const itemId = 'item_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        const html = `
            <tr data-item-id="${itemId}" data-product-id="${productId}" data-gst-id="${gstId}" data-gst-rate="${gstRate}">
                <td>
                    <input type="text" class="form-control form-control-sm" value="${productName}" readonly>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm quantity" value="${quantity}" min="0.01" step="0.01" required>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm unit-price" value="${price}" min="0" step="0.01" required>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm gst-rate" value="${gstRate}%" readonly>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm gst-amount" value="0.00" readonly>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm total" value="0.00" readonly>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-item">
                        <i class="mdi mdi-delete"></i>
                    </button>
                </td>
            </tr>
        `;
        
        $('#itemsBody').append(html);
        
        const row = $(`tr[data-item-id="${itemId}"]`);
        row.find('.quantity, .unit-price').on('input', function() {
            calculateRowTotal(row);
            updateSummary();
        });
        
        row.find('.remove-item').click(function() {
            row.remove();
            updateSummary();
        });
        
        calculateRowTotal(row);
        updateSummary();
    }

    // Calculate row total
    function calculateRowTotal(row) {
        const quantity = parseFloat(row.find('.quantity').val()) || 0;
        const unitPrice = parseFloat(row.find('.unit-price').val()) || 0;
        const gstRate = parseFloat(row.data('gst-rate')) || 0;
        
        const subtotal = quantity * unitPrice;
        const gstAmount = subtotal * (gstRate / 100);
        const total = subtotal + gstAmount;
        
        row.find('.gst-amount').val(gstAmount.toFixed(2));
        row.find('.total').val(total.toFixed(2));
    }

    // Update summary
    function updateSummary() {
        let subtotal = 0;
        let gstTotal = 0;
        
        $('#itemsBody tr').each(function() {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const unitPrice = parseFloat($(this).find('.unit-price').val()) || 0;
            const gstAmount = parseFloat($(this).find('.gst-amount').val()) || 0;
            
            subtotal += quantity * unitPrice;
            gstTotal += gstAmount;
        });
        
        const discountType = $('#discount_type').val();
        let discountValue = parseFloat($('#discount_value').val()) || 0;
        let discountAmount = 0;
        
        if (discountType === 'percentage') {
            discountAmount = (subtotal + gstTotal) * (discountValue / 100);
        } else {
            discountAmount = discountValue;
        }
        
        const shipping = parseFloat($('#shipping_charge').val()) || 0;
        const total = subtotal + gstTotal - discountAmount + shipping;
        
        $('#summarySubtotal').text('₹' + subtotal.toFixed(2));
        $('#summaryGst').text('₹' + gstTotal.toFixed(2));
        $('#summaryDiscount').text('-₹' + discountAmount.toFixed(2));
        $('#summaryShipping').text('₹' + shipping.toFixed(2));
        $('#summaryTotal').text('₹' + total.toFixed(2));
        
        // Update hidden input
        updateItemsInput();
    }

    // Update hidden items input
    function updateItemsInput() {
        const items = [];
        
        $('#itemsBody tr').each(function() {
            const row = $(this);
            items.push({
                product_id: row.data('product-id'),
                quantity: parseFloat(row.find('.quantity').val()) || 0,
                unit_price: parseFloat(row.find('.unit-price').val()) || 0,
                gst_id: row.data('gst-id') || null,
                gst_rate: parseFloat(row.data('gst-rate')) || 0,
                gst_amount: parseFloat(row.find('.gst-amount').val()) || 0
            });
        });
        
        $('#itemsInput').val(JSON.stringify(items));
    }

    // Event listeners for summary updates
    $('#discount_type, #discount_value, #shipping_charge').on('change input', function() {
        updateSummary();
    });

    // Form submission validation
    $('#poForm').submit(function(e) {
        if ($('#itemsBody tr').length === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'No Items',
                text: 'Please add at least one item to the purchase order',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (!$('#supplier_id').val()) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Supplier Required',
                text: 'Please select a supplier',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        // Update items input before submission
        updateItemsInput();
        return true;
    });

    // Auto-hide alerts
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
</script>

</body>
</html>