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
        'invoice_prefix' => 'INV-',
        'invoice_start_number' => '1001',
        'default_payment_terms' => '30',
        'invoice_footer_text' => 'Thank you for your business!',
        'show_gst' => 'true',
        'company_name' => 'Your Company Name',
        'company_gst' => '22AAAAA0000A1Z5',
        'company_address' => 'Your Company Address'
    ];
}

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code, phone, email, gst_number, outstanding_balance, credit_limit FROM customers WHERE is_active = 1 ORDER BY name");
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

// Generate unique invoice number - FIXED VERSION
function generateInvoiceNumber($pdo, $prefix) {
    try {
        // Get the last invoice number
        $stmt = $pdo->query("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1");
        $lastInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lastInvoice) {
            // Extract the numeric part from the last invoice number
            $lastNumber = preg_replace('/[^0-9]/', '', $lastInvoice['invoice_number']);
            if ($lastNumber) {
                $newNumber = intval($lastNumber) + 1;
            } else {
                $newNumber = 1;
            }
        } else {
            $newNumber = 1;
        }
        
        // Format: INV-YYYYMMDD-XXXX (where XXXX is sequential number)
        $datePart = date('Ymd');
        $sequentialPart = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        
        return $prefix . $datePart . '-' . $sequentialPart;
        
    } catch (Exception $e) {
        // Fallback to timestamp-based number
        return $prefix . date('Ymd') . '-' . rand(1000, 9999);
    }
}

$invoice_number = generateInvoiceNumber($pdo, $settings['invoice_prefix']);

