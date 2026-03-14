<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get filter parameters
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : date('Y-m-01');
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : date('Y-m-d');
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$filter_payment_method = isset($_GET['filter_payment_method']) ? $_GET['filter_payment_method'] : '';
$filter_supplier = isset($_GET['filter_supplier']) ? $_GET['filter_supplier'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

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

// Get suppliers for filter dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $pdo->beginTransaction();
        
        $expense_id = (int)$_GET['id'];
        
        // Get expense details for logging and reversal
        $expenseStmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id");
        $expenseStmt->execute([':id' => $expense_id]);
        $expense = $expenseStmt->fetch();
        
        if ($expense) {
            // Reverse daywise amounts
            reverseDaywiseAmounts($pdo, $expense);
            
            // Delete the expense
            $deleteStmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
            $deleteStmt->execute([':id' => $expense_id]);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 5, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "Expense deleted: " . $expense['expense_number'],
                ':activity_data' => json_encode([
                    'expense_id' => $expense_id,
                    'expense_number' => $expense['expense_number'],
                    'amount' => $expense['total_amount']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $pdo->commit();
            
            $success_message = "Expense deleted successfully!";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error deleting expense: " . $e->getMessage();
        error_log("Expense deletion error: " . $e->getMessage());
    }
}

// Function to reverse daywise amounts when expense is deleted
function reverseDaywiseAmounts($pdo, $expense) {
    try {
        // Check if daywise record exists for this date
        $checkStmt = $pdo->prepare("SELECT id, expenses_cash, expenses_bank FROM daywise_amounts WHERE amount_date = :amount_date");
        $checkStmt->execute([':amount_date' => $expense['expense_date']]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            // Update existing record
            if ($expense['payment_method'] == 'cash') {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_cash = GREATEST(expenses_cash - :amount, 0),
                        closing_cash = opening_cash + cash_sales + cash_received - (cash_purchases + GREATEST(expenses_cash - :amount, 0) + cash_paid)
                    WHERE id = :id
                ");
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET expenses_bank = GREATEST(expenses_bank - :amount, 0),
                        closing_bank = opening_bank + credit_sales + bank_deposits - (credit_purchases + GREATEST(expenses_bank - :amount, 0) + bank_withdrawals)
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
        // Don't throw exception - non-critical feature
    }
}

// Get expenses with filters and pagination
try {
    $query = "
        SELECT e.*, 
               s.name as supplier_name,
               s.company_name as supplier_company,
               v.vehicle_number,
               u.full_name as created_by_name
        FROM expenses e
        LEFT JOIN suppliers s ON e.supplier_id = s.id
        LEFT JOIN vehicles v ON e.vehicle_id = v.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE 1=1
    ";
    
    $count_query = "
        SELECT COUNT(*) as total 
        FROM expenses e
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply date filters
    if (!empty($filter_date_from)) {
        $query .= " AND DATE(e.expense_date) >= :date_from";
        $count_query .= " AND DATE(e.expense_date) >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND DATE(e.expense_date) <= :date_to";
        $count_query .= " AND DATE(e.expense_date) <= :date_to";
        $params[':date_to'] = $filter_date_to;
    }
    
    // Apply category filter
    if (!empty($filter_category)) {
        $query .= " AND e.category = :category";
        $count_query .= " AND e.category = :category";
        $params[':category'] = $filter_category;
    }
    
    // Apply payment method filter
    if (!empty($filter_payment_method)) {
        $query .= " AND e.payment_method = :payment_method";
        $count_query .= " AND e.payment_method = :payment_method";
        $params[':payment_method'] = $filter_payment_method;
    }
    
    // Apply supplier filter
    if (!empty($filter_supplier)) {
        $query .= " AND e.supplier_id = :supplier_id";
        $count_query .= " AND e.supplier_id = :supplier_id";
        $params[':supplier_id'] = $filter_supplier;
    }
    
    // Apply search
    if (!empty($search)) {
        $query .= " AND (e.expense_number LIKE :search OR e.description LIKE :search OR e.reference_number LIKE :search OR e.notes LIKE :search)";
        $count_query .= " AND (e.expense_number LIKE :search OR e.description LIKE :search OR e.reference_number LIKE :search OR e.notes LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Get total records for pagination
    $countStmt = $pdo->prepare($count_query);
    $countStmt->execute($params);
    $total_records = $countStmt->fetch()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    
    // Add ordering and pagination
    $query .= " ORDER BY e.expense_date DESC, e.created_at DESC LIMIT :offset, :limit";
    
    $stmt = $pdo->prepare($query);
    
    // Bind parameters for pagination
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    
    $stmt->execute();
    $expenses = $stmt->fetchAll();
    
    // Get summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_expenses,
            SUM(total_amount) as total_amount,
            SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_total,
            SUM(CASE WHEN payment_method IN ('bank', 'cheque', 'online') THEN total_amount ELSE 0 END) as bank_total,
            COUNT(DISTINCT category) as unique_categories,
            AVG(total_amount) as average_amount
        FROM expenses e
        WHERE 1=1
    ";
    
    $summary_params = [];
    if (!empty($filter_date_from)) {
        $summary_query .= " AND DATE(expense_date) >= :date_from";
        $summary_params[':date_from'] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $summary_query .= " AND DATE(expense_date) <= :date_to";
        $summary_params[':date_to'] = $filter_date_to;
    }
    if (!empty($filter_category)) {
        $summary_query .= " AND category = :category";
        $summary_params[':category'] = $filter_category;
    }
    if (!empty($filter_supplier)) {
        $summary_query .= " AND supplier_id = :supplier_id";
        $summary_params[':supplier_id'] = $filter_supplier;
    }
    
    $summaryStmt = $pdo->prepare($summary_query);
    $summaryStmt->execute($summary_params);
    $summary = $summaryStmt->fetch();
    
    // Get category-wise breakdown for chart
    $category_query = "
        SELECT 
            category,
            COUNT(*) as count,
            SUM(total_amount) as total
        FROM expenses e
        WHERE 1=1
    ";
    
    $category_params = [];
    if (!empty($filter_date_from)) {
        $category_query .= " AND DATE(expense_date) >= :date_from";
        $category_params[':date_from'] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $category_query .= " AND DATE(expense_date) <= :date_to";
        $category_params[':date_to'] = $filter_date_to;
    }
    
    $category_query .= " GROUP BY category ORDER BY total DESC LIMIT 10";
    
    $categoryStmt = $pdo->prepare($category_query);
    $categoryStmt->execute($category_params);
    $category_breakdown = $categoryStmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Expenses fetch error: " . $e->getMessage());
    $expenses = [];
    $total_records = 0;
    $total_pages = 0;
    $summary = [
        'total_expenses' => 0,
        'total_amount' => 0,
        'cash_total' => 0,
        'bank_total' => 0,
        'unique_categories' => 0,
        'average_amount' => 0
    ];
    $category_breakdown = [];
}

// Handle AJAX request for bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    header('Content-Type: application/json');
    
    try {
        $pdo->beginTransaction();
        
        $selected_ids = isset($_POST['selected_ids']) ? json_decode($_POST['selected_ids'], true) : [];
        $action = $_POST['bulk_action'];
        
        if (empty($selected_ids)) {
            throw new Exception('No expenses selected');
        }
        
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        if ($action === 'delete') {
            // Get expenses for reversal
            $expenseStmt = $pdo->prepare("SELECT * FROM expenses WHERE id IN ($placeholders)");
            $expenseStmt->execute($selected_ids);
            $expenses_to_delete = $expenseStmt->fetchAll();
            
            foreach ($expenses_to_delete as $expense) {
                reverseDaywiseAmounts($pdo, $expense);
            }
            
            // Delete expenses
            $deleteStmt = $pdo->prepare("DELETE FROM expenses WHERE id IN ($placeholders)");
            $deleteStmt->execute($selected_ids);
            
            // Log bulk activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (:user_id, 5, :description, :activity_data, :created_by)
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "Bulk delete: " . count($selected_ids) . " expenses",
                ':activity_data' => json_encode(['ids' => $selected_ids]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $message = count($selected_ids) . ' expenses deleted successfully';
        } else {
            throw new Exception('Invalid bulk action');
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
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
                            <h4 class="mb-0 font-size-18">Manage Expenses</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Expenses</a></li>
                                    <li class="breadcrumb-item active">Manage Expenses</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="add-expense.php" class="btn btn-primary">
                                        <i class="mdi mdi-plus"></i> Add New Expense
                                    </a>
                                    <button type="button" class="btn btn-success" id="exportBtn">
                                        <i class="mdi mdi-export"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-info" id="printBtn">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                    <button type="button" class="btn btn-danger" id="bulkDeleteBtn" disabled>
                                        <i class="mdi mdi-delete"></i> Delete Selected
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-cash-multiple font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Expenses</p>
                                        <h4><?= number_format($summary['total_expenses'] ?? 0) ?></h4>
                                        <small class="text-muted">This period</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-currency-inr font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Amount</p>
                                        <h4>₹<?= number_format($summary['total_amount'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">All expenses</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-cash font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Cash / Bank</p>
                                        <h4>₹<?= number_format($summary['cash_total'] ?? 0, 2) ?> / ₹<?= number_format($summary['bank_total'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Cash vs Bank</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-chart-line font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Average Expense</p>
                                        <h4>₹<?= number_format($summary['average_amount'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Per transaction</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end summary cards -->

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Expenses</h4>
                                <form method="GET" action="manage-expenses.php" class="row">
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_category" class="form-label">Category</label>
                                            <select class="form-control" id="filter_category" name="filter_category">
                                                <option value="">All Categories</option>
                                                <?php foreach ($expense_categories as $key => $value): ?>
                                                <option value="<?= $key ?>" <?= $filter_category == $key ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($value) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_payment_method" class="form-label">Payment Method</label>
                                            <select class="form-control" id="filter_payment_method" name="filter_payment_method">
                                                <option value="">All Methods</option>
                                                <option value="cash" <?= $filter_payment_method == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                <option value="bank" <?= $filter_payment_method == 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                                <option value="cheque" <?= $filter_payment_method == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                <option value="online" <?= $filter_payment_method == 'online' ? 'selected' : '' ?>>Online</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_supplier" class="form-label">Supplier</label>
                                            <select class="form-control" id="filter_supplier" name="filter_supplier">
                                                <option value="">All Suppliers</option>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?= $supplier['id'] ?>" <?= $filter_supplier == $supplier['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($supplier['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="manage-expenses.php" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                                
                                <!-- Search Bar -->
                                <form method="GET" action="manage-expenses.php" class="row mt-3">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Search by expense number, description, reference number..." value="<?= htmlspecialchars($search) ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="mdi mdi-magnify"></i> Search
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="per_page" class="form-select" onchange="this.form.submit()">
                                            <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                            <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20 per page</option>
                                            <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                            <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                        </select>
                                    </div>
                                    <?php 
                                    // Preserve other filter parameters
                                    foreach (['filter_date_from', 'filter_date_to', 'filter_category', 'filter_payment_method', 'filter_supplier'] as $param):
                                        if (!empty($_GET[$param])):
                                    ?>
                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param]) ?>">
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Category Breakdown Chart -->
                <?php if (!empty($category_breakdown)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Expense Breakdown by Category</h4>
                                <div id="category-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Expenses Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Expense List</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <span class="text-muted">
                                                Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th width="40">
                                                    <input type="checkbox" id="selectAll">
                                                </th>
                                                <th>Expense #</th>
                                                <th>Date</th>
                                                <th>Category</th>
                                                <th>Description</th>
                                                <th>Payment Method</th>
                                                <th>Supplier/Vehicle</th>
                                                <th>Amount</th>
                                                <th>GST</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($expenses)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No expenses found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($expenses as $expense): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" class="expense-checkbox" value="<?= $expense['id'] ?>">
                                                    </td>
                                                    <td>
                                                        <h5 class="font-size-14 mb-1">
                                                            <a href="view-expense.php?id=<?= $expense['id'] ?>" class="text-dark">
                                                                <?= htmlspecialchars($expense['expense_number']) ?>
                                                            </a>
                                                        </h5>
                                                    </td>
                                                    <td><?= date('d M Y', strtotime($expense['expense_date'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-primary text-primary">
                                                            <?= htmlspecialchars($expense_categories[$expense['category']] ?? $expense['category']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($expense['description']) ?>">
                                                            <?= htmlspecialchars($expense['description']) ?>
                                                        </div>
                                                        <?php if (!empty($expense['reference_number'])): ?>
                                                            <small class="text-muted">Ref: <?= htmlspecialchars($expense['reference_number']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $methodClass = '';
                                                        $methodIcon = '';
                                                        switch($expense['payment_method']) {
                                                            case 'cash':
                                                                $methodClass = 'success';
                                                                $methodIcon = 'mdi-cash';
                                                                break;
                                                            case 'bank':
                                                                $methodClass = 'primary';
                                                                $methodIcon = 'mdi-bank';
                                                                break;
                                                            case 'cheque':
                                                                $methodClass = 'warning';
                                                                $methodIcon = 'mdi-file-document';
                                                                break;
                                                            case 'online':
                                                                $methodClass = 'info';
                                                                $methodIcon = 'mdi-credit-card';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $methodClass ?> text-<?= $methodClass ?>">
                                                            <i class="mdi <?= $methodIcon ?>"></i> <?= ucfirst($expense['payment_method']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($expense['supplier_id']): ?>
                                                            <small>
                                                                <i class="mdi mdi-truck"></i> 
                                                                <?= htmlspecialchars($expense['supplier_name']) ?>
                                                                <?= $expense['supplier_company'] ? '(' . htmlspecialchars($expense['supplier_company']) . ')' : '' ?>
                                                            </small>
                                                        <?php elseif ($expense['vehicle_id']): ?>
                                                            <small>
                                                                <i class="mdi mdi-car"></i> 
                                                                <?= htmlspecialchars($expense['vehicle_number']) ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>₹<?= number_format($expense['amount'], 2) ?></td>
                                                    <td>
                                                        <?php if ($expense['gst_amount'] > 0): ?>
                                                            <span class="text-muted">₹<?= number_format($expense['gst_amount'], 2) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong>₹<?= number_format($expense['total_amount'], 2) ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        // You can implement expense status if needed
                                                        ?>
                                                        <span class="badge bg-soft-success text-success">Completed</span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <a href="view-expense.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-soft-primary" title="View">
                                                                <i class="mdi mdi-eye"></i>
                                                            </a>
                                                            <a href="edit-expense.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-soft-info" title="Edit">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-soft-danger" title="Delete" 
                                                                    onclick="confirmDelete(<?= $expense['id'] ?>, '<?= htmlspecialchars($expense['expense_number']) ?>')">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="row mt-4">
                                    <div class="col-sm-6">
                                        <div class="text-muted">
                                            Page <?= $page ?> of <?= $total_pages ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>" tabindex="-1">Previous</a>
                                            </li>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($i) ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end table -->

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete expense <strong id="deleteExpenseNumber"></strong>?</p>
                <p class="text-danger"><small>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- Chart JS -->
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Delete confirmation
    function confirmDelete(id, expenseNumber) {
        document.getElementById('deleteExpenseNumber').textContent = expenseNumber;
        document.getElementById('confirmDeleteBtn').href = 'manage-expenses.php?action=delete&id=' + id;
        var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Select all checkboxes
    document.getElementById('selectAll').addEventListener('change', function() {
        var checkboxes = document.querySelectorAll('.expense-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = this.checked;
        }, this);
        updateBulkDeleteButton();
    });

    // Individual checkbox change
    document.querySelectorAll('.expense-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', updateBulkDeleteButton);
    });

    // Update bulk delete button state
    function updateBulkDeleteButton() {
        var checkedCount = document.querySelectorAll('.expense-checkbox:checked').length;
        var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
        
        if (checkedCount > 0) {
            bulkDeleteBtn.disabled = false;
            bulkDeleteBtn.innerHTML = '<i class="mdi mdi-delete"></i> Delete Selected (' + checkedCount + ')';
        } else {
            bulkDeleteBtn.disabled = true;
            bulkDeleteBtn.innerHTML = '<i class="mdi mdi-delete"></i> Delete Selected';
        }
    }

    // Bulk delete
    document.getElementById('bulkDeleteBtn').addEventListener('click', function() {
        var selectedIds = [];
        document.querySelectorAll('.expense-checkbox:checked').forEach(function(checkbox) {
            selectedIds.push(checkbox.value);
        });
        
        if (selectedIds.length === 0) return;
        
        if (confirm('Are you sure you want to delete ' + selectedIds.length + ' selected expenses? This action cannot be undone.')) {
            // Send AJAX request for bulk delete
            fetch('manage-expenses.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'bulk_action=delete&selected_ids=' + JSON.stringify(selectedIds)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while processing your request');
            });
        }
    });

    // Export functionality
    document.getElementById('exportBtn').addEventListener('click', function() {
        var params = new URLSearchParams(window.location.search);
        params.append('export', 'csv');
        window.location.href = 'export-expenses.php?' + params.toString();
    });

    // Print functionality
    document.getElementById('printBtn').addEventListener('click', function() {
        window.print();
    });

    // Category Chart
    <?php if (!empty($category_breakdown)): ?>
    var categoryData = <?= json_encode($category_breakdown) ?>;
    
    var options = {
        chart: {
            height: 350,
            type: 'bar'
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
                endingShape: 'rounded'
            },
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            show: true,
            width: 2,
            colors: ['transparent']
        },
        series: [{
            name: 'Total Amount',
            data: categoryData.map(item => parseFloat(item.total))
        }],
        xaxis: {
            categories: categoryData.map(item => {
                var category = item.category;
                <?php foreach ($expense_categories as $key => $value): ?>
                if (category === '<?= $key ?>') return '<?= addslashes($value) ?>';
                <?php endforeach; ?>
                return category;
            })
        },
        yaxis: {
            title: {
                text: 'Amount (₹)'
            }
        },
        fill: {
            opacity: 1
        },
        colors: ['#556ee6'],
        tooltip: {
            y: {
                formatter: function(val) {
                    return "₹ " + val.toFixed(2)
                }
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#category-chart"), options);
    chart.render();
    <?php endif; ?>
</script>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, .apex-charts, 
    .page-title-right, .card-title .btn, .action-buttons,
    #filter_row, #selectAll, .expense-checkbox, .btn-group {
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
    .badge {
        border: 1px solid #000;
        color: #000 !important;
        background: transparent !important;
    }
}
</style>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'manage-expenses.php?' . http_build_query($params);
}
?>

</body>
</html>