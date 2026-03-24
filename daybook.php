<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get invoice settings
try {
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $settings = [
        'invoice_prefix' => 'DB-',
        'invoice_start_number' => '1001',
        'default_payment_terms' => '30',
        'invoice_footer_text' => 'Thank you for your business!',
        'show_gst' => 'true',
        'company_name' => 'Your Company Name',
        'company_gst' => '22AAAAA0000A1Z5',
        'company_address' => 'Your Company Address'
    ];
}

// Get products with GST details
try {
    $productsStmt = $pdo->query("
        SELECT p.*, c.name as category_name, p.gst_rate, p.gst_type, p.hsn_code
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.is_active = 1 
        ORDER BY p.name
    ");
    $products = $productsStmt->fetchAll();
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code, phone, email, gst_number, outstanding_balance, credit_limit FROM customers WHERE is_active = 1 ORDER BY name");
    $customers = $customersStmt->fetchAll();
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
}

// Generate unique invoice number
function generateDaybookNumber($pdo, $prefix) {
    try {
        $stmt = $pdo->query("SELECT invoice_number FROM daybook ORDER BY id DESC LIMIT 1");
        $lastInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lastInvoice) {
            $lastNumber = preg_replace('/[^0-9]/', '', $lastInvoice['invoice_number']);
            if ($lastNumber) {
                $newNumber = intval($lastNumber) + 1;
            } else {
                $newNumber = 1;
            }
        } else {
            $newNumber = 1;
        }
        
        $sequentialPart = str_pad($newNumber, 8, '0', STR_PAD_LEFT);
        return $prefix . $sequentialPart;
        
    } catch (Exception $e) {
        return $prefix . str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT);
    }
}

$invoice_number = generateDaybookNumber($pdo, $settings['invoice_prefix']);

// Initialize variables
$invoice = [
    'invoice_number' => $invoice_number,
    'invoice_date' => date('Y-m-d'),
    'driver_name' => '',
    'driver_number' => '',
    'location' => '',
    'customer_type' => 'constructor',
    'customer_id' => '',
    'customer' => null,
    'customer_name' => '',
    'customer_mobile' => '',
    'payment_status' => 'pending',
    'paid_amount' => 0,
    'notes' => '',
    'terms' => $settings['invoice_footer_text'] ?? '',
    'subtotal' => 0,
    'gst_total' => 0,
    'discount_total' => 0,
    'discount_type' => 'percentage',
    'discount_value' => 0,
    'grand_total' => 0,
    'items' => []
];

