<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Get order ID from URL
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$order_id) {
    header("Location: orders.php");
    exit();
}

// Get order settings
try {
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $settings = [
        'order_prefix' => 'ORD-',
        'default_payment_terms' => '30',
        'company_name' => 'Your Company Name',
        'company_gst' => '22AAAAA0000A1Z5',
        'company_address' => 'Your Company Address'
    ];
}

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code, phone, email, gst_number FROM customers WHERE is_active = 1 ORDER BY name");
    $customers = $customersStmt->fetchAll();
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
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

// Get GST details for reference
try {
    $gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
    $gstDetails = $gstStmt->fetchAll();
} catch (Exception $e) {
    $gstDetails = [];
    error_log("Error fetching GST details: " . $e->getMessage());
}

// Get order details
try {
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, c.customer_code, c.phone, c.email, c.gst_number
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: orders.php");
        exit();
    }

    // Get order items
    $itemsStmt = $pdo->prepare("
        SELECT oi.*, p.name as product_name, p.unit, g.gst_rate
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN gst_details g ON oi.gst_id = g.id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error fetching order: " . $e->getMessage());
    header("Location: orders.php");
    exit();
}

// Initialize variables
$order_data = [
    'order_number' => $order['order_number'],
    'order_date' => $order['order_date'],
    'delivery_date' => $order['delivery_date'],
    'customer_id' => $order['customer_id'],
    'customer_name' => $order['customer_name'],
    'customer_gst' => $order['gst_number'],
    'status' => $order['status'],
    'notes' => $order['notes'],
    'subtotal' => floatval($order['subtotal']),
    'gst_total' => floatval($order['gst_total']),
    'discount_amount' => floatval($order['discount_amount']),
    'discount_type' => 'fixed',
    'discount_value' => floatval($order['discount_amount']),
    'total_amount' => floatval($order['total_amount']),
    'advance_paid' => floatval($order['advance_paid']),
    'balance_amount' => floatval($order['balance_amount']),
    'items' => $items
];

// Calculate discount type and value
$subtotal_with_gst = $order_data['subtotal'] + $order_data['gst_total'];
if ($subtotal_with_gst > 0) {
    $discount_percentage = ($order_data['discount_amount'] / $subtotal_with_gst) * 100;
    if (abs($discount_percentage - round($discount_percentage)) < 0.01) {
        $order_data['discount_type'] = 'percentage';
        $order_data['discount_value'] = round($discount_percentage);
    } else {
        $order_data['discount_type'] = 'fixed';
        $order_data['discount_value'] = $order_data['discount_amount'];
    }
}

$errors = [];
$success_message = '';

