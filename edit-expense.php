<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Get expense ID from URL
$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$expense_id) {
    header("Location: manage-expenses.php");
    exit();
}

// Fetch expense details
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               s.name as supplier_name,
               s.company_name as supplier_company,
               v.vehicle_number
        FROM expenses e
        LEFT JOIN suppliers s ON e.supplier_id = s.id
        LEFT JOIN vehicles v ON e.vehicle_id = v.id
        WHERE e.id = ?
    ");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        header("Location: manage-expenses.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching expense: " . $e->getMessage());
    header("Location: manage-expenses.php");
    exit();
}

// Get expense categories
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

// Initialize variables
$errors = [];
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_expense'])) {
    
    // Get form data
    $expense_date = $_POST['expense_date'] ?? $expense['expense_date'];
    $category = $_POST['category'] ?? $expense['category'];
    $description = $_POST['description'] ?? $expense['description'];
    $amount = floatval($_POST['amount'] ?? $expense['amount']);
    $payment_method = $_POST['payment_method'] ?? $expense['payment_method'];
    $reference_number = $_POST['reference_number'] ?? $expense['reference_number'];
    $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
    $vehicle_id = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $gst_id = !empty($_POST['gst_id']) ? intval($_POST['gst_id']) : null;
    $gst_amount = !empty($_POST['gst_amount']) ? floatval($_POST['gst_amount']) : 0;
    $total_amount = !empty($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
    $notes = $_POST['notes'] ?? $expense['notes'];
    
    // Validation
    if (empty($expense_date)) {
        $errors['expense_date'] = 'Expense date is required';
    }
    
    if (empty($category)) {
        $errors['category'] = 'Category is required';
    }
    
    if ($amount <= 0) {
        $errors['amount'] = 'Valid amount is required';
    }
    
    if (empty($description)) {
        $errors['description'] = 'Description is required';
    }
    
    // Calculate total amount if not provided but GST is applied
    if (empty($total_amount) && !empty($gst_id) && !empty($gst_amount)) {
        $total_amount = $amount + $gst_amount;
    } elseif (empty($total_amount)) {
        $total_amount = $amount;
    }
    
    // If no errors, update database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Reverse the original daywise amounts
            reverseDaywiseAmounts($pdo, $expense);
            
            // Update expense
            $updateStmt = $pdo->prepare("
                UPDATE expenses SET
                    expense_date = :expense_date,
                    category = :category,
                    description = :description,
                    amount = :amount,
                    payment_method = :payment_method,
                    reference_number = :reference_number,
                    supplier_id = :supplier_id,
                    vehicle_id = :vehicle_id,
                    gst_id = :gst_id,
                    gst_amount = :gst_amount,
                    total_amount = :total_amount,
                    notes = :notes,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE id = :id
            ");
            
            $updateStmt->execute([
                ':expense_date' => $expense_date,
                ':category' => $category,
                ':description' => $description,
                ':amount' => $amount,
                ':payment_method' => $payment_method,
                ':reference_number' => !empty($reference_number) ? $reference_number : null,
                ':supplier_id' => $supplier_id,
                ':vehicle_id' => $vehicle_id,
                ':gst_id' => $gst_id,
                ':gst_amount' => $gst_amount > 0 ? $gst_amount : null,
                ':total_amount' => $total_amount,
                ':notes' => !empty($notes) ? $notes : null,
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $expense_id
            ]);
            
            // Apply new daywise amounts
            $new_expense = array_merge($expense, [
                'expense_date' => $expense_date,
                'payment_method' => $payment_method,
                'total_amount' => $total_amount
            ]);
            updateDaywiseAmounts($pdo, $new_expense);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by, created_at)
                VALUES (?, 4, ?, ?, ?, NOW())
            ");
            
            $logStmt->execute([
                $_SESSION['user_id'],
                "Expense updated: " . $expense['expense_number'],
                json_encode([
                    'expense_id' => $expense_id,
                    'expense_number' => $expense['expense_number'],
                    'old_amount' => $expense['total_amount'],
                    'new_amount' => $total_amount
                ]),
                $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Expense updated successfully!";
            header("Location: manage-expenses.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "Failed to update expense: " . $e->getMessage();
            error_log("Expense update error: " . $e->getMessage());
        }
    }
}

// Function to reverse daywise amounts
function reverseDaywiseAmounts($pdo, $expense) {
    try {
        $checkStmt = $pdo->prepare("SELECT id, expenses_cash, expenses_bank FROM daywise_amounts WHERE amount_date = :amount_date");
        $checkStmt->execute([':amount_date' => $expense['expense_date']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            if ($expense['payment_method'] == 'cash') {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_cash = GREATEST(expenses_cash - :amount, 0)
                    WHERE id = :id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_bank = GREATEST(expenses_bank - :amount, 0)
                    WHERE id = :id
                ");
            }
            $updateStmt->execute([
                ':amount' => $expense['total_amount'],
                ':id' => $existing['id']
            ]);
        }
    } catch (Exception $e) {
        error_log("Daywise amounts reversal error: " . $e->getMessage());
    }
}

// Function to update daywise amounts
function updateDaywiseAmounts($pdo, $expense) {
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM daywise_amounts WHERE amount_date = :amount_date");
        $checkStmt->execute([':amount_date' => $expense['expense_date']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            if ($expense['payment_method'] == 'cash') {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_cash = expenses_cash + :amount
                    WHERE id = :id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_bank = expenses_bank + :amount
                    WHERE id = :id
                ");
            }
            $updateStmt->execute([
                ':amount' => $expense['total_amount'],
                ':id' => $existing['id']
            ]);
        } else {
            $prevDate = date('Y-m-d', strtotime($expense['expense_date'] . ' -1 day'));
            $prevStmt = $pdo->prepare("SELECT closing_cash, closing_bank FROM daywise_amounts WHERE amount_date = ?");
            $prevStmt->execute([$prevDate]);
            $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
            
            $opening_cash = $prev ? floatval($prev['closing_cash']) : 0;
            $opening_bank = $prev ? floatval($prev['closing_bank']) : 0;
            
            $expenses_cash = ($expense['payment_method'] == 'cash') ? $expense['total_amount'] : 0;
            $expenses_bank = ($expense['payment_method'] != 'cash') ? $expense['total_amount'] : 0;
            
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
                    0, 0,
                    0, 0,
                    :expenses_cash, :expenses_bank,
                    0, 0,
                    0, 0,
                    :opening_cash - :expenses_cash, :opening_bank - :expenses_bank,
                    :created_by, NOW()
                )
            ");
            
            $insertStmt->execute([
                ':amount_date' => $expense['expense_date'],
                ':opening_cash' => $opening_cash,
                ':opening_bank' => $opening_bank,
                ':expenses_cash' => $expenses_cash,
                ':expenses_bank' => $expenses_bank,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
        }
    } catch (Exception $e) {
        error_log("Daywise amounts update error: " . $e->getMessage());
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
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
                            <h4 class="mb-0 font-size-18">Edit Expense</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-expenses.php">Expenses</a></li>
                                    <li class="breadcrumb-item active">Edit Expense</li>
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
                                
                                <form method="POST" action="edit-expense.php?id=<?= $expense_id ?>" id="expenseForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="expense_number" class="form-label">Expense Number</label>
                                                <input type="text" class="form-control" id="expense_number" value="<?= htmlspecialchars($expense['expense_number']) ?>" readonly>
                                                <small class="text-muted">Expense number cannot be changed</small>
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
                                                <select class="form-control select2 <?= isset($errors['category']) ? 'is-invalid' : '' ?>" 
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
                                                <select class="form-control select2" id="gst_id" name="gst_id">
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
                                                <select class="form-control select2" id="supplier_id" name="supplier_id">
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
                                                <select class="form-control select2" id="vehicle_id" name="vehicle_id">
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
                                                       value="<?= htmlspecialchars($expense['reference_number'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="gst_amount" class="form-label">GST Amount</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₹</span>
                                                    <input type="number" step="0.01" class="form-control" 
                                                           id="gst_amount" name="gst_amount" placeholder="Auto-calculated"
                                                           value="<?= htmlspecialchars($expense['gst_amount'] ?? '') ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Additional Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Any additional information..."><?= htmlspecialchars($expense['notes'] ?? '') ?></textarea>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" name="update_expense" class="btn btn-primary">
                                                <i class="mdi mdi-content-save"></i> Update Expense
                                            </button>
                                            <a href="manage-expenses.php" class="btn btn-secondary">
                                                <i class="mdi mdi-arrow-left"></i> Cancel
                                            </a>
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
                                    <h5 class="font-size-14">Expense Summary</h5>
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td><strong>Expense #:</strong></td>
                                            <td><?= htmlspecialchars($expense['expense_number']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Created On:</strong></td>
                                            <td><?= date('d M Y, h:i A', strtotime($expense['created_at'])) ?></td>
                                        </tr>
                                        <?php if ($expense['updated_at']): ?>
                                        <tr>
                                            <td><strong>Last Updated:</strong></td>
                                            <td><?= date('d M Y, h:i A', strtotime($expense['updated_at'])) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
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
                            </div>
                        </div>

                        <!-- Quick Tips Card -->
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Quick Tips</h4>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Update expense details carefully as it affects financial records.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">GST calculations are automatically updated based on the GST rate.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="mdi mdi-lightbulb-on text-primary" style="font-size: 20px;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">Changes will be reflected in day-wise financial records.</p>
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

    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select option',
        allowClear: true
    });

    // GST Calculation
    document.getElementById('gst_id')?.addEventListener('change', calculateGST);
    document.getElementById('amount')?.addEventListener('input', calculateGST);

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
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Please enter a valid amount greater than 0',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        var category = document.getElementById('category').value;
        if (!category) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Category Required',
                text: 'Please select a category',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        return true;
    });

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('expenseForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'hidden') {
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

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('expenseForm').submit();
        }
    });
</script>

<style>
/* Form styling */
.form-label {
    font-weight: 500;
    color: #495057;
}

.input-group-text {
    background-color: #f8f9fa;
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

/* Card styling */
.card.bg-soft-primary {
    background-color: rgba(85, 110, 230, 0.1) !important;
}

/* Invalid feedback */
.invalid-feedback.d-block {
    display: block !important;
    margin-top: 0.25rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .col-lg-4 {
        margin-top: 20px;
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