<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$daybook_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$daybook_id) {
    header("Location: daybook-list.php");
    exit();
}

// Get daybook details
try {
    $stmt = $pdo->prepare("SELECT * FROM daybook WHERE id = ?");
    $stmt->execute([$daybook_id]);
    $daybook = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$daybook) {
        $_SESSION['error_message'] = "Daybook entry not found.";
        header("Location: daybook-list.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching daybook: " . $e->getMessage());
    $_SESSION['error_message'] = "Error fetching daybook details.";
    header("Location: daybook-list.php");
    exit();
}

// Get daybook items
try {
    $itemStmt = $pdo->prepare("
        SELECT di.*, p.name as product_name, p.unit, p.gst_rate, p.gst_type, p.hsn_code
        FROM daybook_items di
        LEFT JOIN products p ON di.product_id = p.id
        WHERE di.daybook_id = ?
        ORDER BY di.id ASC
    ");
    $itemStmt->execute([$daybook_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $items = [];
    error_log("Error fetching daybook items: " . $e->getMessage());
}

// Get products for dropdown
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
        'invoice_footer_text' => 'Thank you for your business!'
    ];
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_daybook') {
    
    // Get form data
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = $_POST['invoice_date'] ?? '';
    $driver_name = trim($_POST['driver_name'] ?? '');
    $driver_number = trim($_POST['driver_number'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $customer_type = $_POST['customer_type'] ?? 'constructor';
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $payment_status = $_POST['payment_status'] ?? 'pending';
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    $terms = $_POST['terms'] ?? '';
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = floatval($_POST['discount_value'] ?? 0);
    
    // Get invoice items from JSON
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    
    // Validation
    if (empty($driver_name)) {
        $error = "Please enter driver name";
    } elseif (empty($location)) {
        $error = "Please enter location";
    } elseif (empty($customer_name)) {
        $error = "Please enter customer/constructor name";
    } elseif (empty($invoice_date)) {
        $error = "Invoice date is required";
    } elseif (empty($items) || count($items) === 0) {
        $error = "Please add at least one item to the invoice";
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
    
    // Calculate discount on subtotal (excluding GST)
    if ($discount_type === 'percentage') {
        $discount_total = $subtotal * ($discount_value / 100);
    } else {
        $discount_total = $discount_value;
    }
    
    $grand_total = $subtotal + $gst_total - $discount_total;
    
    // If no errors, update database
    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            
            // First, restore stock for old items
            $oldItemsStmt = $pdo->prepare("SELECT product_id, quantity FROM daybook_items WHERE daybook_id = ?");
            $oldItemsStmt->execute([$daybook_id]);
            $oldItems = $oldItemsStmt->fetchAll();
            
            foreach ($oldItems as $oldItem) {
                $restoreStockStmt = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock + :quantity,
                        updated_at = NOW()
                    WHERE id = :product_id
                ");
                $restoreStockStmt->execute([
                    ':quantity' => $oldItem['quantity'],
                    ':product_id' => $oldItem['product_id']
                ]);
            }
            
            // Update daybook
            $updateStmt = $pdo->prepare("
                UPDATE daybook SET 
                    invoice_number = :invoice_number,
                    invoice_date = :invoice_date,
                    driver_name = :driver_name,
                    driver_number = :driver_number,
                    location = :location,
                    customer_type = :customer_type,
                    customer_name = :customer_name,
                    customer_mobile = :customer_mobile,
                    subtotal = :subtotal,
                    gst_total = :gst_total,
                    discount_total = :discount_total,
                    discount_type = :discount_type,
                    discount_value = :discount_value,
                    grand_total = :grand_total,
                    payment_status = :payment_status,
                    paid_amount = :paid_amount,
                    notes = :notes,
                    terms = :terms,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $updateStmt->execute([
                ':invoice_number' => $invoice_number,
                ':invoice_date' => $invoice_date,
                ':driver_name' => $driver_name,
                ':driver_number' => $driver_number,
                ':location' => $location,
                ':customer_type' => $customer_type,
                ':customer_name' => $customer_name,
                ':customer_mobile' => $customer_mobile,
                ':subtotal' => $subtotal,
                ':gst_total' => $gst_total,
                ':discount_total' => $discount_total,
                ':discount_type' => $discount_type,
                ':discount_value' => $discount_value,
                ':grand_total' => $grand_total,
                ':payment_status' => $payment_status,
                ':paid_amount' => $paid_amount,
                ':notes' => $notes,
                ':terms' => $terms,
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $daybook_id
            ]);
            
            // Delete old items
            $deleteItemsStmt = $pdo->prepare("DELETE FROM daybook_items WHERE daybook_id = ?");
            $deleteItemsStmt->execute([$daybook_id]);
            
            // Insert new items
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
                $discount_type_item = $item['discount_type'] ?? 'percentage';
                $discount_value_item = $item['discount_value'] ?? 0;
                
                $itemStmt->execute([
                    ':daybook_id' => $daybook_id,
                    ':product_id' => intval($item['product_id']),
                    ':product_name' => $item['product_name'],
                    ':unit' => $item['unit'],
                    ':quantity' => $quantity,
                    ':unit_price' => $unit_price,
                    ':gst_rate' => $gst_rate,
                    ':gst_amount' => $gst_amount,
                    ':discount_type' => $discount_type_item,
                    ':discount_value' => $discount_value_item,
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
                VALUES (:user_id, 4, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':description' => "Daybook entry updated: " . $invoice_number,
                ':activity_data' => json_encode([
                    'daybook_id' => $daybook_id,
                    'invoice_number' => $invoice_number,
                    'customer_name' => $customer_name,
                    'grand_total' => $grand_total
                ]),
                ':created_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Daybook entry updated successfully.";
            header("Location: daybook-list.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update daybook entry: " . $e->getMessage();
            error_log("Daybook update error: " . $e->getMessage());
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
                            <h4 class="mb-0 font-size-18">Edit Daybook Entry</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="daybook-list.php">Daybook</a></li>
                                    <li class="breadcrumb-item active">Edit Entry</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Messages -->
                <?php if ($error): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i>
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i>
                            <?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <form method="POST" action="edit-daybook.php?id=<?= $daybook_id ?>" id="invoiceForm">
                    <input type="hidden" name="action" value="update_daybook">
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
                                                <label for="invoice_number" class="form-label">Entry Number</label>
                                                <input type="text" class="form-control" id="invoice_number" name="invoice_number" 
                                                       value="<?= safe_echo($daybook['invoice_number']) ?>" readonly>
                                                <small class="text-muted">Entry number cannot be changed</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="invoice_date" class="form-label">Entry Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" 
                                                       id="invoice_date" name="invoice_date" 
                                                       value="<?= safe_echo($daybook['invoice_date']) ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="driver_name" class="form-label">Driver Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" 
                                                       id="driver_name" name="driver_name" 
                                                       value="<?= safe_echo($daybook['driver_name']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="driver_number" class="form-label">Driver Mobile Number</label>
                                                <input type="tel" class="form-control" id="driver_number" name="driver_number" 
                                                       value="<?= safe_echo($daybook['driver_number']) ?>" placeholder="Enter mobile number">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" 
                                                       id="location" name="location" 
                                                       value="<?= safe_echo($daybook['location']) ?>" 
                                                       placeholder="Pickup/Delivery location" required>
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
                                                    <option value="constructor" <?= $daybook['customer_type'] == 'constructor' ? 'selected' : '' ?>>Constructor/Builder</option>
                                                    <option value="customer" <?= $daybook['customer_type'] == 'customer' ? 'selected' : '' ?>>Regular Customer</option>
                                                    <option value="dealer" <?= $daybook['customer_type'] == 'dealer' ? 'selected' : '' ?>>Dealer</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="customer_name" class="form-label">Customer/Constructor Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" 
                                                       id="customer_name" name="customer_name" 
                                                       value="<?= safe_echo($daybook['customer_name']) ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="customer_mobile" class="form-label">Mobile Number</label>
                                                <input type="tel" class="form-control" id="customer_mobile" name="customer_mobile" 
                                                       value="<?= safe_echo($daybook['customer_mobile']) ?>" placeholder="Enter mobile number">
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
                                                <?php foreach ($items as $item): ?>
                                                <tr data-item-id="<?= $item['id'] ?>" 
                                                    data-product-id="<?= $item['product_id'] ?>" 
                                                    data-gst-rate="<?= $item['gst_rate'] ?? 0 ?>" 
                                                    data-gst-type="<?= $item['gst_type'] ?? 'exclusive' ?>">
                                                     <td>
                                                        <input type="text" class="form-control form-control-sm" value="<?= safe_echo($item['product_name']) ?>" readonly style="background-color: #e9ecef;">
                                                     </td>
                                                     <td>
                                                        <input type="text" class="form-control form-control-sm unit" value="<?= safe_echo($item['unit']) ?>" readonly style="background-color: #e9ecef;">
                                                     </td>
                                                     <td>
                                                        <input type="number" class="form-control form-control-sm quantity" value="<?= $item['quantity'] ?>" min="0.01" step="0.01">
                                                     </td>
                                                     <td>
                                                        <input type="number" class="form-control form-control-sm unit-price" value="<?= $item['unit_price'] ?>" min="0" step="0.01">
                                                     </td>
                                                     <td>
                                                        <input type="number" class="form-control form-control-sm gst-rate" value="<?= $item['gst_rate'] ?? 0 ?>" min="0" step="0.01" readonly style="background-color: #e9ecef;">
                                                        <small class="text-muted"><?= $item['gst_rate'] ?? 0 ?>%</small>
                                                     </td>
                                                     <td>
                                                        <input type="text" class="form-control form-control-sm gst-amount" value="<?= number_format($item['gst_amount'] ?? 0, 2) ?>" readonly style="background-color: #e9ecef;">
                                                     </td>
                                                     <td>
                                                        <div class="item-discount-group">
                                                            <select class="discount-type-select">
                                                                <option value="percentage" <?= ($item['discount_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>%</option>
                                                                <option value="fixed" <?= ($item['discount_type'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>₹</option>
                                                            </select>
                                                            <input type="number" class="discount-value-input" placeholder="0" value="<?= $item['discount_value'] ?? 0 ?>" step="0.01">
                                                        </div>
                                                        <small class="discount-amount-display">Discount: ₹<?= number_format($item['discount_amount'] ?? 0, 2) ?></small>
                                                     </td>
                                                     <td>
                                                        <input type="text" class="form-control form-control-sm total" value="<?= number_format($item['total_amount'] ?? 0, 2) ?>" readonly style="background-color: #e9ecef;">
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

                                    <!-- Hidden input to store items JSON -->
                                    <input type="hidden" name="items" id="itemsInput" value="">
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
                                                          placeholder="Additional notes..."><?= safe_echo($daybook['notes']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="terms" class="form-label">Terms & Conditions</label>
                                                <textarea class="form-control" id="terms" name="terms" rows="2" 
                                                          placeholder="Terms and conditions..."><?= safe_echo($daybook['terms']) ?></textarea>
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
                                                    <option value="percentage" <?= ($daybook['discount_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                                    <option value="fixed" <?= ($daybook['discount_type'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>Fixed (₹)</option>
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="number" class="form-control" id="discount_value" name="discount_value" 
                                                       value="<?= $daybook['discount_value'] ?? 0 ?>" min="0" step="0.01" placeholder="0.00">
                                            </div>
                                        </div>
                                        <small class="text-muted">Discount applied to subtotal (excluding GST)</small>
                                    </div>

                                    <!-- Payment Status Section -->
                                    <div class="mb-4">
                                        <label class="form-label">Payment Status</label>
                                        <select class="form-control" id="payment_status" name="payment_status">
                                            <option value="pending" <?= $daybook['payment_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="partial" <?= $daybook['payment_status'] == 'partial' ? 'selected' : '' ?>>Partial Payment</option>
                                            <option value="paid" <?= $daybook['payment_status'] == 'paid' ? 'selected' : '' ?>>Paid</option>
                                        </select>
                                    </div>

                                    <!-- Paid Amount Section (shown conditionally) -->
                                    <div class="mb-4" id="paidAmountSection" style="<?= $daybook['payment_status'] != 'pending' ? 'display: block;' : 'display: none;' ?>">
                                        <label class="form-label">Paid Amount (₹)</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                                            <input type="number" class="form-control" id="paid_amount" name="paid_amount" 
                                                   value="<?= $daybook['paid_amount'] ?? 0 ?>" min="0" step="0.01">
                                        </div>
                                    </div>

                                    <!-- Summary Calculations -->
                                    <div class="table-responsive">
                                        <table class="table table-borderless mb-0">
                                              32
                                                <td>Subtotal (Excluding GST):点
                                                <td class="text-end" id="summarySubtotal">₹0.00点
                                              32
                                              32
                                                <td>GST Amount:点
                                                <td class="text-end text-success" id="summaryGST">₹0.00点
                                              32
                                              32
                                                <td>Total (Before Discount):点
                                                <td class="text-end" id="summaryTotalBeforeDiscount">₹0.00点
                                              32
                                              32
                                                <td>Discount:点
                                                <td class="text-end text-danger" id="summaryDiscount">-₹0.00点
                                              32
                                              <tr class="border-top">
                                                <th>Grand Total:</th>
                                                <th class="text-end" id="summaryGrandTotal">₹0.00</th>
                                              </tr>
                                              <tr class="border-top" id="balanceRow" style="display: none;">
                                                <td><strong>Balance Due:</strong>点
                                                <td class="text-end" id="balanceDue">₹0.00点
                                              </tr>
                                          </table>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="mdi mdi-content-save"></i> Update Entry
                                        </button>
                                        <a href="daybook-list.php" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Cancel
                                        </a>
                                    </div>

                                    <!-- Info Alert -->
                                    <div class="mt-4 p-3 bg-light rounded">
                                        <h6 class="mb-2"><i class="mdi mdi-information text-info me-1"></i> Important</h6>
                                        <ul class="small text-muted ps-3 mb-0">
                                            <li>Editing will update stock quantities automatically</li>
                                            <li>Entry number cannot be changed</li>
                                            <li>All changes are logged for audit purposes</li>
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
                                            32
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
                                                data-product-name="<?= safe_echo($product['name']) ?>"
                                                data-product-price="<?= $product['selling_price'] ?>"
                                                data-product-unit="<?= safe_echo($product['unit']) ?>"
                                                data-product-stock="<?= $product['current_stock'] ?>"
                                                data-product-gst="<?= $product['gst_rate'] ?? 0 ?>"
                                                data-product-gst-type="<?= $product['gst_type'] ?? 'exclusive' ?>">
                                                <td><?= safe_echo($product['name']) ?></td>
                                                <td><?= safe_echo($product['category_name'] ?? 'N/A') ?></td>
                                                <td><?= safe_echo($product['unit']) ?></td>
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

// Initialize existing items calculations
$(document).ready(function() {
    $('#itemsBody tr').each(function() {
        calculateRowTotal($(this));
    });
    updateInvoiceSummary();
    
    // Initialize paid amount section visibility
    if ($('#payment_status').val() === 'paid' || $('#payment_status').val() === 'partial') {
        $('#paidAmountSection').show();
        if ($('#payment_status').val() === 'partial') {
            $('#balanceRow').show();
            calculateBalance();
        }
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl+Enter to add new item
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        $('#addItemBtn').click();
    }
});
</script>

</body>
</html>