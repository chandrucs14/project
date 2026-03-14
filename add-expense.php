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
$expense = [
    'expense_number' => '',
    'expense_date' => date('Y-m-d'),
    'category' => '',
    'description' => '',
    'amount' => '',
    'payment_method' => 'cash',
    'reference_number' => '',
    'supplier_id' => '',
    'vehicle_id' => '',
    'gst_id' => '',
    'gst_amount' => '',
    'total_amount' => '',
    'notes' => ''
];

$errors = [];
$success_message = '';

// Get expense categories from database or use predefined list
$expense_categories = [
    'Transportation' => 'Transportation & Freight',
    'Fuel' => 'Fuel & Diesel',
    'Maintenance' => 'Vehicle Maintenance',
    'Salary' => 'Salaries & Wages',
    'Rent' => 'Rent & Lease',
    'Electricity' => 'Electricity & Utilities',
    'Office' => 'Office Expenses',
    'Marketing' => 'Marketing & Advertising',
    'Insurance' => 'Insurance',
    'Tax' => 'Taxes & Fees',
    'Legal' => 'Legal & Professional',
    'Repair' => 'Repairs & Maintenance',
    'Equipment' => 'Equipment Purchase',
    'Miscellaneous' => 'Miscellaneous'
];

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get vehicles for dropdown
try {
    $vehiclesStmt = $pdo->query("SELECT id, vehicle_number, owner_name FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
    $vehicles = $vehiclesStmt->fetchAll();
} catch (Exception $e) {
    $vehicles = [];
    error_log("Error fetching vehicles: " . $e->getMessage());
}

// Get GST details for dropdown
try {
    $gstStmt = $pdo->query("SELECT id, gst_rate, hsn_code, description FROM gst_details WHERE is_active = 1 ORDER BY gst_rate");
    $gstDetails = $gstStmt->fetchAll();
} catch (Exception $e) {
    $gstDetails = [];
    error_log("Error fetching GST details: " . $e->getMessage());
}

// Generate unique expense number
function generateExpenseNumber($pdo) {
    $prefix = 'EXP';
    $year = date('y');
    $month = date('m');
    
    try {
        // Get the last expense number for current month
        $stmt = $pdo->prepare("
            SELECT expense_number FROM expenses 
            WHERE expense_number LIKE :pattern 
            ORDER BY id DESC LIMIT 1
        ");
        $pattern = $prefix . $year . $month . '%';
        $stmt->execute([':pattern' => $pattern]);
        $lastExpense = $stmt->fetch();
        
        if ($lastExpense) {
            // Extract the sequence number and increment
            $lastNumber = intval(substr($lastExpense['expense_number'], -4));
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

$expense['expense_number'] = generateExpenseNumber($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate CSRF token (if you implement CSRF protection)
    // if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    //     die('CSRF token validation failed');
    // }
    
    // Get form data
    $expense['expense_number'] = $_POST['expense_number'] ?? $expense['expense_number'];
    $expense['expense_date'] = $_POST['expense_date'] ?? '';
    $expense['category'] = $_POST['category'] ?? '';
    $expense['description'] = $_POST['description'] ?? '';
    $expense['amount'] = $_POST['amount'] ?? '';
    $expense['payment_method'] = $_POST['payment_method'] ?? 'cash';
    $expense['reference_number'] = $_POST['reference_number'] ?? '';
    $expense['supplier_id'] = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
    $expense['vehicle_id'] = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : null;
    $expense['gst_id'] = !empty($_POST['gst_id']) ? $_POST['gst_id'] : null;
    $expense['gst_amount'] = !empty($_POST['gst_amount']) ? floatval($_POST['gst_amount']) : 0;
    $expense['total_amount'] = !empty($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
    $expense['notes'] = $_POST['notes'] ?? '';
    
    // Validation
    if (empty($expense['expense_date'])) {
        $errors['expense_date'] = 'Expense date is required';
    }
    
    if (empty($expense['category'])) {
        $errors['category'] = 'Category is required';
    }
    
    if (empty($expense['amount']) || $expense['amount'] <= 0) {
        $errors['amount'] = 'Valid amount is required';
    }
    
    if (empty($expense['description'])) {
        $errors['description'] = 'Description is required';
    }
    
    // Validate total amount if GST is applied
    if (!empty($expense['gst_id']) && $expense['total_amount'] <= 0) {
        $errors['total_amount'] = 'Total amount with GST is required';
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Calculate total amount if not provided but GST is applied
            if (empty($expense['total_amount']) && !empty($expense['gst_id']) && !empty($expense['gst_amount'])) {
                $expense['total_amount'] = $expense['amount'] + $expense['gst_amount'];
            } elseif (empty($expense['total_amount'])) {
                $expense['total_amount'] = $expense['amount'];
            }
            
            // Insert expense
            $insertStmt = $pdo->prepare("
                INSERT INTO expenses (
                    expense_number, expense_date, category, description, amount, 
                    payment_method, reference_number, supplier_id, vehicle_id, 
                    gst_id, gst_amount, total_amount, notes, created_by, created_at
                ) VALUES (
                    :expense_number, :expense_date, :category, :description, :amount,
                    :payment_method, :reference_number, :supplier_id, :vehicle_id,
                    :gst_id, :gst_amount, :total_amount, :notes, :created_by, NOW()
                )
            ");
            
            $insertStmt->execute([
                ':expense_number' => $expense['expense_number'],
                ':expense_date' => $expense['expense_date'],
                ':category' => $expense['category'],
                ':description' => $expense['description'],
                ':amount' => $expense['amount'],
                ':payment_method' => $expense['payment_method'],
                ':reference_number' => !empty($expense['reference_number']) ? $expense['reference_number'] : null,
                ':supplier_id' => $expense['supplier_id'],
                ':vehicle_id' => $expense['vehicle_id'],
                ':gst_id' => $expense['gst_id'],
                ':gst_amount' => $expense['gst_amount'] > 0 ? $expense['gst_amount'] : null,
                ':total_amount' => $expense['total_amount'],
                ':notes' => !empty($expense['notes']) ? $expense['notes'] : null,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $expense_id = $pdo->lastInsertId();
            
            // Update daywise amounts if enabled
            updateDaywiseAmounts($pdo, $expense);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 3, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "New expense created: " . $expense['expense_number'],
                ':activity_data' => json_encode([
                    'expense_id' => $expense_id,
                    'expense_number' => $expense['expense_number'],
                    'category' => $expense['category'],
                    'amount' => $expense['amount'],
                    'total_amount' => $expense['total_amount']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            $success_message = "Expense recorded successfully! Expense #: " . $expense['expense_number'];
            
            // Reset form for new entry
            if (!isset($_POST['save_and_new'])) {
                // Redirect to view expense page
                header("Location: view-expense.php?id=" . $expense_id);
                exit();
            } else {
                // Reset form for new entry
                $expense = [
                    'expense_number' => generateExpenseNumber($pdo),
                    'expense_date' => date('Y-m-d'),
                    'category' => '',
                    'description' => '',
                    'amount' => '',
                    'payment_method' => 'cash',
                    'reference_number' => '',
                    'supplier_id' => '',
                    'vehicle_id' => '',
                    'gst_id' => '',
                    'gst_amount' => '',
                    'total_amount' => '',
                    'notes' => ''
                ];
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to save expense: " . $e->getMessage();
            error_log("Expense insertion error: " . $e->getMessage());
        }
    }
}

// Function to update daywise amounts
function updateDaywiseAmounts($pdo, $expense) {
    try {
        // Check if daywise record exists for this date
        $checkStmt = $pdo->prepare("SELECT id FROM daywise_amounts WHERE amount_date = :amount_date");
        $checkStmt->execute([':amount_date' => $expense['expense_date']]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            // Update existing record
            if ($expense['payment_method'] == 'cash') {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_cash = expenses_cash + :amount,
                        closing_cash = opening_cash + cash_sales + cash_received - (cash_purchases + expenses_cash + :amount + cash_paid)
                    WHERE id = :id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_bank = expenses_bank + :amount,
                        closing_bank = opening_bank + credit_sales + bank_deposits - (credit_purchases + expenses_bank + :amount + bank_withdrawals)
                    WHERE id = :id
                ");
            }
            $updateStmt->execute([
                ':amount' => $expense['total_amount'],
                ':id' => $existing['id']
            ]);
        } else {
            // Create new daywise record
            $insertStmt = $pdo->prepare("
                INSERT INTO daywise_amounts (
                    amount_date, 
                    opening_cash, opening_bank,
                    cash_sales, credit_sales,
                    cash_purchases, credit_purchases,
                    expenses_cash, expenses_bank,
                    cash_received, cash_paid,
                    bank_deposits, bank_withdrawals,
                    closing_cash, closing_bank,
                    created_by
                ) VALUES (
                    :amount_date,
                    (SELECT COALESCE(closing_cash, 0) FROM daywise_amounts WHERE amount_date = DATE_SUB(:amount_date, INTERVAL 1 DAY)),
                    (SELECT COALESCE(closing_bank, 0) FROM daywise_amounts WHERE amount_date = DATE_SUB(:amount_date, INTERVAL 1 DAY)),
                    0, 0, 0, 0,
                    :expenses_cash, :expenses_bank,
                    0, 0, 0, 0,
                    :closing_cash, :closing_bank,
                    :created_by
                )
            ");
            
            $opening_cash = getPreviousDayClosing($pdo, $expense['expense_date'], 'cash');
            $opening_bank = getPreviousDayClosing($pdo, $expense['expense_date'], 'bank');
            
            $expenses_cash = ($expense['payment_method'] == 'cash') ? $expense['total_amount'] : 0;
            $expenses_bank = ($expense['payment_method'] == 'bank' || $expense['payment_method'] == 'cheque' || $expense['payment_method'] == 'online') ? $expense['total_amount'] : 0;
            
            $closing_cash = $opening_cash - $expenses_cash;
            $closing_bank = $opening_bank - $expenses_bank;
            
            $insertStmt->execute([
                ':amount_date' => $expense['expense_date'],
                ':expenses_cash' => $expenses_cash,
                ':expenses_bank' => $expenses_bank,
                ':closing_cash' => $closing_cash,
                ':closing_bank' => $closing_bank,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
        }
    } catch (Exception $e) {
        error_log("Daywise amounts update error: " . $e->getMessage());
        // Don't throw exception - non-critical feature
    }
}

// Helper function to get previous day's closing balance
function getPreviousDayClosing($pdo, $date, $type) {
    try {
        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $stmt = $pdo->prepare("SELECT closing_{$type} FROM daywise_amounts WHERE amount_date = :date");
        $stmt->execute([':date' => $prevDate]);
        $result = $stmt->fetch();
        return $result ? $result['closing_' . $type] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Get recent expenses for reference
try {
    $recentStmt = $pdo->query("
        SELECT expense_number, expense_date, category, description, total_amount, payment_method
        FROM expenses 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentExpenses = $recentStmt->fetchAll();
} catch (Exception $e) {
    $recentExpenses = [];
    error_log("Error fetching recent expenses: " . $e->getMessage());
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
                            <h4 class="mb-0 font-size-18">Add New Expense</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Expenses</a></li>
                                    <li class="breadcrumb-item active">Add Expense</li>
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
                                <h4 class="card-title mb-4">Expense Information</h4>
                                
                                <form method="POST" action="add-expense.php" id="expenseForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="expense_number" class="form-label">Expense Number</label>
                                                <input type="text" class="form-control" id="expense_number" name="expense_number" 
                                                       value="<?= htmlspecialchars($expense['expense_number']) ?>" readonly>
                                                <small class="text-muted">Auto-generated</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="expense_date" class="form-label">Expense Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control <?= isset($errors['expense_date']) ? 'is-invalid' : '' ?>" 
                                                       id="expense_date" name="expense_date" value="<?= htmlspecialchars($expense['expense_date']) ?>" required>
                                                <?php if (isset($errors['expense_date'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['expense_date'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                                                <select class="form-control <?= isset($errors['category']) ? 'is-invalid' : '' ?>" 
                                                        id="category" name="category" required>
                                                    <option value="">Select Category</option>
                                                    <?php foreach ($expense_categories as $key => $value): ?>
                                                    <option value="<?= $key ?>" <?= $expense['category'] == $key ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($value) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (isset($errors['category'])): ?>
                                                    <div class="invalid-feedback"><?= $errors['category'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="payment_method" class="form-label">Payment Method</label>
                                                <select class="form-control" id="payment_method" name="payment_method">
                                                    <option value="cash" <?= $expense['payment_method'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                    <option value="bank" <?= $expense['payment_method'] == 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                                    <option value="cheque" <?= $expense['payment_method'] == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                    <option value="online" <?= $expense['payment_method'] == 'online' ? 'selected' : '' ?>>Online Payment</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" 
                                               id="description" name="description" placeholder="Brief description of expense"
                                               value="<?= htmlspecialchars($expense['description']) ?>" required>
                                        <?php if (isset($errors['description'])): ?>
                                            <div class="invalid-feedback"><?= $errors['description'] ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" step="0.01" class="form-control <?= isset($errors['amount']) ? 'is-invalid' : '' ?>" 
                                                           id="amount" name="amount" placeholder="0.00"
                                                           value="<?= htmlspecialchars($expense['amount']) ?>" required>
                                                </div>
                                                <?php if (isset($errors['amount'])): ?>
                                                    <div class="invalid-feedback d-block"><?= $errors['amount'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="gst_id" class="form-label">GST Rate</label>
                                                <select class="form-control" id="gst_id" name="gst_id">
                                                    <option value="">No GST</option>
                                                    <?php foreach ($gstDetails as $gst): ?>
                                                    <option value="<?= $gst['id'] ?>" data-rate="<?= $gst['gst_rate'] ?>" 
                                                            <?= $expense['gst_id'] == $gst['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($gst['gst_rate']) ?>% - <?= htmlspecialchars($gst['hsn_code']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="total_amount" class="form-label">Total Amount (with GST)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" step="0.01" class="form-control" 
                                                           id="total_amount" name="total_amount" placeholder="Auto-calculated"
                                                           value="<?= htmlspecialchars($expense['total_amount']) ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="supplier_id" class="form-label">Supplier/Vendor</label>
                                                <select class="form-control" id="supplier_id" name="supplier_id">
                                                    <option value="">None (Not applicable)</option>
                                                    <?php foreach ($suppliers as $supplier): ?>
                                                    <option value="<?= $supplier['id'] ?>" <?= $expense['supplier_id'] == $supplier['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($supplier['name']) ?> 
                                                        <?= $supplier['company_name'] ? '(' . htmlspecialchars($supplier['company_name']) . ')' : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="vehicle_id" class="form-label">Vehicle (if applicable)</label>
                                                <select class="form-control" id="vehicle_id" name="vehicle_id">
                                                    <option value="">None</option>
                                                    <?php foreach ($vehicles as $vehicle): ?>
                                                    <option value="<?= $vehicle['id'] ?>" <?= $expense['vehicle_id'] == $vehicle['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($vehicle['vehicle_number']) ?> 
                                                        <?= $vehicle['owner_name'] ? '(' . htmlspecialchars($vehicle['owner_name']) . ')' : '' ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="reference_number" class="form-label">Reference Number</label>
                                                <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                                       placeholder="Bill/Invoice/Receipt #"
                                                       value="<?= htmlspecialchars($expense['reference_number']) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="gst_amount" class="form-label">GST Amount</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" step="0.01" class="form-control" 
                                                           id="gst_amount" name="gst_amount" placeholder="Auto-calculated"
                                                           value="<?= htmlspecialchars($expense['gst_amount']) ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Additional Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Any additional information..."><?= htmlspecialchars($expense['notes']) ?></textarea>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" name="save" class="btn btn-primary">
                                                <i class="mdi mdi-content-save"></i> Save Expense
                                            </button>
                                            <button type="submit" name="save_and_new" class="btn btn-info">
                                                <i class="mdi mdi-content-save-plus"></i> Save & New
                                            </button>
                                            <a href="manage-expenses.php" class="btn btn-secondary">
                                                <i class="mdi mdi-arrow-left"></i> Cancel
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
                                <h4 class="card-title mb-4">Expense Information</h4>
                                
                                <div class="alert alert-info" role="alert">
                                    <i class="mdi mdi-information me-2"></i>
                                    <strong>Note:</strong> Fields marked with <span class="text-danger">*</span> are required.
                                </div>
                                
                                <div class="mt-3">
                                    <h5 class="font-size-14">Payment Methods:</h5>
                                    <ul class="list-unstyled">
                                        <li class="mb-2">
                                            <i class="mdi mdi-cash text-success me-2"></i> Cash
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-bank text-primary me-2"></i> Bank Transfer
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-file-document text-warning me-2"></i> Cheque
                                        </li>
                                        <li class="mb-2">
                                            <i class="mdi mdi-credit-card text-info me-2"></i> Online Payment
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="mt-3">
                                    <h5 class="font-size-14">GST Information:</h5>
                                    <p class="text-muted small">
                                        If you select a GST rate, the GST amount and total amount will be automatically calculated.
                                        Input-credit will be available for eligible expenses.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Expenses Card -->
                        <?php if (!empty($recentExpenses)): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Expenses</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Category</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentExpenses as $recent): ?>
                                            <tr>
                                                <td>
                                                    <small><?= htmlspecialchars($recent['expense_number']) ?></small>
                                                </td>
                                                <td>
                                                    <small><?= htmlspecialchars($recent['category']) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-soft-success text-success">
                                                        ₹<?= number_format($recent['total_amount'], 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="manage-expenses.php" class="text-primary">View all expenses <i class="mdi mdi-arrow-right"></i></a>
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
                                        <p class="mb-0">Attach expense receipts to reference number for easy tracking.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Link expenses to suppliers for better financial tracking.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Vehicle-related expenses help in calculating operational costs.</p>
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

    // GST Calculation
    document.getElementById('gst_id').addEventListener('change', calculateGST);
    document.getElementById('amount').addEventListener('input', calculateGST);

    function calculateGST() {
        var amount = parseFloat(document.getElementById('amount').value) || 0;
        var gstSelect = document.getElementById('gst_id');
        var selectedOption = gstSelect.options[gstSelect.selectedIndex];
        
        if (selectedOption && selectedOption.value) {
            var gstRate = parseFloat(selectedOption.dataset.rate) || 0;
            var gstAmount = amount * (gstRate / 100);
            var totalAmount = amount + gstAmount;
            
            document.getElementById('gst_amount').value = gstAmount.toFixed(2);
            document.getElementById('total_amount').value = totalAmount.toFixed(2);
        } else {
            document.getElementById('gst_amount').value = '';
            document.getElementById('total_amount').value = amount.toFixed(2);
        }
    }

    // Form validation
    document.getElementById('expenseForm').addEventListener('submit', function(e) {
        var amount = parseFloat(document.getElementById('amount').value) || 0;
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than 0');
            document.getElementById('amount').focus();
            return false;
        }
        
        var category = document.getElementById('category').value;
        if (!category) {
            e.preventDefault();
            alert('Please select a category');
            document.getElementById('category').focus();
            return false;
        }
        
        return true;
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Reset button handler
    document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to reset the form?')) {
            document.getElementById('expenseForm').reset();
            document.getElementById('expense_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('payment_method').value = 'cash';
            document.getElementById('gst_amount').value = '';
            document.getElementById('total_amount').value = '';
        }
    });

    // Category quick select with keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+1 for Cash, Ctrl+2 for Bank, etc.
        if (e.ctrlKey && !e.shiftKey && !e.altKey) {
            if (e.key === '1') {
                e.preventDefault();
                document.getElementById('payment_method').value = 'cash';
            } else if (e.key === '2') {
                e.preventDefault();
                document.getElementById('payment_method').value = 'bank';
            } else if (e.key === '3') {
                e.preventDefault();
                document.getElementById('payment_method').value = 'cheque';
            } else if (e.key === '4') {
                e.preventDefault();
                document.getElementById('payment_method').value = 'online';
            }
        }
    });
</script>

<!-- Include Select2 for better dropdowns (optional) -->
<link href="assets/libs/select2/css/select2.min.css" rel="stylesheet" type="text/css">
<script src="assets/libs/select2/js/select2.min.js"></script>
<script>
    // Initialize Select2 for better dropdown experience
    $(document).ready(function() {
        $('#category').select2({
            placeholder: 'Select Category',
            width: '100%'
        });
        
        $('#supplier_id').select2({
            placeholder: 'Select Supplier/Vendor',
            width: '100%',
            allowClear: true
        });
        
        $('#vehicle_id').select2({
            placeholder: 'Select Vehicle',
            width: '100%',
            allowClear: true
        });
        
        $('#gst_id').select2({
            placeholder: 'Select GST Rate',
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
    
    .invalid-feedback.d-block {
        display: block !important;
        margin-top: 0.25rem;
    }
</style>

</body>
</html>