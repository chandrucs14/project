<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Initialize variables
$receipt = [
    'receipt_number' => '',
    'receipt_date' => date('Y-m-d'),
    'receipt_type' => '',
    'reference_type' => '',
    'reference_id' => '',
    'reference_number' => '',
    'received_from' => '',
    'received_from_type' => 'customer', // customer, supplier, other
    'received_from_id' => '',
    'description' => '',
    'amount' => '',
    'payment_mode' => 'cash',
    'cheque_number' => '',
    'cheque_date' => '',
    'bank_name' => '',
    'notes' => '',
    'category' => 'receipt'
];

$errors = [];
$success_message = '';

// Get receipt types
$receipt_types = [
    'customer_payment' => 'Customer Payment',
    'supplier_refund' => 'Supplier Refund',
    'loan_received' => 'Loan Received',
    'investment' => 'Investment/Capital',
    'interest_income' => 'Interest Income',
    'rent_income' => 'Rent Income',
    'commission' => 'Commission',
    'advance_from_customer' => 'Advance from Customer',
    'cash_sales' => 'Cash Sales',
    'other_income' => 'Other Income'
];

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code, phone FROM customers WHERE is_active = 1 ORDER BY name");
    $customers = $customersStmt->fetchAll();
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
}

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get pending invoices for reference
try {
    $invoicesStmt = $pdo->query("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, 
               i.paid_amount, (i.total_amount - i.paid_amount) as due_amount,
               c.name as customer_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.status IN ('sent', 'partially_paid', 'overdue')
        AND (i.total_amount - i.paid_amount) > 0
        ORDER BY i.invoice_date DESC
        LIMIT 20
    ");
    $pending_invoices = $invoicesStmt->fetchAll();
} catch (Exception $e) {
    $pending_invoices = [];
    error_log("Error fetching pending invoices: " . $e->getMessage());
}

// Generate unique receipt number
function generateReceiptNumber($pdo) {
    $prefix = 'RCP';
    $year = date('y');
    $month = date('m');
    
    try {
        // Check if receipts table exists, if not we'll use daywise_amounts
        $pattern = $prefix . $year . $month . '%';
        
        // Try to find the last receipt number from daywise_amounts or create a new one
        $stmt = $pdo->prepare("
            SELECT MAX(SUBSTRING_INDEX(amount_date, '-', -1)) as last_num 
            FROM daywise_amounts 
            WHERE amount_date LIKE :pattern
        ");
        $stmt->execute([':pattern' => '%' . $year . $month . '%']);
        $lastReceipt = $stmt->fetch();
        
        if ($lastReceipt && $lastReceipt['last_num']) {
            $lastNumber = intval($lastReceipt['last_num']);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . $year . $month . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        // Fallback to timestamp-based number
        return $prefix . $year . $month . date('d') . rand(100, 999);
    }
}

$receipt['receipt_number'] = generateReceiptNumber($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $receipt['receipt_number'] = $_POST['receipt_number'] ?? $receipt['receipt_number'];
    $receipt['receipt_date'] = $_POST['receipt_date'] ?? '';
    $receipt['receipt_type'] = $_POST['receipt_type'] ?? '';
    $receipt['reference_type'] = $_POST['reference_type'] ?? '';
    $receipt['reference_id'] = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
    $receipt['reference_number'] = $_POST['reference_number'] ?? '';
    $receipt['received_from_type'] = $_POST['received_from_type'] ?? 'customer';
    $receipt['received_from_id'] = !empty($_POST['received_from_id']) ? intval($_POST['received_from_id']) : null;
    $receipt['received_from'] = $_POST['received_from'] ?? '';
    $receipt['description'] = $_POST['description'] ?? '';
    $receipt['amount'] = $_POST['amount'] ?? '';
    $receipt['payment_mode'] = $_POST['payment_mode'] ?? 'cash';
    $receipt['cheque_number'] = $_POST['cheque_number'] ?? '';
    $receipt['cheque_date'] = $_POST['cheque_date'] ?? '';
    $receipt['bank_name'] = $_POST['bank_name'] ?? '';
    $receipt['notes'] = $_POST['notes'] ?? '';
    
    // Validation
    if (empty($receipt['receipt_date'])) {
        $errors['receipt_date'] = 'Receipt date is required';
    }
    
    if (empty($receipt['receipt_type'])) {
        $errors['receipt_type'] = 'Receipt type is required';
    }
    
    if (empty($receipt['description'])) {
        $errors['description'] = 'Description is required';
    }
    
    if (empty($receipt['amount']) || $receipt['amount'] <= 0) {
        $errors['amount'] = 'Valid amount is required';
    }
    
    // Validate based on receipt type
    if ($receipt['receipt_type'] == 'customer_payment' || $receipt['receipt_type'] == 'advance_from_customer') {
        if (empty($receipt['received_from_id']) && $receipt['received_from_type'] == 'customer') {
            $errors['received_from_id'] = 'Please select a customer';
        }
    }
    
    if ($receipt['receipt_type'] == 'supplier_refund') {
        if (empty($receipt['received_from_id']) && $receipt['received_from_type'] == 'supplier') {
            $errors['received_from_id'] = 'Please select a supplier';
        }
    }
    
    // Validate cheque details if payment mode is cheque
    if ($receipt['payment_mode'] == 'cheque') {
        if (empty($receipt['cheque_number'])) {
            $errors['cheque_number'] = 'Cheque number is required';
        }
        if (empty($receipt['cheque_date'])) {
            $errors['cheque_date'] = 'Cheque date is required';
        }
        if (empty($receipt['bank_name'])) {
            $errors['bank_name'] = 'Bank name is required';
        }
    }
    
    // If no errors, process the receipt
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Check if we need to update daywise_amounts
            // First, check if record exists for this date
            $checkStmt = $pdo->prepare("SELECT id, cash_received FROM daywise_amounts WHERE amount_date = :amount_date");
            $checkStmt->execute([':amount_date' => $receipt['receipt_date']]);
            $existing = $checkStmt->fetch();
            
            $amount = floatval($receipt['amount']);
            
            if ($existing) {
                // Update existing record
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET cash_received = cash_received + :amount,
                        closing_cash = opening_cash + cash_sales + (cash_received + :amount) - cash_purchases - expenses_cash - cash_paid,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':amount' => $amount,
                    ':updated_by' => $_SESSION['user_id'] ?? null,
                    ':id' => $existing['id']
                ]);
            } else {
                // Get previous day's closing balance
                $prevDate = date('Y-m-d', strtotime($receipt['receipt_date'] . ' -1 day'));
                $prevStmt = $pdo->prepare("SELECT closing_cash FROM daywise_amounts WHERE amount_date = :prev_date");
                $prevStmt->execute([':prev_date' => $prevDate]);
                $prev = $prevStmt->fetch();
                $opening_cash = $prev ? floatval($prev['closing_cash']) : 0;
                
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
                        :amount_date, :opening_cash, 0,
                        0, 0,
                        0, 0,
                        0, 0,
                        :cash_received, 0,
                        0, 0,
                        :closing_cash, 0,
                        :created_by, NOW()
                    )
                ");
                
                $insertStmt->execute([
                    ':amount_date' => $receipt['receipt_date'],
                    ':opening_cash' => $opening_cash,
                    ':cash_received' => $amount,
                    ':closing_cash' => $opening_cash + $amount,
                    ':created_by' => $_SESSION['user_id'] ?? null
                ]);
            }
            
            // If this is a customer payment, update invoice if reference is provided
            if ($receipt['receipt_type'] == 'customer_payment' && !empty($receipt['reference_id'])) {
                // Get invoice details
                $invoiceStmt = $pdo->prepare("
                    SELECT id, total_amount, paid_amount, outstanding_amount 
                    FROM invoices WHERE id = :id
                ");
                $invoiceStmt->execute([':id' => $receipt['reference_id']]);
                $invoice = $invoiceStmt->fetch();
                
                if ($invoice) {
                    $new_paid = floatval($invoice['paid_amount']) + $amount;
                    $new_outstanding = floatval($invoice['outstanding_amount']) - $amount;
                    
                    // Determine new status
                    if ($new_outstanding <= 0) {
                        $status = 'paid';
                    } else if ($new_paid > 0) {
                        $status = 'partially_paid';
                    } else {
                        $status = 'sent';
                    }
                    
                    // Update invoice
                    $updateInvoiceStmt = $pdo->prepare("
                        UPDATE invoices 
                        SET paid_amount = :paid_amount,
                            outstanding_amount = :outstanding_amount,
                            status = :status,
                            updated_at = NOW(),
                            updated_by = :updated_by
                        WHERE id = :id
                    ");
                    $updateInvoiceStmt->execute([
                        ':paid_amount' => $new_paid,
                        ':outstanding_amount' => $new_outstanding,
                        ':status' => $status,
                        ':updated_by' => $_SESSION['user_id'] ?? null,
                        ':id' => $receipt['reference_id']
                    ]);
                    
                    // Update customer outstanding
                    $updateOutstandingStmt = $pdo->prepare("
                        INSERT INTO customer_outstanding 
                        (customer_id, transaction_type, reference_id, transaction_date, amount, balance_after, created_by)
                        VALUES (
                            :customer_id, 'payment', :reference_id, :transaction_date, :amount,
                            (SELECT COALESCE(outstanding_balance, 0) - :amount2 FROM customers WHERE id = :customer_id2),
                            :created_by
                        )
                    ");
                    $updateOutstandingStmt->execute([
                        ':customer_id' => $invoice['customer_id'],
                        ':reference_id' => $receipt['reference_id'],
                        ':transaction_date' => $receipt['receipt_date'],
                        ':amount' => $amount,
                        ':amount2' => $amount,
                        ':customer_id2' => $invoice['customer_id'],
                        ':created_by' => $_SESSION['user_id'] ?? null
                    ]);
                    
                    // Update customer balance
                    $updateCustomerStmt = $pdo->prepare("
                        UPDATE customers 
                        SET outstanding_balance = outstanding_balance - :amount
                        WHERE id = :id
                    ");
                    $updateCustomerStmt->execute([
                        ':amount' => $amount,
                        ':id' => $invoice['customer_id']
                    ]);
                }
            }
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 3, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "New cash receipt added: " . $receipt['receipt_number'],
                ':activity_data' => json_encode([
                    'receipt_number' => $receipt['receipt_number'],
                    'amount' => $amount,
                    'type' => $receipt['receipt_type'],
                    'received_from' => $receipt['received_from']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            $success_message = "Cash receipt added successfully! Receipt #: " . $receipt['receipt_number'];
            
            // Reset form for new entry if requested
            if (isset($_POST['save_and_new'])) {
                $receipt = [
                    'receipt_number' => generateReceiptNumber($pdo),
                    'receipt_date' => date('Y-m-d'),
                    'receipt_type' => '',
                    'reference_type' => '',
                    'reference_id' => '',
                    'reference_number' => '',
                    'received_from' => '',
                    'received_from_type' => 'customer',
                    'received_from_id' => '',
                    'description' => '',
                    'amount' => '',
                    'payment_mode' => 'cash',
                    'cheque_number' => '',
                    'cheque_date' => '',
                    'bank_name' => '',
                    'notes' => '',
                    'category' => 'receipt'
                ];
            } else {
                // Redirect to view receipt page
                header("Location: cash-book.php?message=" . urlencode($success_message) . "&message_type=success");
                exit();
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to save receipt: " . $e->getMessage();
            error_log("Cash receipt insertion error: " . $e->getMessage());
        }
    }
}

// Get recent receipts for display
try {
    $recentStmt = $pdo->query("
        SELECT amount_date, cash_received 
        FROM daywise_amounts 
        WHERE cash_received > 0 
        ORDER BY amount_date DESC 
        LIMIT 5
    ");
    $recent_receipts = $recentStmt->fetchAll();
} catch (Exception $e) {
    $recent_receipts = [];
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
                            <h4 class="mb-0 font-size-18">Add Cash Receipt</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="cash-book.php">Cash Book</a></li>
                                    <li class="breadcrumb-item active">Add Receipt</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success Message -->
                <?php if (!empty($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Error Message -->
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

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Receipt Information</h4>
                                
                                <form method="POST" action="add-cash-receipt.php" id="receiptForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="receipt_number" class="form-label">Receipt Number</label>
                                                <input type="text" class="form-control" id="receipt_number" name="receipt_number" 
                                                       value="<?= htmlspecialchars($receipt['receipt_number']) ?>" readonly>
                                                <small class="text-muted">Auto-generated</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="receipt_date" class="form-label">Receipt Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['receipt_date']) ? 'is-invalid' : '' ?>" 
                                                       id="receipt_date" name="receipt_date" value="<?= htmlspecialchars($receipt['receipt_date']) ?>" required>
                                                <?php if (isset($errors['receipt_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['receipt_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="receipt_type" class="form-label">Receipt Type <span class="text-danger">*</span></label>
                                                <select class="form-control <?= isset($errors['receipt_type']) ? 'is-invalid' : '' ?>" 
                                                        id="receipt_type" name="receipt_type" required>
                                                    <option value="">Select Receipt Type</option>
                                                    <?php foreach ($receipt_types as $key => $value): ?>
                                                    <option value="<?= $key ?>" <?= $receipt['receipt_type'] == $key ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($value) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['receipt_type'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['receipt_type'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="payment_mode" class="form-label">Payment Mode</label>
                                                <select class="form-control" id="payment_mode" name="payment_mode">
                                                    <option value="cash" <?= $receipt['payment_mode'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                    <option value="cheque" <?= $receipt['payment_mode'] == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                    <option value="bank_transfer" <?= $receipt['payment_mode'] == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                                    <option value="online" <?= $receipt['payment_mode'] == 'online' ? 'selected' : '' ?>>Online Payment</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Cheque Details (shown only when cheque is selected) -->
                                    <div id="cheque_details" style="display: <?= $receipt['payment_mode'] == 'cheque' ? 'block' : 'none' ?>;">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="cheque_number" class="form-label">Cheque Number</label>
                                                    <input type="text" class="form-control <?= isset($errors['cheque_number']) ? 'is-invalid' : '' ?>" 
                                                           id="cheque_number" name="cheque_number" value="<?= htmlspecialchars($receipt['cheque_number']) ?>">
                                                    <?php if (isset($errors['cheque_number'])): ?>
                                                        <div class="invalid-feedback"><?= $errors['cheque_number'] ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="cheque_date" class="form-label">Cheque Date</label>
                                                    <input type="date" class="form-control <?= isset($errors['cheque_date']) ? 'is-invalid' : '' ?>" 
                                                           id="cheque_date" name="cheque_date" value="<?= htmlspecialchars($receipt['cheque_date']) ?>">
                                                    <?php if (isset($errors['cheque_date'])): ?>
                                                        <div class="invalid-feedback"><?= $errors['cheque_date'] ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="bank_name" class="form-label">Bank Name</label>
                                                    <input type="text" class="form-control <?= isset($errors['bank_name']) ? 'is-invalid' : '' ?>" 
                                                           id="bank_name" name="bank_name" value="<?= htmlspecialchars($receipt['bank_name']) ?>">
                                                    <?php if (isset($errors['bank_name'])): ?>
                                                        <div class="invalid-feedback"><?= $errors['bank_name'] ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="received_from_type" class="form-label">Received From Type</label>
                                                <select class="form-control" id="received_from_type" name="received_from_type">
                                                    <option value="customer" <?= $receipt['received_from_type'] == 'customer' ? 'selected' : '' ?>>Customer</option>
                                                    <option value="supplier" <?= $receipt['received_from_type'] == 'supplier' ? 'selected' : '' ?>>Supplier</option>
                                                    <option value="other" <?= $receipt['received_from_type'] == 'other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="customer_section">
                                            <div class="mb-3">
                                                <label for="received_from_id" class="form-label">Select Customer</label>
                                                <select class="form-control <?= isset($errors['received_from_id']) ? 'is-invalid' : '' ?>" 
                                                        id="received_from_id" name="received_from_id">
                                                    <option value="">Select Customer</option>
                                                    <?php foreach ($customers as $customer): ?>
                                                    <option value="<?= $customer['id'] ?>" <?= $receipt['received_from_id'] == $customer['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['customer_code']) ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['received_from_id'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['received_from_id'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="supplier_section" style="display: none;">
                                            <div class="mb-3">
                                                <label for="received_from_id_supplier" class="form-label">Select Supplier</label>
                                                <select class="form-control" id="received_from_id_supplier" name="received_from_id_supplier">
                                                    <option value="">Select Supplier</option>
                                                    <?php foreach ($suppliers as $supplier): ?>
                                                    <option value="<?= $supplier['id'] ?>">
                                                        <?= htmlspecialchars($supplier['name']) ?> 
                                                        <?= $supplier['company_name'] ? '(' . htmlspecialchars($supplier['company_name']) . ')' : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="other_section" style="display: none;">
                                            <div class="mb-3">
                                                <label for="received_from" class="form-label">Received From (Name)</label>
                                                <input type="text" class="form-control" id="received_from" name="received_from" 
                                                       value="<?= htmlspecialchars($receipt['received_from']) ?>" placeholder="Enter name">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="reference_type" class="form-label">Reference Type</label>
                                                <select class="form-control" id="reference_type" name="reference_type">
                                                    <option value="">None</option>
                                                    <option value="invoice" <?= $receipt['reference_type'] == 'invoice' ? 'selected' : '' ?>>Invoice</option>
                                                    <option value="order" <?= $receipt['reference_type'] == 'order' ? 'selected' : '' ?>>Order</option>
                                                    <option value="other" <?= $receipt['reference_type'] == 'other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="invoice_section" style="display: none;">
                                            <div class="mb-3">
                                                <label for="reference_id" class="form-label">Select Invoice</label>
                                                <select class="form-control" id="reference_id" name="reference_id">
                                                    <option value="">Select Invoice</option>
                                                    <?php foreach ($pending_invoices as $invoice): ?>
                                                    <option value="<?= $invoice['id'] ?>" data-amount="<?= $invoice['due_amount'] ?>" 
                                                            data-customer="<?= htmlspecialchars($invoice['customer_name']) ?>">
                                                        <?= $invoice['invoice_number'] ?> - <?= htmlspecialchars($invoice['customer_name']) ?> 
                                                        (Due: ₹<?= number_format($invoice['due_amount'], 2) ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="reference_other_section" style="display: none;">
                                            <div class="mb-3">
                                                <label for="reference_number" class="form-label">Reference Number</label>
                                                <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                                       value="<?= htmlspecialchars($receipt['reference_number']) ?>" placeholder="Enter reference number">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" 
                                               id="description" name="description" placeholder="Brief description of receipt"
                                               value="<?= htmlspecialchars($receipt['description']) ?>" required>
                                        <?php if (isset($errors['description'])): ?>
                                            <div class="invalid-feedback"><?= $errors['description'] ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="amount" class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" class="form-control <?= isset($errors['amount']) ? 'is-invalid' : '' ?>" 
                                                       id="amount" name="amount" placeholder="0.00"
                                                       value="<?= htmlspecialchars($receipt['amount']) ?>" required>
                                                <?php if (isset($errors['amount'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['amount'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="notes" class="form-label">Additional Notes</label>
                                                <input type="text" class="form-control" id="notes" name="notes" 
                                                       placeholder="Any additional information"
                                                       value="<?= htmlspecialchars($receipt['notes']) ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" name="save" class="btn btn-primary">
                                                <i class="mdi mdi-content-save"></i> Save Receipt
                                            </button>
                                            <button type="submit" name="save_and_new" class="btn btn-info">
                                                <i class="mdi mdi-content-save-plus"></i> Save & New
                                            </button>
                                            <a href="cash-book.php" class="btn btn-secondary">
                                                <i class="mdi mdi-arrow-left"></i> Back to Cash Book
                                            </a>
                                            <button type="reset" class="btn btn-light">
                                                <i class="mdi mdi-refresh"></i> Reset
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Information Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Receipt Information</h4>
                                
                                <div class="alert alert-info" role="alert">
                                    <i class="mdi mdi-information me-2"></i>
                                    <strong>Note:</strong> Fields marked with <span class="text-danger">*</span> are required.
                                </div>
                                
                                <div class="mt-3">
                                    <h5 class="font-size-14">Receipt Types:</h5>
                                    <ul class="list-unstyled">
                                        <li class="mb-2">
                                            <i class="mdi mdi-account text-success me-2"></i> Customer Payment
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-truck text-info me-2"></i> Supplier Refund
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-bank text-primary me-2"></i> Loan/Investment
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-cash text-warning me-2"></i> Cash Sales
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="mt-3">
                                    <h5 class="font-size-14">Payment Modes:</h5>
                                    <ul class="list-unstyled">
                                        <li class="mb-2">
                                            <i class="mdi mdi-cash text-success me-2"></i> Cash
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-file-document text-warning me-2"></i> Cheque
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-bank text-primary me-2"></i> Bank Transfer
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-credit-card text-info me-2"></i> Online Payment
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Receipts Card -->
                        <?php if (!empty($recent_receipts)): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Receipts</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_receipts as $recent): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($recent['amount_date'])) ?></td>
                                                <td>
                                                    <span class="badge bg-soft-success text-success">
                                                        ₹<?= number_format($recent['cash_received'], 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="cash-book.php" class="text-primary">View all in Cash Book <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Quick Tips Card -->
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Quick Tips</h4>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Link receipts to invoices to automatically update customer outstanding.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Select the correct receipt type for accurate financial reporting.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Use Save & New for adding multiple receipts quickly.</p>
                                    </div>
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

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Show/hide cheque details based on payment mode
    document.getElementById('payment_mode').addEventListener('change', function() {
        var chequeDetails = document.getElementById('cheque_details');
        if (this.value === 'cheque') {
            chequeDetails.style.display = 'block';
        } else {
            chequeDetails.style.display = 'none';
        }
    });

    // Show/hide received from sections based on type
    document.getElementById('received_from_type').addEventListener('change', function() {
        var customerSection = document.getElementById('customer_section');
        var supplierSection = document.getElementById('supplier_section');
        var otherSection = document.getElementById('other_section');
        
        if (this.value === 'customer') {
            customerSection.style.display = 'block';
            supplierSection.style.display = 'none';
            otherSection.style.display = 'none';
            document.getElementById('received_from_id').required = true;
            document.getElementById('received_from_id_supplier').required = false;
        } else if (this.value === 'supplier') {
            customerSection.style.display = 'none';
            supplierSection.style.display = 'block';
            otherSection.style.display = 'none';
            document.getElementById('received_from_id').required = false;
            document.getElementById('received_from_id_supplier').required = true;
        } else {
            customerSection.style.display = 'none';
            supplierSection.style.display = 'none';
            otherSection.style.display = 'block';
            document.getElementById('received_from_id').required = false;
            document.getElementById('received_from_id_supplier').required = false;
        }
    });

    // Show/hide reference sections
    document.getElementById('reference_type').addEventListener('change', function() {
        var invoiceSection = document.getElementById('invoice_section');
        var otherSection = document.getElementById('reference_other_section');
        
        if (this.value === 'invoice') {
            invoiceSection.style.display = 'block';
            otherSection.style.display = 'none';
        } else if (this.value === 'other') {
            invoiceSection.style.display = 'none';
            otherSection.style.display = 'block';
        } else {
            invoiceSection.style.display = 'none';
            otherSection.style.display = 'none';
        }
    });

    // Auto-fill amount and description when invoice is selected
    document.getElementById('reference_id').addEventListener('change', function() {
        var selected = this.options[this.selectedIndex];
        if (selected && selected.value) {
            var amount = selected.dataset.amount;
            var customer = selected.dataset.customer;
            
            document.getElementById('amount').value = amount;
            document.getElementById('description').value = 'Payment received from ' + customer + ' against invoice ' + selected.text.split(' - ')[0];
            
            // Auto-select receipt type if not already selected
            if (!document.getElementById('receipt_type').value) {
                document.getElementById('receipt_type').value = 'customer_payment';
            }
            
            // Auto-select customer if not already selected
            if (!document.getElementById('received_from_id').value) {
                // You would need to map customer from invoice here
                // This is simplified - you might need additional logic
            }
        }
    });

    // Form validation
    document.getElementById('receiptForm').addEventListener('submit', function(e) {
        var amount = parseFloat(document.getElementById('amount').value) || 0;
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than 0');
            document.getElementById('amount').focus();
            return false;
        }
        
        var receiptType = document.getElementById('receipt_type').value;
        if (!receiptType) {
            e.preventDefault();
            alert('Please select a receipt type');
            document.getElementById('receipt_type').focus();
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

    // Reset button handler
    document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to reset the form?')) {
            document.getElementById('receiptForm').reset();
            document.getElementById('receipt_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('payment_mode').value = 'cash';
            document.getElementById('cheque_details').style.display = 'none';
            document.getElementById('received_from_type').value = 'customer';
            document.getElementById('customer_section').style.display = 'block';
            document.getElementById('supplier_section').style.display = 'none';
            document.getElementById('other_section').style.display = 'none';
            document.getElementById('reference_type').value = '';
            document.getElementById('invoice_section').style.display = 'none';
            document.getElementById('reference_other_section').style.display = 'none';
        }
    });

    // Calculate total when amount changes
    document.getElementById('amount').addEventListener('input', function() {
        // You can add any additional calculations here if needed
    });
</script>

<!-- Include Select2 for better dropdowns -->
<link href="assets/libs/select2/css/select2.min.css" rel="stylesheet" type="text/css">
<script src="assets/libs/select2/js/select2.min.js"></script>
<script>
    // Initialize Select2 for better dropdown experience
    $(document).ready(function() {
        $('#receipt_type').select2({
            placeholder: 'Select Receipt Type',
            width: '100%'
        });
        
        $('#received_from_id').select2({
            placeholder: 'Select Customer',
            width: '100%',
            allowClear: true
        });
        
        $('#received_from_id_supplier').select2({
            placeholder: 'Select Supplier',
            width: '100%',
            allowClear: true
        });
        
        $('#reference_id').select2({
            placeholder: 'Select Invoice',
            width: '100%',
            allowClear: true
        });
    });
</script>

<style>
    /* Custom styles for better UI */
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
    
    .card.bg-soft-primary {
        background-color: rgba(85, 110, 230, 0.1) !important;
    }
    
    .invalid-feedback {
        display: block !important;
        margin-top: 0.25rem;
    }
    
    /* Loading state for submit buttons */
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
</style>

</body>
</html>