// Initialize variables
$invoice = [
    'invoice_number' => $invoice_number,
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+' . ($settings['default_payment_terms'] ?? 30) . ' days')),
    'customer_id' => '',
    'customer' => null,
    'payment_type' => 'credit',
    'notes' => '',
    'terms' => $settings['invoice_footer_text'] ?? '',
    'subtotal' => 0,
    'gst_total' => 0,
    'discount_amount' => 0,
    'discount_type' => 'percentage',
    'discount_value' => 0,
    'total_amount' => 0,
    'paid_amount' => 0,
    'outstanding_amount' => 0,
    'items' => []
];

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    
    // Get form data
    $invoice['invoice_number'] = $_POST['invoice_number'] ?? $invoice['invoice_number'];
    $invoice['invoice_date'] = $_POST['invoice_date'] ?? '';
    $invoice['due_date'] = $_POST['due_date'] ?? '';
    $invoice['customer_id'] = intval($_POST['customer_id'] ?? 0);
    $invoice['payment_type'] = $_POST['payment_type'] ?? 'credit';
    $invoice['notes'] = $_POST['notes'] ?? '';
    $invoice['terms'] = $_POST['terms'] ?? $settings['invoice_footer_text'] ?? '';
    $invoice['discount_type'] = $_POST['discount_type'] ?? 'percentage';
    $invoice['discount_value'] = floatval($_POST['discount_value'] ?? 0);
    
    // Get invoice items from JSON
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    
    // Validation
    if (empty($invoice['customer_id'])) {
        $errors['customer'] = 'Please select a customer';
    }
    
    if (empty($invoice['invoice_date'])) {
        $errors['invoice_date'] = 'Invoice date is required';
    }
    
    if (empty($invoice['due_date'])) {
        $errors['due_date'] = 'Due date is required';
    }
    
    if (empty($items) || count($items) === 0) {
        $errors['items'] = 'Please add at least one item to the invoice';
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
    
    $invoice['subtotal'] = $subtotal;
    $invoice['gst_total'] = $gst_total;
    
    // Calculate discount
    if ($invoice['discount_type'] === 'percentage') {
        $invoice['discount_amount'] = ($subtotal + $gst_total) * ($invoice['discount_value'] / 100);
    } else {
        $invoice['discount_amount'] = $invoice['discount_value'];
    }
    
    $invoice['total_amount'] = $subtotal + $gst_total - $invoice['discount_amount'];
    $invoice['outstanding_amount'] = $invoice['total_amount'];
    $invoice['paid_amount'] = 0;
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate a new invoice number if the current one already exists
            $checkStmt = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
            $checkStmt->execute([$invoice['invoice_number']]);
            
            // Keep generating new numbers until we find a unique one
            $attempts = 0;
            while ($checkStmt->fetch() && $attempts < 10) {
                // Generate a new invoice number with a random suffix
                $randomSuffix = rand(100, 999);
                $invoice['invoice_number'] = $settings['invoice_prefix'] . date('Ymd') . '-' . $randomSuffix;
                $checkStmt->execute([$invoice['invoice_number']]);
                $attempts++;
            }
            
            // Insert invoice
            $insertStmt = $pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, customer_id, invoice_date, due_date, status,
                    payment_type, subtotal, gst_total, discount_amount, total_amount,
                    paid_amount, outstanding_amount, notes, created_by, created_at
                ) VALUES (
                    :invoice_number, :customer_id, :invoice_date, :due_date, 'sent',
                    :payment_type, :subtotal, :gst_total, :discount_amount, :total_amount,
                    :paid_amount, :outstanding_amount, :notes, :created_by, NOW()
                )
            ");
            
            $insertStmt->execute([
                ':invoice_number' => $invoice['invoice_number'],
                ':customer_id' => $invoice['customer_id'],
                ':invoice_date' => $invoice['invoice_date'],
                ':due_date' => $invoice['due_date'],
                ':payment_type' => $invoice['payment_type'],
                ':subtotal' => $invoice['subtotal'],
                ':gst_total' => $invoice['gst_total'],
                ':discount_amount' => $invoice['discount_amount'],
                ':total_amount' => $invoice['total_amount'],
                ':paid_amount' => $invoice['paid_amount'],
                ':outstanding_amount' => $invoice['outstanding_amount'],
                ':notes' => $invoice['notes'],
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Insert invoice items
            $itemStmt = $pdo->prepare("
                INSERT INTO invoice_items (
                    invoice_id, product_id, quantity, unit_price, gst_id, gst_amount, total_price, created_by, created_at
                ) VALUES (
                    :invoice_id, :product_id, :quantity, :unit_price, :gst_id, :gst_amount, :total_price, :created_by, NOW()
                )
            ");
            
            foreach ($items as $item) {
                $quantity = floatval($item['quantity']);
                $unit_price = floatval($item['unit_price']);
                $gst_amount = floatval($item['gst_amount']);
                $total_price = ($quantity * $unit_price) + $gst_amount;
                
                $itemStmt->execute([
                    ':invoice_id' => $invoice_id,
                    ':product_id' => intval($item['product_id']),
                    ':quantity' => $quantity,
                    ':unit_price' => $unit_price,
                    ':gst_id' => !empty($item['gst_id']) ? intval($item['gst_id']) : null,
                    ':gst_amount' => $gst_amount,
                    ':total_price' => $total_price,
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
                
                // Update product stock (reduce quantity)
                $updateStockStmt = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock - :quantity,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :product_id
                ");
                $updateStockStmt->execute([
                    ':quantity' => $quantity,
                    ':updated_by' => $_SESSION['user_id'] ?? null,
                    ':product_id' => intval($item['product_id'])
                ]);
                
                // Record stock transaction
                $stockStmt = $pdo->prepare("
                    INSERT INTO stock_transactions (
                        transaction_date, product_id, transaction_type, quantity,
                        reference_type, reference_id, unit_price, total_value, created_by, created_at
                    ) VALUES (
                        :transaction_date, :product_id, 'out', :quantity,
                        'invoice', :reference_id, :unit_price, :total_value, :created_by, NOW()
                    )
                ");
                $stockStmt->execute([
                    ':transaction_date' => $invoice['invoice_date'],
                    ':product_id' => intval($item['product_id']),
                    ':quantity' => $quantity,
                    ':reference_id' => $invoice_id,
                    ':unit_price' => $unit_price,
                    ':total_value' => $quantity * $unit_price,
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            
            // Update customer outstanding balance
            $updateCustomerStmt = $pdo->prepare("
                UPDATE customers 
                SET outstanding_balance = outstanding_balance + :amount
                WHERE id = :customer_id
            ");
            $updateCustomerStmt->execute([
                ':amount' => $invoice['outstanding_amount'],
                ':customer_id' => $invoice['customer_id']
            ]);
            
            // Insert into customer outstanding
            if ($invoice['outstanding_amount'] > 0) {
                $outstandingStmt = $pdo->prepare("
                    INSERT INTO customer_outstanding (
                        customer_id, transaction_type, reference_id, transaction_date,
                        amount, balance_after, due_date, status, created_by, created_at
                    ) VALUES (
                        :customer_id, 'invoice', :reference_id, :transaction_date,
                        :amount, :balance_after, :due_date, 'pending', :created_by, NOW()
                    )
                ");
                
                // Get current outstanding balance
                $balanceStmt = $pdo->prepare("SELECT outstanding_balance FROM customers WHERE id = ?");
                $balanceStmt->execute([$invoice['customer_id']]);
                $current_balance = $balanceStmt->fetchColumn();
                
                $outstandingStmt->execute([
                    ':customer_id' => $invoice['customer_id'],
                    ':reference_id' => $invoice_id,
                    ':transaction_date' => $invoice['invoice_date'],
                    ':amount' => $invoice['outstanding_amount'],
                    ':balance_after' => $current_balance,
                    ':due_date' => $invoice['due_date'],
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            
            // Update daywise amounts
            updateDaywiseAmounts($pdo, $invoice);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 3, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "New invoice created: " . $invoice['invoice_number'],
                ':activity_data' => json_encode([
                    'invoice_id' => $invoice_id,
                    'invoice_number' => $invoice['invoice_number'],
                    'customer_id' => $invoice['customer_id'],
                    'total_amount' => $invoice['total_amount']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            // Redirect to print invoice page
            header("Location: print-invoice.php?id=" . $invoice_id . "&success=1");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to create invoice: " . $e->getMessage();
            error_log("Invoice creation error: " . $e->getMessage());
        }
    }
}

// Function to update daywise amounts
function updateDaywiseAmounts($pdo, $invoice) {
    try {
        // Check if daywise record exists for this date
        $checkStmt = $pdo->prepare("SELECT id FROM daywise_amounts WHERE amount_date = :amount_date");
        $checkStmt->execute([':amount_date' => $invoice['invoice_date']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            if ($invoice['payment_type'] == 'cash') {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET cash_sales = cash_sales + :amount
                    WHERE id = :id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET credit_sales = credit_sales + :amount
                    WHERE id = :id
                ");
            }
            $updateStmt->execute([
                ':amount' => $invoice['total_amount'],
                ':id' => $existing['id']
            ]);
        } else {
            // Get previous day's closing balance
            $prevDate = date('Y-m-d', strtotime($invoice['invoice_date'] . ' -1 day'));
            $prevStmt = $pdo->prepare("SELECT closing_cash, closing_bank FROM daywise_amounts WHERE amount_date = ?");
            $prevStmt->execute([$prevDate]);
            $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
            
            $opening_cash = $prev ? floatval($prev['closing_cash']) : 0;
            $opening_bank = $prev ? floatval($prev['closing_bank']) : 0;
            
            // Create new record
            $insertStmt = $pdo->prepare("
                INSERT INTO daywise_amounts (
                    amount_date, opening_cash, opening_bank,
                    cash_sales, credit_sales,
                    cash_purchases, credit_purchases,
                    expenses_cash, expenses_bank,
                    cash_received, cash_paid,
                    bank_deposits, bank_withdrawals,
                    closing_cash, closing_bank,
                    created_by, created_at
                ) VALUES (
                    :amount_date, :opening_cash, :opening_bank,
                    :cash_sales, :credit_sales,
                    0, 0,
                    0, 0,
                    0, 0,
                    0, 0,
                    :opening_cash + :cash_sales, :opening_bank + :credit_sales,
                    :created_by, NOW()
                )
            ");
            
            $cash_sales = ($invoice['payment_type'] == 'cash') ? $invoice['total_amount'] : 0;
            $credit_sales = ($invoice['payment_type'] != 'cash') ? $invoice['total_amount'] : 0;
            
            $insertStmt->execute([
                ':amount_date' => $invoice['invoice_date'],
                ':opening_cash' => $opening_cash,
                ':opening_bank' => $opening_bank,
                ':cash_sales' => $cash_sales,
                ':credit_sales' => $credit_sales,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
        }
    } catch (Exception $e) {
        error_log("Daywise amounts update error: " . $e->getMessage());
        // Don't throw exception - non-critical feature
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
                            <h4 class="mb-0 font-size-18">Create New Invoice</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="invoices.php">Invoices</a></li>
                                    <li class="breadcrumb-item active">Create Invoice</li>
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
                <form method="POST" action="create-invoice.php" id="invoiceForm">
                    <input type="hidden" name="action" value="create_invoice">
                    <div class="row">
                        <!-- Left Column - Invoice Details -->
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Invoice Details</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="invoice_number" class="form-label">Invoice Number</label>
                                                <input type="text" class="form-control" id="invoice_number" name="invoice_number" 
                                                       value="<?= htmlspecialchars($invoice['invoice_number']) ?>" readonly>
                                                <small class="text-muted">Format: INV-YYYYMMDD-0001</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="invoice_date" class="form-label">Invoice Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['invoice_date']) ? 'is-invalid' : '' ?>" 
                                                       id="invoice_date" name="invoice_date" value="<?= htmlspecialchars($invoice['invoice_date']) ?>" required>
                                                <?php if (isset($errors['invoice_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['invoice_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="due_date" class="form-label">Due Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['due_date']) ? 'is-invalid' : '' ?>" 
                                                       id="due_date" name="due_date" value="<?= htmlspecialchars($invoice['due_date']) ?>" required>
                                                <?php if (isset($errors['due_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['due_date'] ?></div>
                                                <?php endif; ?>
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
                                                            data-gst="<?= htmlspecialchars($customer['gst_number'] ?? '') ?>"
                                                            data-outstanding="<?= $customer['outstanding_balance'] ?>"
                                                            data-credit-limit="<?= $customer['credit_limit'] ?? 0 ?>">
                                                        <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['customer_code']) ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['customer'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['customer'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="payment_type" class="form-label">Payment Type</label>
                                                <select class="form-control" id="payment_type" name="payment_type">
                                                    <option value="credit" <?= $invoice['payment_type'] == 'credit' ? 'selected' : '' ?>>Credit</option>
                                                    <option value="cash" <?= $invoice['payment_type'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                    <option value="bank_transfer" <?= $invoice['payment_type'] == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                                    <option value="cheque" <?= $invoice['payment_type'] == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                    <option value="online" <?= $invoice['payment_type'] == 'online' ? 'selected' : '' ?>>Online Payment</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Customer Info Card -->
                                    <div id="customerInfoCard" class="card bg-light mt-2" style="display: none;">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p class="mb-1"><i class="bi bi-building me-1"></i> <strong>GST Number:</strong> <span id="customerGst">-</span></p>
                                                    <p class="mb-1"><i class="bi bi-cash-stack me-1"></i> <strong>Outstanding:</strong> <span id="customerOutstanding">₹0.00</span></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="mb-1"><i class="bi bi-credit-card me-1"></i> <strong>Credit Limit:</strong> <span id="customerCreditLimit">₹0.00</span></p>
                                                    <p class="mb-1"><i class="bi bi-piggy-bank me-1"></i> <strong>Available Credit:</strong> <span id="customerAvailableCredit">₹0.00</span></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Items Card -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h4 class="card-title mb-0">Invoice Items</h4>
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
                                                    <th style="width: 15%">Unit Price</th>
                                                    <th style="width: 10%">GST %</th>
                                                    <th style="width: 10%">GST Amount</th>
                                                    <th style="width: 15%">Total</th>
                                                    <th style="width: 10%">Action</th>
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
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="notes" class="form-label">Notes (Optional)</label>
                                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                          placeholder="Additional notes for customer..."><?= htmlspecialchars($invoice['notes']) ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="terms" class="form-label">Terms & Conditions</label>
                                                <textarea class="form-control" id="terms" name="terms" rows="3"><?= htmlspecialchars($invoice['terms']) ?></textarea>
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
                                    <h4 class="card-title mb-4">Invoice Summary</h4>

                                    <!-- Discount Section -->
                                    <div class="mb-4">
                                        <label class="form-label">Discount</label>
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
                                    </div>

                                    <!-- Summary Calculations -->
                                    <div class="table-responsive">
                                        <table class="table table-borderless mb-0">
                                            <tr>
                                                <td>Subtotal:</td>
                                                <td class="text-end" id="summarySubtotal">₹0.00</td>
                                            </tr>
                                            <tr>
                                                <td>GST Total:</td>
                                                <td class="text-end" id="summaryGst">₹0.00</td>
                                            </tr>
                                            <tr>
                                                <td>Discount:</td>
                                                <td class="text-end" id="summaryDiscount">-₹0.00</td>
                                            </tr>
                                            <tr class="border-top">
                                                <th>Total Amount:</th>
                                                <th class="text-end" id="summaryTotal">₹0.00</th>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="mdi mdi-content-save"></i> Create Invoice & Print
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                                            <i class="mdi mdi-eye"></i> Preview Invoice
                                        </button>
                                        <a href="invoices.php" class="btn btn-outline-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Cancel
                                        </a>
                                    </div>

                                    <!-- Quick Tips -->
                                    <div class="mt-4 p-3 bg-light rounded">
                                        <h6 class="mb-2"><i class="mdi mdi-lightbulb-on text-warning me-1"></i> Quick Tips</h6>
                                        <ul class="small text-muted ps-3 mb-0">
                                            <li>Use Tab key to navigate between fields</li>
                                            <li>Press Ctrl+Enter to add new item quickly</li>
                                            <li>GST is automatically calculated based on product settings</li>
                                            <li>Invoice will be saved and you'll be redirected to print</li>
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

                <!-- Invoice Preview Modal -->
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

    // Initialize Select2 for customer dropdown
    $('#customer_id').select2({
        placeholder: 'Search and select customer...',
        allowClear: true,
        width: '100%'
    });

    // Invoice items array
    let invoiceItems = [];

    // Customer selection handler
    $('#customer_id').on('change', function() {
        const selected = $(this).find(':selected');
        if (selected.val()) {
            const gst = selected.data('gst') || '-';
            const outstanding = parseFloat(selected.data('outstanding') || 0);
            const creditLimit = parseFloat(selected.data('credit-limit') || 0);
            const available = creditLimit - outstanding;
            
            $('#customerGst').text(gst);
            $('#customerOutstanding').text('₹' + outstanding.toFixed(2));
            $('#customerCreditLimit').text('₹' + creditLimit.toFixed(2));
            $('#customerAvailableCredit').text('₹' + Math.max(0, available).toFixed(2));
            
            $('#customerInfoCard').show();
            
            // Check credit limit warning
            const totalAmount = parseFloat($('#summaryTotal').text().replace('₹', '') || 0);
            if (totalAmount > available && creditLimit > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Credit Limit Warning',
                    text: 'Invoice amount exceeds available credit limit!',
                    confirmButtonColor: '#556ee6'
                });
            }
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

    // Update invoice summary
    function updateInvoiceSummary() {
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
        
        $('#summarySubtotal').text('₹' + subtotal.toFixed(2));
        $('#summaryGst').text('₹' + gstTotal.toFixed(2));
        $('#summaryDiscount').text('-₹' + discountAmount.toFixed(2));
        $('#summaryTotal').text('₹' + total.toFixed(2));
        
        // Update hidden items input
        updateItemsInput();
        
        // Check credit limit
        const availableCredit = parseFloat($('#customerAvailableCredit').text().replace('₹', '') || 0);
        const creditLimit = parseFloat($('#customerCreditLimit').text().replace('₹', '') || 0);
        
        if (total > availableCredit && creditLimit > 0 && $('#customer_id').val()) {
            $('#summaryTotal').addClass('text-danger');
        } else {
            $('#summaryTotal').removeClass('text-danger');
        }
    }

    // Update hidden items input with JSON data
    function updateItemsInput() {
        const items = [];
        
        $('#itemsBody tr').each(function() {
            const item = {
                product_id: $(this).data('product-id'),
                quantity: parseFloat($(this).find('.quantity').val()) || 0,
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

    // Generate invoice preview
    function generatePreview() {
        const companyName = '<?= htmlspecialchars($settings['company_name'] ?? 'Your Company') ?>';
        const companyGst = '<?= htmlspecialchars($settings['company_gst'] ?? '') ?>';
        const companyAddress = '<?= htmlspecialchars($settings['company_address'] ?? '') ?>';
        const invoiceNumber = $('#invoice_number').val();
        const invoiceDate = $('#invoice_date').val();
        const dueDate = $('#due_date').val();
        const customerName = $('#customer_id option:selected').text().split(' (')[0];
        const customerGst = $('#customerGst').text();
        
        let itemsHtml = '';
        let subtotal = 0;
        let gstTotal = 0;
        
        $('#itemsBody tr').each(function() {
            const productName = $(this).find('td:first input').val();
            const quantity = $(this).find('.quantity').val();
            const unitPrice = $(this).find('.unit-price').val();
            const gstRate = $(this).data('gst-rate');
            const total = $(this).find('.total').val();
            const gstAmount = $(this).find('.gst-amount').val();
            
            itemsHtml += `
                <tr>
                    <td>${productName}</td>
                    <td class="text-center">${quantity}</td>
                    <td class="text-end">₹${parseFloat(unitPrice).toFixed(2)}</td>
                    <td class="text-center">${gstRate}%</td>
                    <td class="text-end">₹${parseFloat(gstAmount).toFixed(2)}</td>
                    <td class="text-end">₹${parseFloat(total).toFixed(2)}</td>
                </tr>
            `;
            
            subtotal += quantity * unitPrice;
            gstTotal += parseFloat(gstAmount);
        });
        
        const discountAmount = parseFloat($('#summaryDiscount').text().replace('-₹', '') || 0);
        const total = parseFloat($('#summaryTotal').text().replace('₹', '') || 0);
        
        return `
            <div class="container-fluid">
                <div class="row mb-4">
                    <div class="col-6">
                        <h4>${companyName}</h4>
                        <p class="mb-0">${companyAddress}</p>
                        <p class="mb-0">GST: ${companyGst}</p>
                    </div>
                    <div class="col-6 text-end">
                        <h2>INVOICE</h2>
                        <p class="mb-0"><strong>Invoice #:</strong> ${invoiceNumber}</p>
                        <p class="mb-0"><strong>Date:</strong> ${invoiceDate}</p>
                        <p class="mb-0"><strong>Due Date:</strong> ${dueDate}</p>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-6">
                        <h5>Bill To:</h5>
                        <p class="mb-0"><strong>${customerName}</strong></p>
                        <p class="mb-0">GST: ${customerGst}</p>
                    </div>
                </div>
                
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-center">GST %</th>
                            <th class="text-end">GST Amount</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                            <td class="text-end">₹${subtotal.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end"><strong>GST Total:</strong></td>
                            <td class="text-end">₹${gstTotal.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end"><strong>Discount:</strong></td>
                            <td class="text-end">-₹${discountAmount.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end"><strong>Total Amount:</strong></td>
                            <td class="text-end"><strong>₹${total.toFixed(2)}</strong></td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <p><strong>Notes:</strong> ${$('#notes').val() || 'N/A'}</p>
                        <p><strong>Terms & Conditions:</strong> ${$('#terms').val() || 'N/A'}</p>
                    </div>
                </div>
                
                <div class="row mt-5">
                    <div class="col-6">
                        <p>_________________________</p>
                        <p>Customer Signature</p>
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

#summaryTotal.text-danger {
    color: #f46a6a !important;
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

/* Preview modal */
#previewModal .modal-body {
    background-color: #f8f9fa;
    padding: 30px;
    font-family: 'Courier New', monospace;
}

#previewContent table {
    background-color: white;
}

#previewContent .table-bordered {
    border: 1px solid #dee2e6;
}

#previewContent .table-bordered th,
#previewContent .table-bordered td {
    padding: 10px;
}
</style>

</body>
</html>