// Handle AJAX request for customer details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customer' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    
    try {
        $customer_id = intval($_GET['customer_id']);
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($customer) {
            echo json_encode(['success' => true, 'customer' => $customer]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for product details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_product' && isset($_GET['product_id'])) {
    header('Content-Type: application/json');
    
    try {
        $product_id = intval($_GET['product_id']);
        $stmt = $pdo->prepare("
            SELECT p.*, g.gst_rate, g.id as gst_id 
            FROM products p 
            LEFT JOIN gst_details g ON p.gst_id = g.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_order') {
    
    // Get form data
    $order_data['order_date'] = $_POST['order_date'] ?? $order_data['order_date'];
    $order_data['delivery_date'] = $_POST['delivery_date'] ?? null;
    $order_data['customer_id'] = intval($_POST['customer_id'] ?? $order_data['customer_id']);
    $order_data['status'] = $_POST['status'] ?? $order_data['status'];
    $order_data['notes'] = $_POST['notes'] ?? '';
    $order_data['discount_type'] = $_POST['discount_type'] ?? 'fixed';
    $order_data['discount_value'] = floatval($_POST['discount_value'] ?? 0);
    $order_data['advance_paid'] = floatval($_POST['advance_paid'] ?? 0);
    
    // Get order items from JSON
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    
    // Validation
    if (empty($order_data['customer_id'])) {
        $errors['customer'] = 'Please select a customer';
    }
    
    if (empty($order_data['order_date'])) {
        $errors['order_date'] = 'Order date is required';
    }
    
    if (empty($items) || count($items) === 0) {
        $errors['items'] = 'Please add at least one item to the order';
    }
    
    // Calculate totals
    $subtotal = 0;
    $gst_total = 0;
    
    foreach ($items as $item) {
        $quantity = floatval($item['quantity'] ?? 0);
        $unit_price = floatval($item['unit_price'] ?? 0);
        $item_subtotal = $quantity * $unit_price;
        $item_gst = floatval($item['gst_amount'] ?? 0);
        
        $subtotal += $item_subtotal;
        $gst_total += $item_gst;
    }
    
    $order_data['subtotal'] = $subtotal;
    $order_data['gst_total'] = $gst_total;
    
    // Calculate discount
    if ($order_data['discount_type'] === 'percentage') {
        $order_data['discount_amount'] = ($subtotal + $gst_total) * ($order_data['discount_value'] / 100);
    } else {
        $order_data['discount_amount'] = $order_data['discount_value'];
    }
    
    $order_data['total_amount'] = $subtotal + $gst_total - $order_data['discount_amount'];
    $order_data['balance_amount'] = $order_data['total_amount'] - $order_data['advance_paid'];
    
    // If no errors, update database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Update order
            $updateStmt = $pdo->prepare("
                UPDATE orders SET
                    order_date = :order_date,
                    delivery_date = :delivery_date,
                    customer_id = :customer_id,
                    status = :status,
                    subtotal = :subtotal,
                    gst_total = :gst_total,
                    discount_amount = :discount_amount,
                    total_amount = :total_amount,
                    advance_paid = :advance_paid,
                    balance_amount = :balance_amount,
                    notes = :notes,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE id = :id
            ");
            
            $updateStmt->execute([
                ':order_date' => $order_data['order_date'],
                ':delivery_date' => $order_data['delivery_date'],
                ':customer_id' => $order_data['customer_id'],
                ':status' => $order_data['status'],
                ':subtotal' => $order_data['subtotal'],
                ':gst_total' => $order_data['gst_total'],
                ':discount_amount' => $order_data['discount_amount'],
                ':total_amount' => $order_data['total_amount'],
                ':advance_paid' => $order_data['advance_paid'],
                ':balance_amount' => $order_data['balance_amount'],
                ':notes' => $order_data['notes'],
                ':updated_by' => $_SESSION['user_id'] ?? null,
                ':id' => $order_id
            ]);
            
            // Delete existing order items
            $deleteItemsStmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
            $deleteItemsStmt->execute([$order_id]);
            
            // Insert new order items
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_id, quantity, delivered_quantity, unit_price, 
                    gst_id, gst_amount, total_price, created_by, created_at
                ) VALUES (
                    :order_id, :product_id, :quantity, :delivered_quantity, :unit_price,
                    :gst_id, :gst_amount, :total_price, :created_by, NOW()
                )
            ");
            
            foreach ($items as $item) {
                $quantity = floatval($item['quantity']);
                $delivered_quantity = floatval($item['delivered_quantity'] ?? 0);
                $unit_price = floatval($item['unit_price']);
                $gst_amount = floatval($item['gst_amount']);
                $total_price = ($quantity * $unit_price) + $gst_amount;
                
                $itemStmt->execute([
                    ':order_id' => $order_id,
                    ':product_id' => intval($item['product_id']),
                    ':quantity' => $quantity,
                    ':delivered_quantity' => $delivered_quantity,
                    ':unit_price' => $unit_price,
                    ':gst_id' => !empty($item['gst_id']) ? intval($item['gst_id']) : null,
                    ':gst_amount' => $gst_amount,
                    ':total_price' => $total_price,
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 4, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "Order updated: " . $order_data['order_number'],
                ':activity_data' => json_encode([
                    'order_id' => $order_id,
                    'order_number' => $order_data['order_number'],
                    'customer_id' => $order_data['customer_id'],
                    'total_amount' => $order_data['total_amount']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            $success_message = "Order updated successfully!";
            
            // Redirect to view order page
            header("Location: view-order.php?id=" . $order_id . "&success=1");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to update order: " . $e->getMessage();
            error_log("Order update error: " . $e->getMessage());
        }
    }
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
                            <h4 class="mb-0 font-size-18">Edit Order #<?= htmlspecialchars($order['order_number']) ?></h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="orders.php">Orders</a></li>
                                    <li class="breadcrumb-item"><a href="view-order.php?id=<?= $order_id ?>">View Order</a></li>
                                    <li class="breadcrumb-item active">Edit Order</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i>
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Order Form -->
                <form method="POST" action="edit-order.php?id=<?= $order_id ?>" id="orderForm">
                    <input type="hidden" name="action" value="update_order">
                    <div class="row">
                        <!-- Left Column - Order Details -->
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Order Details</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="order_number" class="form-label">Order Number</label>
                                                <input type="text" class="form-control" id="order_number" 
                                                       value="<?= htmlspecialchars($order['order_number']) ?>" readonly>
                                                <small class="text-muted">Order number cannot be changed</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="order_date" class="form-label">Order Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['order_date']) ? 'is-invalid' : '' ?>" 
                                                       id="order_date" name="order_date" value="<?= htmlspecialchars($order_data['order_date']) ?>" required>
                                                <?php if (isset($errors['order_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['order_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="delivery_date" class="form-label">Expected Delivery</label>
                                                <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                                                       value="<?= htmlspecialchars($order_data['delivery_date'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="customer_id" class="form-label">Select Customer <span class="text-danger">*</span></label>
                                                <select class="form-control select2 <?= isset($errors['customer']) ? 'is-invalid' : '' ?>" 
                                                        id="customer_id" name="customer_id" required>
                                                    <option value="">Search and select customer...</option>
                                                    <?php foreach ($customers as $customer): ?>
                                                    <option value="<?= $customer['id'] ?>" 
                                                            <?= $customer['id'] == $order_data['customer_id'] ? 'selected' : '' ?>
                                                            data-gst="<?= htmlspecialchars($customer['gst_number'] ?? '') ?>">
                                                        <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['customer_code']) ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['customer'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['customer'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                       
                                    </div>

                                    <!-- Customer Info Card -->
                                    <div id="customerInfoCard" class="card bg-light mt-2" style="display: <?= $order_data['customer_id'] ? 'block' : 'none' ?>;">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p class="mb-1"><i class="bi bi-building me-1"></i> <strong>GST Number:</strong> <span id="customerGst"><?= htmlspecialchars($order_data['customer_gst'] ?? '-') ?></span></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="mb-1"><i class="bi bi-telephone me-1"></i> <strong>Phone:</strong> <span id="customerPhone"><?= htmlspecialchars($order['phone'] ?? 'N/A') ?></span></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Order Items Card -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h4 class="card-title mb-0">Order Items</h4>
                                        <button type="button" class="btn btn-primary" id="addItemBtn">
                                            <i class="mdi mdi-plus"></i> Add Item
                                        </button>
                                    </div>

                                    <!-- Items Table -->
                                    <div class="table-responsive">
                                        <table class="table table-centered mb-0" id="itemsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 30%">Product</th>
                                                    <th style="width: 10%">Quantity</th>
                                                    <th style="width: 10%">Delivered</th>
                                                    <th style="width: 15%">Unit Price</th>
                                                    <th style="width: 10%">GST %</th>
                                                    <th style="width: 10%">GST Amount</th>
                                                    <th style="width: 15%">Total</th>
                                                    <th style="width: 10%">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                                <?php foreach ($order_data['items'] as $index => $item): ?>
                                                <tr data-item-id="<?= $index ?>" 
                                                    data-product-id="<?= $item['product_id'] ?>" 
                                                    data-gst-id="<?= $item['gst_id'] ?>" 
                                                    data-gst-rate="<?= $item['gst_rate'] ?? 0 ?>">
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($item['product_name']) ?>" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm quantity" value="<?= $item['quantity'] ?>" min="0.01" step="0.01">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm delivered-qty" value="<?= $item['delivered_quantity'] ?? 0 ?>" min="0" step="0.01" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm unit-price" value="<?= $item['unit_price'] ?>" min="0" step="0.01">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm gst-rate" value="<?= $item['gst_rate'] ?? 0 ?>%" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm gst-amount" value="<?= number_format($item['gst_amount'], 2) ?>" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm total" value="<?= number_format(($item['quantity'] * $item['unit_price']) + $item['gst_amount'], 2) ?>" readonly>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger remove-item">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (isset($errors['items'])): ?>
                                    <div class="text-danger mt-2"><?= $errors['items'] ?></div>
                                    <?php endif; ?>

                                    <!-- Hidden input to store items JSON -->
                                    <input type="hidden" name="items" id="itemsInput" value='<?= json_encode($order_data['items']) ?>'>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Additional notes..."><?= htmlspecialchars($order_data['notes']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Summary -->
                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Order Summary</h4>

                                    <!-- Discount Section -->
                                    <div class="mb-4">
                                        <label class="form-label">Discount</label>
                                        <div class="row g-2">
                                            <div class="col-5">
                                                <select class="form-control" id="discount_type" name="discount_type">
                                                    <option value="percentage" <?= $order_data['discount_type'] == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= $order_data['discount_type'] == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="number" class="form-control" id="discount_value" name="discount_value" 
                                                       value="<?= $order_data['discount_value'] ?>" min="0" step="0.01" placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Advance Payment -->
                                    <div class="mb-4">
                                        <label for="advance_paid" class="form-label">Advance Paid</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control" id="advance_paid" name="advance_paid" 
                                                   value="<?= $order_data['advance_paid'] ?>" min="0" step="0.01">
                                        </div>
                                    </div>

                                    <!-- Summary Calculations -->
                                    <div class="table-responsive">
                                        <table class="table table-borderless mb-0">
                                            <tr>
                                                <td>Subtotal:</td>
                                                <td class="text-end" id="summarySubtotal">₹<?= number_format($order_data['subtotal'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>GST Total:</td>
                                                <td class="text-end" id="summaryGst">₹<?= number_format($order_data['gst_total'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Discount:</td>
                                                <td class="text-end" id="summaryDiscount">-₹<?= number_format($order_data['discount_amount'], 2) ?></td>
                                            </tr>
                                            <tr class="border-top">
                                                <th>Total Amount:</th>
                                                <th class="text-end" id="summaryTotal">₹<?= number_format($order_data['total_amount'], 2) ?></th>
                                            </tr>
                                            <tr>
                                                <td>Advance Paid:</td>
                                                <td class="text-end text-success" id="summaryAdvance">₹<?= number_format($order_data['advance_paid'], 2) ?></td>
                                            </tr>
                                            <tr class="border-top">
                                                <th>Balance Amount:</th>
                                                <th class="text-end" id="summaryBalance">₹<?= number_format($order_data['balance_amount'], 2) ?></th>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="mdi mdi-content-save"></i> Update Order
                                        </button>
                                        <a href="view-order.php?id=<?= $order_id ?>" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-eye"></i> View Order
                                        </a>
                                        <a href="orders.php" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Back to Orders
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

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
                                                <th>Selling Price</th>
                                                <th>GST %</th>
                                                <th>Stock</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="productList">
                                            <?php foreach ($products as $product): ?>
                                            <tr class="product-row" data-product-id="<?= $product['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                                data-product-price="<?= $product['selling_price'] ?>"
                                                data-product-gst="<?= $product['gst_rate'] ?? 0 ?>"
                                                data-product-gst-id="<?= $product['gst_id'] ?? '' ?>"
                                                data-product-stock="<?= $product['current_stock'] ?>">
                                                <td><?= htmlspecialchars($product['name']) ?></td>
                                                <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($product['unit']) ?></td>
                                                <td>₹<?= number_format($product['selling_price'], 2) ?></td>
                                                <td><?= $product['gst_rate'] ?? 0 ?>%</td>
                                                <td>
                                                    <span class="badge bg-<?= $product['current_stock'] > 10 ? 'success' : ($product['current_stock'] > 0 ? 'warning' : 'danger') ?>">
                                                        <?= $product['current_stock'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary select-product">
                                                        <i class="bi bi-plus-circle"></i> Select
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Select2 for customer dropdown
    $('#customer_id').select2({
        placeholder: 'Search and select customer...',
        allowClear: true,
        width: '100%'
    });

    // Customer selection handler
    $('#customer_id').on('change', function() {
        const selected = $(this).find(':selected');
        if (selected.val()) {
            const gst = selected.data('gst') || '-';
            $('#customerGst').text(gst);
            $('#customerInfoCard').show();
        } else {
            $('#customerInfoCard').hide();
        }
    });

    // Add item button click
    $('#addItemBtn').click(function() {
        $('#productModal').modal('show');
    });

    // Product search in modal
    $('#productSearch').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.product-row').each(function() {
            const productName = $(this).find('td:first').text().toLowerCase();
            if (productName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Select product from modal
    $(document).on('click', '.select-product', function() {
        const row = $(this).closest('.product-row');
        const productId = row.data('product-id');
        const productName = row.data('product-name');
        const price = row.data('product-price');
        const gstRate = row.data('product-gst');
        const gstId = row.data('product-gst-id');
        const stock = row.data('product-stock');
        
        addItemToTable(productId, productName, price, gstRate, gstId, stock);
        $('#productModal').modal('hide');
        $('#productSearch').val('');
        $('.product-row').show();
    });

    // Add item to table
    function addItemToTable(productId, productName, price, gstRate, gstId, stock) {
        const itemId = Date.now() + Math.random();
        
        const html = `
            <tr data-item-id="${itemId}" data-product-id="${productId}" data-gst-id="${gstId}" data-gst-rate="${gstRate}">
                <td>
                    <input type="text" class="form-control form-control-sm" value="${productName}" readonly>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm quantity" value="1" min="0.01" step="0.01" max="${stock}">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm delivered-qty" value="0" min="0" step="0.01" readonly>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm unit-price" value="${price}" min="0" step="0.01">
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
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        
        $('#itemsBody').append(html);
        
        // Add event listeners
        const row = $(`tr[data-item-id="${itemId}"]`);
        row.find('.quantity, .unit-price').on('input', function() {
            calculateRowTotal(row);
            updateOrderSummary();
        });
        
        row.find('.remove-item').click(function() {
            row.remove();
            updateOrderSummary();
        });
        
        // Calculate initial total
        calculateRowTotal(row);
        updateOrderSummary();
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

    // Update order summary
    function updateOrderSummary() {
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
        
        const total = subtotal + gstTotal - discountAmount;
        const advancePaid = parseFloat($('#advance_paid').val()) || 0;
        const balance = total - advancePaid;
        
        $('#summarySubtotal').text('₹' + subtotal.toFixed(2));
        $('#summaryGst').text('₹' + gstTotal.toFixed(2));
        $('#summaryDiscount').text('-₹' + discountAmount.toFixed(2));
        $('#summaryTotal').text('₹' + total.toFixed(2));
        $('#summaryAdvance').text('₹' + advancePaid.toFixed(2));
        $('#summaryBalance').text('₹' + balance.toFixed(2));
        
        // Update hidden items input
        updateItemsInput();
    }

    // Update hidden items input with JSON data
    function updateItemsInput() {
        const items = [];
        
        $('#itemsBody tr').each(function() {
            const item = {
                product_id: $(this).data('product-id'),
                quantity: parseFloat($(this).find('.quantity').val()) || 0,
                delivered_quantity: parseFloat($(this).find('.delivered-qty').val()) || 0,
                unit_price: parseFloat($(this).find('.unit-price').val()) || 0,
                gst_id: $(this).data('gst-id') || null,
                gst_rate: parseFloat($(this).data('gst-rate')) || 0,
                gst_amount: parseFloat($(this).find('.gst-amount').val()) || 0
            };
            items.push(item);
        });
        
        $('#itemsInput').val(JSON.stringify(items));
    }

    // Discount change handlers
    $('#discount_type, #discount_value, #advance_paid').on('change input', function() {
        updateOrderSummary();
    });

    // Initialize calculations for existing items
    $(document).ready(function() {
        $('#itemsBody tr').each(function() {
            const row = $(this);
            row.find('.quantity, .unit-price').on('input', function() {
                calculateRowTotal(row);
                updateOrderSummary();
            });
            
            row.find('.remove-item').click(function() {
                if (confirm('Are you sure you want to remove this item?')) {
                    row.remove();
                    updateOrderSummary();
                }
            });
        });
        
        // Trigger initial calculation
        updateOrderSummary();
    });

    // Form submission validation
    $('#orderForm').submit(function(e) {
        const itemsCount = $('#itemsBody tr').length;
        
        if (itemsCount === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'No Items',
                text: 'Please add at least one item to the order',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        // Validate quantities and prices
        let valid = true;
        $('#itemsBody tr').each(function() {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const unitPrice = parseFloat($(this).find('.unit-price').val()) || 0;
            
            if (quantity <= 0 || unitPrice <= 0) {
                valid = false;
            }
        });
        
        if (!valid) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Items',
                text: 'Please ensure all quantities and prices are greater than zero',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        return true;
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            setTimeout(function() {
                bsAlert.close();
            }, 5000);
        });
    }, 100);
</script>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .select2, .no-print {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 10pt;
    }
}

/* Table styles */
#itemsTable input[readonly] {
    background-color: #f8f9fa;
    border: none;
    cursor: default;
}

#itemsTable .form-control-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Select2 customization */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

/* Customer info card */
#customerInfoCard {
    transition: all 0.3s;
    border-left: 4px solid #556ee6;
}

#customerInfoCard .bi {
    color: #556ee6;
}

/* Summary styling */
#summaryTotal {
    font-size: 1.2rem;
    color: #556ee6;
}

#summaryBalance {
    font-size: 1.1rem;
    color: #dc3545;
}

/* Product modal */
#productList {
    max-height: 400px;
    overflow-y: auto;
}

.product-row {
    cursor: pointer;
    transition: background-color 0.2s;
}

.product-row:hover {
    background-color: rgba(85, 110, 230, 0.05);
}

.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 12px;
    }
    
    #itemsTable .form-control-sm {
        font-size: 12px;
        padding: 0.2rem;
    }
    
    .btn-sm {
        padding: 0.2rem 0.4rem;
    }
}

/* SweetAlert2 customization */
.swal2-popup {
    font-family: inherit;
}

.swal2-title {
    font-size: 1.2rem;
}

.swal2-confirm {
    background-color: #556ee6 !important;
}
</style>

</body>
</html>