$errors = [];

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
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    
    // Get form data
    $invoice['invoice_number'] = $_POST['invoice_number'] ?? $invoice['invoice_number'];
    $invoice['invoice_date'] = $_POST['invoice_date'] ?? '';
    $invoice['driver_name'] = trim($_POST['driver_name'] ?? '');
    $invoice['driver_number'] = trim($_POST['driver_number'] ?? '');
    $invoice['location'] = trim($_POST['location'] ?? '');
    $invoice['customer_type'] = $_POST['customer_type'] ?? 'constructor';
    $invoice['customer_id'] = intval($_POST['customer_id'] ?? 0);
    $invoice['customer_name'] = trim($_POST['customer_name'] ?? '');
    $invoice['customer_mobile'] = trim($_POST['customer_mobile'] ?? '');
    $invoice['payment_status'] = $_POST['payment_status'] ?? 'pending';
    $invoice['paid_amount'] = floatval($_POST['paid_amount'] ?? 0);
    $invoice['notes'] = $_POST['notes'] ?? '';
    $invoice['terms'] = $_POST['terms'] ?? $settings['invoice_footer_text'] ?? '';
    $invoice['discount_type'] = $_POST['discount_type'] ?? 'percentage';
    $invoice['discount_value'] = floatval($_POST['discount_value'] ?? 0);
    
    // Get invoice items from JSON
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    
    // Validation
    if (empty($invoice['driver_name'])) {
        $errors['driver_name'] = 'Please enter driver name';
    }
    
    if (empty($invoice['location'])) {
        $errors['location'] = 'Please enter location';
    }
    
    if (empty($invoice['customer_name'])) {
        $errors['customer_name'] = 'Please enter customer/constructor name';
    }
    
    if (empty($invoice['invoice_date'])) {
        $errors['invoice_date'] = 'Invoice date is required';
    }
    
    if (empty($items) || count($items) === 0) {
        $errors['items'] = 'Please add at least one item to the invoice';
    }
    
    // Calculate totals with GST
    $subtotal = 0;
    $gst_total = 0;
    
    foreach ($items as &$item) {
        $quantity = floatval($item['quantity'] ?? 0);
        $unit_price = floatval($item['unit_price'] ?? 0);
        $gst_rate = floatval($item['gst_rate'] ?? 0);
        $item_subtotal = $quantity * $unit_price;
        
        // Calculate GST based on product's GST type
        $gst_type = $item['gst_type'] ?? 'exclusive';
        
        if ($gst_type === 'inclusive') {
            // GST is included in the price
            $gst_amount = ($item_subtotal * $gst_rate) / (100 + $gst_rate);
            $item_subtotal_excluding_gst = $item_subtotal - $gst_amount;
        } else {
            // GST is exclusive (added to price)
            $gst_amount = ($item_subtotal * $gst_rate) / 100;
            $item_subtotal_excluding_gst = $item_subtotal;
        }
        
        $subtotal += $item_subtotal_excluding_gst;
        $gst_total += $gst_amount;
        
        // Store calculated values in item
        $item['calculated_gst_amount'] = $gst_amount;
        $item['calculated_subtotal_excluding_gst'] = $item_subtotal_excluding_gst;
        $item['calculated_subtotal'] = $item_subtotal;
    }
    
    $invoice['subtotal'] = $subtotal;
    $invoice['gst_total'] = $gst_total;
    
    // Calculate discount on subtotal (excluding GST)
    if ($invoice['discount_type'] === 'percentage') {
        $invoice['discount_total'] = $subtotal * ($invoice['discount_value'] / 100);
    } else {
        $invoice['discount_total'] = $invoice['discount_value'];
    }
    
    $invoice['grand_total'] = $subtotal + $gst_total - $invoice['discount_total'];
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate a new invoice number if the current one already exists
            $checkStmt = $pdo->prepare("SELECT id FROM daybook WHERE invoice_number = ?");
            $checkStmt->execute([$invoice['invoice_number']]);
            
            $attempts = 0;
            while ($checkStmt->fetch() && $attempts < 10) {
                $randomNumber = str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT);
                $invoice['invoice_number'] = $settings['invoice_prefix'] . $randomNumber;
                $checkStmt->execute([$invoice['invoice_number']]);
                $attempts++;
            }
            
            // Insert into daybook - set product_id to NULL since we store items separately
            $insertStmt = $pdo->prepare("
                INSERT INTO daybook (
                    invoice_number, invoice_date, driver_name, driver_number,
                    location, customer_type, customer_name, customer_mobile,
                    subtotal, gst_total, discount_total, discount_type, discount_value,
                    grand_total, payment_status, paid_amount, notes, terms,
                    product_id, quantity, unit_price, total_amount,
                    created_by, created_at
                ) VALUES (
                    :invoice_number, :invoice_date, :driver_name, :driver_number,
                    :location, :customer_type, :customer_name, :customer_mobile,
                    :subtotal, :gst_total, :discount_total, :discount_type, :discount_value,
                    :grand_total, :payment_status, :paid_amount, :notes, :terms,
                    NULL, NULL, NULL, NULL,
                    :created_by, NOW()
                )
            ");
            
            $insertStmt->execute([
                ':invoice_number' => $invoice['invoice_number'],
                ':invoice_date' => $invoice['invoice_date'],
                ':driver_name' => $invoice['driver_name'],
                ':driver_number' => $invoice['driver_number'],
                ':location' => $invoice['location'],
                ':customer_type' => $invoice['customer_type'],
                ':customer_name' => $invoice['customer_name'],
                ':customer_mobile' => $invoice['customer_mobile'],
                ':subtotal' => $invoice['subtotal'],
                ':gst_total' => $invoice['gst_total'],
                ':discount_total' => $invoice['discount_total'],
                ':discount_type' => $invoice['discount_type'],
                ':discount_value' => $invoice['discount_value'],
                ':grand_total' => $invoice['grand_total'],
                ':payment_status' => $invoice['payment_status'],
                ':paid_amount' => $invoice['paid_amount'],
                ':notes' => $invoice['notes'],
                ':terms' => $invoice['terms'],
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $daybook_id = $pdo->lastInsertId();
            
            // Insert daybook items with GST details
            $itemStmt = $pdo->prepare("
                INSERT INTO daybook_items (
                    daybook_id, product_id, product_name, unit, quantity,
                    unit_price, gst_rate, gst_amount, discount_type, discount_value,
                    discount_amount, total_amount, line_total, created_at
                ) VALUES (
                    :daybook_id, :product_id, :product_name, :unit, :quantity,
                    :unit_price, :gst_rate, :gst_amount, :discount_type, :discount_value,
                    :discount_amount, :total_amount, :line_total, NOW()
                )
            ");
            
            foreach ($items as $item) {
                $quantity = floatval($item['quantity']);
                $unit_price = floatval($item['unit_price']);
                $gst_rate = floatval($item['gst_rate'] ?? 0);
                $gst_amount = floatval($item['calculated_gst_amount'] ?? 0);
                $line_total = $quantity * $unit_price;
                $discount_amount = floatval($item['discount_amount'] ?? 0);
                $total_amount = $line_total - $discount_amount;
                $discount_type = $item['discount_type'] ?? 'percentage';
                $discount_value = $item['discount_value'] ?? 0;
                
                $itemStmt->execute([
                    ':daybook_id' => $daybook_id,
                    ':product_id' => intval($item['product_id']),
                    ':product_name' => $item['product_name'],
                    ':unit' => $item['unit'],
                    ':quantity' => $quantity,
                    ':unit_price' => $unit_price,
                    ':gst_rate' => $gst_rate,
                    ':gst_amount' => $gst_amount,
                    ':discount_type' => $discount_type,
                    ':discount_value' => $discount_value,
                    ':discount_amount' => $discount_amount,
                    ':total_amount' => $total_amount,
                    ':line_total' => $line_total
                ]);
                
                // Update product stock (reduce quantity)
                $updateStockStmt = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock - :quantity,
                        updated_at = NOW()
                    WHERE id = :product_id
                ");
                $updateStockStmt->execute([
                    ':quantity' => $quantity,
                    ':product_id' => intval($item['product_id'])
                ]);
            }
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 3, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "New daybook entry created: " . $invoice['invoice_number'],
                ':activity_data' => json_encode([
                    'daybook_id' => $daybook_id,
                    'invoice_number' => $invoice['invoice_number'],
                    'customer_name' => $invoice['customer_name'],
                    'grand_total' => $invoice['grand_total'],
                    'gst_total' => $invoice['gst_total']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            // Redirect to print page
            header("Location: print-daybook.php?id=" . $daybook_id . "&success=1");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to create invoice: " . $e->getMessage();
            error_log("Daybook creation error: " . $e->getMessage());
        }
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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
        /* Global Styles */
        .page-title-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 20px;
        }
        
        .page-title-box h4 {
            color: white;
        }
        
        .page-title-box .breadcrumb-item a,
        .page-title-box .breadcrumb-item.active {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        /* Items Table Styles */
        #itemsTable {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        #itemsTable th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 12px 8px;
            vertical-align: middle;
            text-align: center;
            border-bottom: 2px solid #e9ecef;
        }
        
        #itemsTable td {
            padding: 8px;
            vertical-align: middle;
            text-align: center;
        }
        
        #itemsTable .form-control {
            width: 100%;
            min-width: 80px;
            font-size: 0.875rem;
            padding: 0.375rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            background-color: #fff;
        }
        
        #itemsTable .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        #itemsTable .form-control[readonly] {
            background-color: #e9ecef;
            cursor: default;
        }
        
        .item-discount-group {
            display: flex;
            gap: 5px;
            align-items: center;
            justify-content: center;
        }
        
        .discount-type-select {
            width: 55px;
            min-width: 55px;
            padding: 0.375rem 0.25rem;
            font-size: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            background-color: #fff;
        }
        
        .discount-value-input {
            width: 80px;
            min-width: 70px;
            padding: 0.375rem 0.25rem;
            font-size: 0.875rem;
        }
        
        .discount-amount-display {
            display: block;
            font-size: 0.7rem;
            margin-top: 4px;
            color: #6c757d;
        }
        
        .remove-item {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .remove-item:hover {
            background-color: #c82333;
            transform: scale(1.05);
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
        
        /* Summary styling */
        #summaryGrandTotal {
            font-size: 1.4rem;
            color: #667eea;
            font-weight: bold;
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
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        /* Loading state */
        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.65;
        }
        
        .btn-loading:after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            #itemsTable .form-control {
                font-size: 0.75rem;
                padding: 0.25rem;
                min-width: 60px;
            }
            
            .discount-type-select {
                width: 45px;
                min-width: 45px;
                font-size: 0.7rem;
            }
            
            .discount-value-input {
                width: 65px;
                min-width: 60px;
            }
        }
        
        @media (max-width: 992px) {
            .table-responsive {
                overflow-x: auto;
            }
        }
        
        .payment-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background-color: #fed7aa;
            color: #92400e;
        }
        
        .status-partial {
            background-color: #dbeafe;
            color: #1e40af;
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
                            <h4 class="mb-0 font-size-18">Daybook - Daily Transaction Register</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="daybook-list.php">Daybook</a></li>
                                    <li class="breadcrumb-item active">Create Entry</li>
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

                <!-- Invoice Form -->
                <form method="POST" action="daybook.php" id="invoiceForm">
                    <input type="hidden" name="action" value="create_invoice">
                    <div class="row">
                        <!-- Left Column - Invoice Details -->
                        <div class="col-xl-8">
                            <!-- Driver & Location Section -->
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Driver & Location Details</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="invoice_number" class="form-label">Invoice Number</label>
                                                <input type="text" class="form-control" id="invoice_number" name="invoice_number" 
                                                       value="<?= htmlspecialchars($invoice['invoice_number']) ?>" readonly>
                                                <small class="text-muted">Format: DB-XXXXXXXX (8 digits)</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="invoice_date" class="form-label">Entry Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['invoice_date']) ? 'is-invalid' : '' ?>" 
                                                       id="invoice_date" name="invoice_date" value="<?= htmlspecialchars($invoice['invoice_date']) ?>" required>
                                                <?php if (isset($errors['invoice_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['invoice_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="driver_name" class="form-label">Driver Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control <?= isset($errors['driver_name']) ? 'is-invalid' : '' ?>" 
                                                       id="driver_name" name="driver_name" value="<?= htmlspecialchars($invoice['driver_name']) ?>" required>
                                                <?php if (isset($errors['driver_name'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['driver_name'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="driver_number" class="form-label">Driver Mobile Number</label>
                                                <input type="tel" class="form-control" id="driver_number" name="driver_number" 
                                                       value="<?= htmlspecialchars($invoice['driver_number']) ?>" placeholder="Enter mobile number">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control <?= isset($errors['location']) ? 'is-invalid' : '' ?>" 
                                                       id="location" name="location" value="<?= htmlspecialchars($invoice['location']) ?>" 
                                                       placeholder="Pickup/Delivery location" required>
                                                <?php if (isset($errors['location'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['location'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer Section -->
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Customer Information</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="customer_type" class="form-label">Customer Type <span class="text-danger">*</span></label>
                                                <select class="form-control" id="customer_type" name="customer_type">
                                                    <option value="constructor" <?= $invoice['customer_type'] == 'constructor' ? 'selected' : '' ?>>Constructor/Builder</option>
                                                    <option value="customer" <?= $invoice['customer_type'] == 'customer' ? 'selected' : '' ?>>Regular Customer</option>
                                                    <option value="dealer" <?= $invoice['customer_type'] == 'dealer' ? 'selected' : '' ?>>Dealer</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="customer_name" class="form-label">Customer/Constructor Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control <?= isset($errors['customer_name']) ? 'is-invalid' : '' ?>" 
                                                       id="customer_name" name="customer_name" value="<?= htmlspecialchars($invoice['customer_name']) ?>" required>
                                                <?php if (isset($errors['customer_name'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['customer_name'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="customer_mobile" class="form-label">Mobile Number</label>
                                                <input type="tel" class="form-control" id="customer_mobile" name="customer_mobile" 
                                                       value="<?= htmlspecialchars($invoice['customer_mobile']) ?>" placeholder="Enter mobile number">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Items Card -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h4 class="card-title mb-0">Products / Services</h4>
                                        <button type="button" class="btn btn-primary" id="addItemBtn">
                                            <i class="mdi mdi-plus"></i> Add Item
                                        </button>
                                    </div>

                                    <!-- Items Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-centered" id="itemsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 20%">Product</th>
                                                    <th style="width: 8%">Unit</th>
                                                    <th style="width: 10%">Quantity</th>
                                                    <th style="width: 12%">Unit Price (₹)</th>
                                                    <th style="width: 8%">GST %</th>
                                                    <th style="width: 10%">GST Amt (₹)</th>
                                                    <th style="width: 15%">Discount</th>
                                                    <th style="width: 10%">Total (₹)</th>
                                                    <th style="width: 7%">Action</th>
                                                  </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                                <!-- Items will be added here dynamically -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (isset($errors['items'])): ?>
                                    <div class="text-danger mt-2"><?= $errors['items'] ?></div>
                                    <?php endif; ?>

                                    <!-- Hidden input to store items JSON -->
                                    <input type="hidden" name="items" id="itemsInput" value="[]">
                                </div>
                            </div>

                            <!-- Notes and Terms -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="notes" class="form-label">Notes (Optional)</label>
                                                <textarea class="form-control" id="notes" name="notes" rows="2" 
                                                          placeholder="Additional notes..."><?= htmlspecialchars($invoice['notes']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="terms" class="form-label">Terms & Conditions</label>
                                                <textarea class="form-control" id="terms" name="terms" rows="2" 
                                                          placeholder="Terms and conditions..."><?= htmlspecialchars($invoice['terms']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Summary -->
                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Transaction Summary</h4>

                                    <!-- Discount Section -->
                                    <div class="mb-4">
                                        <label class="form-label">Overall Discount</label>
                                        <div class="row g-2">
                                            <div class="col-5">
                                                <select class="form-control" id="discount_type" name="discount_type">
                                                    <option value="percentage" <?= $invoice['discount_type'] == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= $invoice['discount_type'] == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="number" class="form-control" id="discount_value" name="discount_value" 
                                                       value="<?= $invoice['discount_value'] ?>" min="0" step="0.01" placeholder="0.00">
                                            </div>
                                        </div>
                                        <small class="text-muted">Discount applied to subtotal (excluding GST)</small>
                                    </div>

                                    <!-- Payment Status Section -->
                                    <div class="mb-4">
                                        <label class="form-label">Payment Status</label>
                                        <select class="form-control" id="payment_status" name="payment_status">
                                            <option value="pending" <?= $invoice['payment_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="partial" <?= $invoice['payment_status'] == 'partial' ? 'selected' : '' ?>>Partial Payment</option>
                                            <option value="paid" <?= $invoice['payment_status'] == 'paid' ? 'selected' : '' ?>>Paid</option>
                                        </select>
                                    </div>

                                    <!-- Paid Amount Section (shown conditionally) -->
                                    <div class="mb-4" id="paidAmountSection" style="display: none;">
                                        <label class="form-label">Paid Amount (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                                            <input type="number" class="form-control" id="paid_amount" name="paid_amount" 
                                                   value="0" min="0" step="0.01">
                                        </div>
                                    </div>

                                    <!-- Summary Calculations -->
                                    <div class="table-responsive">
                                        <table class="table table-borderless mb-0">
                                              <tr>
                                                <td>Subtotal (Excluding GST):</td>
                                                <td class="text-end" id="summarySubtotal">₹0.00</td>
                                              </tr>
                                              <tr>
                                                <td>GST Amount:</td>
                                                <td class="text-end text-success" id="summaryGST">₹0.00</td>
                                              </tr>
                                              <tr>
                                                <td>Total (Before Discount):</td>
                                                <td class="text-end" id="summaryTotalBeforeDiscount">₹0.00</td>
                                              </tr>
                                              <tr>
                                                <td>Discount:</td>
                                                <td class="text-end text-danger" id="summaryDiscount">-₹0.00</td>
                                              </tr>
                                              <tr class="border-top">
                                                <th>Grand Total:</th>
                                                <th class="text-end" id="summaryGrandTotal">₹0.00</th>
                                              </tr>
                                              <tr class="border-top" id="balanceRow" style="display: none;">
                                                <td><strong>Balance Due:</strong></td>
                                                <td class="text-end" id="balanceDue">₹0.00</td>
                                              </tr>
                                            </table>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="mdi mdi-content-save"></i> Create Invoice
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                                            <i class="mdi mdi-eye"></i> Preview
                                        </button>
                                        <a href="daybook-list.php" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Cancel
                                        </a>
                                    </div>

                                    <!-- Quick Tips -->
                                    <div class="mt-4 p-3 bg-light rounded">
                                        <h6 class="mb-2"><i class="mdi mdi-lightbulb-on text-warning me-1"></i> Quick Tips</h6>
                                        <ul class="small text-muted ps-3 mb-0">
                                            <li>Driver name and location are required</li>
                                            <li>You can add multiple products to a single entry</li>
                                            <li>Discount is applied on the subtotal (excluding GST)</li>
                                            <li>Each item can have its own discount (percentage or fixed)</li>
                                            <li>Record partial payments for ongoing transactions</li>
                                            <li>Press Ctrl+Enter to quickly add a new item</li>
                                        </ul>
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
                                                <th>Price (₹)</th>
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
                                                data-product-unit="<?= htmlspecialchars($product['unit']) ?>"
                                                data-product-stock="<?= $product['current_stock'] ?>"
                                                data-product-gst="<?= $product['gst_rate'] ?? 0 ?>"
                                                data-product-gst-type="<?= $product['gst_type'] ?? 'exclusive' ?>">
                                                <td><?= htmlspecialchars($product['name']) ?></td>
                                                <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($product['unit']) ?></td>
                                                <td>₹<?= number_format($product['selling_price'], 2) ?></td>
                                                <td><span class="gst-badge"><?= $product['gst_rate'] ?? 0 ?>%</span></td>
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

                <!-- Preview Modal -->
                <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Invoice Preview</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body" id="previewContent">
                                <!-- Preview content will be loaded here -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" onclick="window.print()">
                                    <i class="mdi mdi-printer"></i> Print
                                </button>
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

// Payment status change handler
$('#payment_status').on('change', function() {
    const status = $(this).val();
    const total = parseFloat($('#summaryGrandTotal').text().replace('₹', '') || 0);
    
    if (status === 'paid') {
        $('#paidAmountSection').show();
        $('#paid_amount').val(total.toFixed(2));
        $('#balanceRow').hide();
    } else if (status === 'partial') {
        $('#paidAmountSection').show();
        $('#paid_amount').val('0');
        $('#balanceRow').show();
        calculateBalance();
    } else {
        $('#paidAmountSection').hide();
        $('#paid_amount').val('0');
        $('#balanceRow').hide();
    }
});

// Paid amount change handler
$('#paid_amount').on('input', function() {
    calculateBalance();
});

// Calculate balance due
function calculateBalance() {
    const total = parseFloat($('#summaryGrandTotal').text().replace('₹', '') || 0);
    const paid = parseFloat($('#paid_amount').val()) || 0;
    const balance = total - paid;
    
    $('#balanceDue').text('₹' + balance.toFixed(2));
    
    if (balance < 0) {
        $('#balanceDue').addClass('text-danger');
    } else {
        $('#balanceDue').removeClass('text-danger');
    }
}

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
    const unit = row.data('product-unit');
    const stock = row.data('product-stock');
    const gstRate = row.data('product-gst') || 0;
    const gstType = row.data('product-gst-type') || 'exclusive';
    
    addItemToTable(productId, productName, price, unit, stock, gstRate, gstType);
    $('#productModal').modal('hide');
    $('#productSearch').val('');
    $('.product-row').show();
});

// Add item to table with GST and discount options
function addItemToTable(productId, productName, price, unit, stock, gstRate, gstType) {
    const itemId = Date.now() + Math.random();
    
    const html = `
        <tr data-item-id="${itemId}" data-product-id="${productId}" data-gst-rate="${gstRate}" data-gst-type="${gstType}">
            <td>
                <input type="text" class="form-control form-control-sm" value="${productName}" readonly style="background-color: #e9ecef;">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm unit" value="${unit}" readonly style="background-color: #e9ecef;">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm quantity" value="1" min="0.01" step="0.01" max="${stock}">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm unit-price" value="${price}" min="0" step="0.01">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm gst-rate" value="${gstRate}" min="0" step="0.01" readonly style="background-color: #e9ecef;">
                <small class="text-muted">${gstRate}%</small>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm gst-amount" value="0" readonly style="background-color: #e9ecef;">
            </td>
            <td>
                <div class="item-discount-group">
                    <select class="discount-type-select">
                        <option value="percentage">%</option>
                        <option value="fixed">₹</option>
                    </select>
                    <input type="number" class="discount-value-input" placeholder="0" value="0" step="0.01">
                </div>
                <small class="discount-amount-display">Discount: ₹0</small>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm total" value="${price}" readonly style="background-color: #e9ecef;">
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
    row.find('.quantity, .unit-price, .discount-value-input, .discount-type-select').on('input change', function() {
        calculateRowTotal(row);
        updateInvoiceSummary();
    });
    
    row.find('.remove-item').click(function() {
        row.remove();
        updateInvoiceSummary();
    });
    
    // Calculate initial total
    calculateRowTotal(row);
    updateInvoiceSummary();
}

// Calculate row total with GST and item-level discount
function calculateRowTotal(row) {
    const quantity = parseFloat(row.find('.quantity').val()) || 0;
    const unitPrice = parseFloat(row.find('.unit-price').val()) || 0;
    const gstRate = parseFloat(row.data('gst-rate')) || 0;
    const gstType = row.data('gst-type') || 'exclusive';
    const discountType = row.find('.discount-type-select').val();
    let discountValue = parseFloat(row.find('.discount-value-input').val()) || 0;
    
    const subtotal = quantity * unitPrice;
    let gstAmount = 0;
    let totalAfterGst = subtotal;
    
    if (gstType === 'inclusive') {
        // GST is included in the price
        gstAmount = (subtotal * gstRate) / (100 + gstRate);
        totalAfterGst = subtotal;
    } else {
        // GST is exclusive
        gstAmount = (subtotal * gstRate) / 100;
        totalAfterGst = subtotal + gstAmount;
    }
    
    // Calculate item-level discount
    let discountAmount = 0;
    if (discountType === 'percentage') {
        discountAmount = totalAfterGst * (discountValue / 100);
    } else {
        discountAmount = discountValue;
    }
    
    const finalTotal = totalAfterGst - discountAmount;
    
    row.find('.gst-amount').val(gstAmount.toFixed(2));
    row.find('.total').val(finalTotal.toFixed(2));
    row.find('.discount-amount-display').text(`Discount: ₹${discountAmount.toFixed(2)}`);
    
    // Store data for summary calculation
    row.data('subtotal-excluding-gst', gstType === 'inclusive' ? subtotal - gstAmount : subtotal);
    row.data('gst-amount', gstAmount);
    row.data('total-after-gst', totalAfterGst);
    row.data('discount-amount', discountAmount);
    row.data('final-total', finalTotal);
    row.data('discount-percent', discountType === 'percentage' ? discountValue : 0);
    row.data('discount-fixed', discountType === 'fixed' ? discountValue : 0);
    row.data('discount-type', discountType);
}

// Update invoice summary with GST
function updateInvoiceSummary() {
    let subtotalExcludingGst = 0;
    let totalGst = 0;
    let totalAfterGst = 0;
    
    $('#itemsBody tr').each(function() {
        subtotalExcludingGst += parseFloat($(this).data('subtotal-excluding-gst')) || 0;
        totalGst += parseFloat($(this).data('gst-amount')) || 0;
        totalAfterGst += parseFloat($(this).data('total-after-gst')) || 0;
    });
    
    // Apply overall discount (if any)
    const discountType = $('#discount_type').val();
    let discountValue = parseFloat($('#discount_value').val()) || 0;
    let overallDiscount = 0;
    
    if (discountType === 'percentage') {
        overallDiscount = subtotalExcludingGst * (discountValue / 100);
    } else {
        overallDiscount = discountValue;
    }
    
    const grandTotal = totalAfterGst - overallDiscount;
    
    // Update summary display
    $('#summarySubtotal').text('₹' + subtotalExcludingGst.toFixed(2));
    $('#summaryGST').text('₹' + totalGst.toFixed(2));
    $('#summaryTotalBeforeDiscount').text('₹' + totalAfterGst.toFixed(2));
    $('#summaryDiscount').text('-₹' + overallDiscount.toFixed(2));
    $('#summaryGrandTotal').text('₹' + grandTotal.toFixed(2));
    
    // Update hidden items input
    updateItemsInput();
    
    // Update balance if partial payment is selected
    if ($('#payment_status').val() === 'partial') {
        calculateBalance();
    }
    
    // If paid status is selected, update paid amount
    if ($('#payment_status').val() === 'paid') {
        $('#paid_amount').val(grandTotal.toFixed(2));
    }
}

// Update hidden items input with JSON data including GST and discount
function updateItemsInput() {
    const items = [];
    
    $('#itemsBody tr').each(function() {
        const item = {
            product_id: $(this).data('product-id'),
            product_name: $(this).find('td:first input').val(),
            unit: $(this).find('.unit').val(),
            quantity: parseFloat($(this).find('.quantity').val()) || 0,
            unit_price: parseFloat($(this).find('.unit-price').val()) || 0,
            gst_rate: parseFloat($(this).data('gst-rate')) || 0,
            gst_type: $(this).data('gst-type') || 'exclusive',
            discount_type: $(this).data('discount-type') || 'percentage',
            discount_value: $(this).data('discount-percent') || $(this).data('discount-fixed') || 0,
            discount_amount: parseFloat($(this).data('discount-amount')) || 0,
            total_amount: parseFloat($(this).find('.total').val()) || 0
        };
        items.push(item);
    });
    
    $('#itemsInput').val(JSON.stringify(items));
}

// Overall discount change handlers
$('#discount_type, #discount_value').on('change input', function() {
    updateInvoiceSummary();
});

// Form submission validation
$('#invoiceForm').submit(function(e) {
    const itemsCount = $('#itemsBody tr').length;
    
    if (itemsCount === 0) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'No Items',
            text: 'Please add at least one item to the invoice',
            confirmButtonColor: '#556ee6'
        });
        return false;
    }
    
    // Validate driver name
    if (!$('#driver_name').val().trim()) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Missing Information',
            text: 'Please enter driver name',
            confirmButtonColor: '#556ee6'
        });
        return false;
    }
    
    // Validate location
    if (!$('#location').val().trim()) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Missing Information',
            text: 'Please enter location',
            confirmButtonColor: '#556ee6'
        });
        return false;
    }
    
    // Validate customer name
    if (!$('#customer_name').val().trim()) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Missing Information',
            text: 'Please enter customer/constructor name',
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
    
    // Validate payment amounts
    const total = parseFloat($('#summaryGrandTotal').text().replace('₹', '') || 0);
    const paidAmount = parseFloat($('#paid_amount').val()) || 0;
    const paymentStatus = $('#payment_status').val();
    
    if (paymentStatus === 'paid' && Math.abs(paidAmount - total) > 0.01) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Payment Mismatch',
            text: 'Paid amount must equal total amount for paid status',
            confirmButtonColor: '#556ee6'
        });
        return false;
    }
    
    if (paymentStatus === 'partial' && (paidAmount <= 0 || paidAmount >= total)) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Invalid Partial Payment',
            text: 'Partial payment amount must be greater than 0 and less than total amount',
            confirmButtonColor: '#556ee6'
        });
        return false;
    }
    
    return true;
});

// Preview button click
$('#previewBtn').click(function() {
    if ($('#itemsBody tr').length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items',
            text: 'Please add items to preview invoice',
            confirmButtonColor: '#556ee6'
        });
        return;
    }
    
    // Generate preview HTML
    let previewHtml = generatePreview();
    $('#previewContent').html(previewHtml);
    $('#previewModal').modal('show');
});

// Generate invoice preview with GST
function generatePreview() {
    const companyName = '<?= htmlspecialchars($settings['company_name'] ?? 'Your Company') ?>';
    const companyGst = '<?= htmlspecialchars($settings['company_gst'] ?? '') ?>';
    const companyAddress = '<?= htmlspecialchars($settings['company_address'] ?? '') ?>';
    const invoiceNumber = $('#invoice_number').val();
    const invoiceDate = $('#invoice_date').val();
    const driverName = $('#driver_name').val();
    const driverNumber = $('#driver_number').val();
    const location = $('#location').val();
    const customerType = $('#customer_type option:selected').text();
    const customerName = $('#customer_name').val();
    const customerMobile = $('#customer_mobile').val();
    const paymentStatus = $('#payment_status').val();
    const paidAmount = parseFloat($('#paid_amount').val()) || 0;
    const terms = $('#terms').val();
    
    let itemsHtml = '';
    let subtotalExcludingGst = 0;
    let totalGst = 0;
    let totalAfterGst = 0;
    
    $('#itemsBody tr').each(function() {
        const productName = $(this).find('td:first input').val();
        const unit = $(this).find('.unit').val();
        const quantity = $(this).find('.quantity').val();
        const unitPrice = $(this).find('.unit-price').val();
        const gstRate = $(this).data('gst-rate');
        const gstAmount = $(this).find('.gst-amount').val();
        const discountType = $(this).find('.discount-type-select').val();
        const discountValue = $(this).find('.discount-value-input').val();
        const totalAmount = $(this).find('.total').val();
        
        itemsHtml += `
            <tr>
                <td>${productName}</td>
                <td class="text-center">${unit}</td>
                <td class="text-end">${quantity}</td>
                <td class="text-end">₹${parseFloat(unitPrice).toFixed(2)}</td>
                <td class="text-center">${gstRate}%</td>
                <td class="text-end">₹${parseFloat(gstAmount).toFixed(2)}</td>
                <td class="text-end">${discountValue}${discountType === 'percentage' ? '%' : '₹'}</td>
                <td class="text-end">₹${parseFloat(totalAmount).toFixed(2)}</td>
            </tr>
        `;
        
        subtotalExcludingGst += parseFloat($(this).data('subtotal-excluding-gst')) || 0;
        totalGst += parseFloat($(this).data('gst-amount')) || 0;
        totalAfterGst += parseFloat($(this).data('total-after-gst')) || 0;
    });
    
    const discountAmount = parseFloat($('#summaryDiscount').text().replace('-₹', '') || 0);
    const grandTotal = parseFloat($('#summaryGrandTotal').text().replace('₹', '') || 0);
    
    return `
        <div class="container-fluid" style="font-family: 'Courier New', monospace;">
            <div class="row mb-4">
                <div class="col-6">
                    <h4>${companyName}</h4>
                    <p class="mb-0">${companyAddress}</p>
                    <p class="mb-0">GST: ${companyGst}</p>
                </div>
                <div class="col-6 text-end">
                    <h2>DAYBOOK ENTRY</h2>
                    <p class="mb-0"><strong>Entry #:</strong> ${invoiceNumber}</p>
                    <p class="mb-0"><strong>Date:</strong> ${invoiceDate}</p>
                    <p class="mb-0"><strong>Status:</strong> ${paymentStatus}</p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-6">
                    <h5>Driver Information:</h5>
                    <p class="mb-0"><strong>Name:</strong> ${driverName}</p>
                    <p class="mb-0"><strong>Mobile:</strong> ${driverNumber || 'N/A'}</p>
                    <p class="mb-0"><strong>Location:</strong> ${location}</p>
                </div>
                <div class="col-6">
                    <h5>Customer Information:</h5>
                    <p class="mb-0"><strong>Type:</strong> ${customerType}</p>
                    <p class="mb-0"><strong>Name:</strong> ${customerName}</p>
                    <p class="mb-0"><strong>Mobile:</strong> ${customerMobile || 'N/A'}</p>
                </div>
            </div>
            
            <table class="table table-bordered">
                <thead class="table-light">
                     <tr>
                        <th>Product</th>
                        <th class="text-center">Unit</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-center">GST %</th>
                        <th class="text-end">GST Amt</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Total</th>
                      </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
                <tfoot>
                     <tr>
                        <td colspan="7" class="text-end"><strong>Subtotal (Excluding GST):</strong></td>
                        <td class="text-end">₹${subtotalExcludingGst.toFixed(2)}</td>
                     </tr>
                     <tr>
                        <td colspan="7" class="text-end"><strong>GST Amount:</strong></td>
                        <td class="text-end text-success">₹${totalGst.toFixed(2)}</td>
                     </tr>
                     <tr>
                        <td colspan="7" class="text-end"><strong>Total (Before Discount):</strong></td>
                        <td class="text-end">₹${totalAfterGst.toFixed(2)}</td>
                     </tr>
                     <tr>
                        <td colspan="7" class="text-end"><strong>Overall Discount:</strong></td>
                        <td class="text-end text-danger">-₹${discountAmount.toFixed(2)}</td>
                     </tr>
                    <tr class="border-top">
                        <td colspan="7" class="text-end"><strong>Grand Total:</strong></td>
                        <td class="text-end"><strong>₹${grandTotal.toFixed(2)}</strong></td>
                     </tr>
                    ${paidAmount > 0 ? `
                     <tr>
                        <td colspan="7" class="text-end"><strong>Paid Amount:</strong></td>
                        <td class="text-end text-success">₹${paidAmount.toFixed(2)}</td>
                     </tr>
                     <tr>
                        <td colspan="7" class="text-end"><strong>Balance Due:</strong></td>
                        <td class="text-end text-danger"><strong>₹${(grandTotal - paidAmount).toFixed(2)}</strong></td>
                     </tr>
                    ` : ''}
                </tfoot>
             </table>
            
            <div class="row mt-4">
                <div class="col-12">
                    <p><strong>Notes:</strong> ${$('#notes').val() || 'N/A'}</p>
                </div>
            </div>
            
            ${terms ? `
            <div class="row">
                <div class="col-12">
                    <p><strong>Terms & Conditions:</strong><br>${terms}</p>
                </div>
            </div>
            ` : ''}
            
            <div class="row mt-5">
                <div class="col-6">
                    <p>_________________________</p>
                    <p>Driver Signature</p>
                </div>
                <div class="col-6 text-end">
                    <p>_________________________</p>
                    <p>Authorized Signature</p>
                </div>
            </div>
        </div>
    `;
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl+Enter to add new item
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        $('#addItemBtn').click();
    }
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

</body>
</html>