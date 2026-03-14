<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get filter parameters
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : date('Y-m-01');
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : date('Y-m-d');
$transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Handle AJAX request for opening balance
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_opening_balance') {
    header('Content-Type: application/json');
    
    try {
        $as_on_date = $_GET['as_on_date'] ?? $filter_date_from;
        
        // Get closing balance from previous day
        $prev_date = date('Y-m-d', strtotime($as_on_date . ' -1 day'));
        
        $stmt = $pdo->prepare("
            SELECT closing_cash as balance 
            FROM daywise_amounts 
            WHERE amount_date = :prev_date
        ");
        $stmt->execute([':prev_date' => $prev_date]);
        $result = $stmt->fetch();
        
        $opening_balance = $result ? floatval($result['balance']) : 0;
        
        echo json_encode([
            'success' => true, 
            'opening_balance' => $opening_balance,
            'formatted' => '₹' . number_format($opening_balance, 2)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($pdo, $filter_date_from, $filter_date_to, $transaction_type, $search);
    exit();
}

// Export function
function exportToCSV($pdo, $date_from, $date_to, $transaction_type, $search) {
    $filename = 'cash_book_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, [
        'Date',
        'Voucher Type',
        'Voucher No',
        'Description',
        'Category',
        'Payment Mode',
        'Receipts (Dr)',
        'Payments (Cr)',
        'Balance'
    ]);
    
    try {
        $transactions = getCashBookTransactions($pdo, $date_from, $date_to, $transaction_type, $search, 0, 999999);
        
        // Calculate opening balance
        $opening_balance = getOpeningBalance($pdo, $date_from);
        $running_balance = $opening_balance;
        
        // Add opening balance row
        fputcsv($output, [
            $date_from,
            'Opening',
            '',
            'Opening Balance',
            '',
            '',
            '',
            '',
            '₹' . number_format($opening_balance, 2)
        ]);
        
        // Add transactions
        foreach ($transactions as $trans) {
            $running_balance = $trans['balance'];
            fputcsv($output, [
                $trans['date'],
                $trans['voucher_type'],
                $trans['voucher_no'],
                $trans['description'],
                $trans['category'],
                $trans['payment_mode'],
                $trans['receipts'] > 0 ? '₹' . number_format($trans['receipts'], 2) : '',
                $trans['payments'] > 0 ? '₹' . number_format($trans['payments'], 2) : '',
                '₹' . number_format($trans['balance'], 2)
            ]);
        }
        
        // Add closing balance row
        fputcsv($output, [
            $date_to,
            'Closing',
            '',
            'Closing Balance',
            '',
            '',
            '',
            '',
            '₹' . number_format($running_balance, 2)
        ]);
        
    } catch (Exception $e) {
        fputcsv($output, ['Error: ' . $e->getMessage()]);
    }
    
    fclose($output);
}

// Function to get opening balance for a date
function getOpeningBalance($pdo, $date) {
    try {
        $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
        
        $stmt = $pdo->prepare("
            SELECT closing_cash as balance 
            FROM daywise_amounts 
            WHERE amount_date = :prev_date
        ");
        $stmt->execute([':prev_date' => $prev_date]);
        $result = $stmt->fetch();
        
        return $result ? floatval($result['balance']) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Function to get cash book transactions
function getCashBookTransactions($pdo, $date_from, $date_to, $transaction_type = 'all', $search = '', $offset = 0, $limit = 50) {
    $transactions = [];
    
    try {
        // Get cash receipts from various sources
        $queries = [];
        
        // 1. Cash Sales from daywise_amounts
        if ($transaction_type == 'all' || $transaction_type == 'receipts') {
            $queries[] = "
                SELECT 
                    amount_date as date,
                    'Sales' as voucher_type,
                    'SALES' as voucher_no,
                    'Cash Sales' as description,
                    'Sales' as category,
                    'Cash' as payment_mode,
                    cash_sales as receipts,
                    0 as payments,
                    created_at
                FROM daywise_amounts
                WHERE amount_date BETWEEN :date_from AND :date_to
                AND cash_sales > 0
            ";
        }
        
        // 2. Cash Received from daywise_amounts
        if ($transaction_type == 'all' || $transaction_type == 'receipts') {
            $queries[] = "
                SELECT 
                    amount_date as date,
                    'Receipt' as voucher_type,
                    'REC' as voucher_no,
                    'Cash Received' as description,
                    'Receipts' as category,
                    'Cash' as payment_mode,
                    cash_received as receipts,
                    0 as payments,
                    created_at
                FROM daywise_amounts
                WHERE amount_date BETWEEN :date_from AND :date_to
                AND cash_received > 0
            ";
        }
        
        // 3. Cash Purchases from daywise_amounts
        if ($transaction_type == 'all' || $transaction_type == 'payments') {
            $queries[] = "
                SELECT 
                    amount_date as date,
                    'Purchase' as voucher_type,
                    'PUR' as voucher_no,
                    'Cash Purchases' as description,
                    'Purchases' as category,
                    'Cash' as payment_mode,
                    0 as receipts,
                    cash_purchases as payments,
                    created_at
                FROM daywise_amounts
                WHERE amount_date BETWEEN :date_from AND :date_to
                AND cash_purchases > 0
            ";
        }
        
        // 4. Cash Expenses from daywise_amounts
        if ($transaction_type == 'all' || $transaction_type == 'payments') {
            $queries[] = "
                SELECT 
                    amount_date as date,
                    'Expense' as voucher_type,
                    'EXP' as voucher_no,
                    'Cash Expenses' as description,
                    'Expenses' as category,
                    'Cash' as payment_mode,
                    0 as receipts,
                    expenses_cash as payments,
                    created_at
                FROM daywise_amounts
                WHERE amount_date BETWEEN :date_from AND :date_to
                AND expenses_cash > 0
            ";
        }
        
        // 5. Cash Paid from daywise_amounts
        if ($transaction_type == 'all' || $transaction_type == 'payments') {
            $queries[] = "
                SELECT 
                    amount_date as date,
                    'Payment' as voucher_type,
                    'PAY' as voucher_no,
                    'Cash Paid' as description,
                    'Payments' as category,
                    'Cash' as payment_mode,
                    0 as receipts,
                    cash_paid as payments,
                    created_at
                FROM daywise_amounts
                WHERE amount_date BETWEEN :date_from AND :date_to
                AND cash_paid > 0
            ";
        }
        
        // 6. Expenses from expenses table (cash payments)
        if ($transaction_type == 'all' || $transaction_type == 'payments') {
            $queries[] = "
                SELECT 
                    expense_date as date,
                    'Expense' as voucher_type,
                    expense_number as voucher_no,
                    description,
                    category,
                    payment_mode,
                    0 as receipts,
                    total_amount as payments,
                    created_at
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
                AND payment_method = 'cash'
                AND total_amount > 0
            ";
        }
        
        // 7. Invoices with cash payments
        if ($transaction_type == 'all' || $transaction_type == 'receipts') {
            $queries[] = "
                SELECT 
                    i.invoice_date as date,
                    'Invoice' as voucher_type,
                    i.invoice_number as voucher_no,
                    CONCAT('Invoice from ', c.name) as description,
                    'Sales' as category,
                    i.payment_type as payment_mode,
                    i.total_amount as receipts,
                    0 as payments,
                    i.created_at
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.invoice_date BETWEEN :date_from AND :date_to
                AND i.payment_type = 'cash'
                AND i.total_amount > 0
            ";
        }
        
        // 8. Purchase Orders with cash payments
        if ($transaction_type == 'all' || $transaction_type == 'payments') {
            $queries[] = "
                SELECT 
                    po.order_date as date,
                    'Purchase' as voucher_type,
                    po.po_number as voucher_no,
                    CONCAT('Purchase from ', s.name) as description,
                    'Purchases' as category,
                    'cash' as payment_mode,
                    0 as receipts,
                    po.total_amount as payments,
                    po.created_at
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE po.order_date BETWEEN :date_from AND :date_to
                AND po.total_amount > 0
            ";
        }
        
        if (empty($queries)) {
            return [];
        }
        
        // Combine all queries with UNION
        $union_query = implode(" UNION ALL ", $queries);
        $union_query .= " ORDER BY date ASC, created_at ASC";
        
        // Add pagination
        $union_query .= " LIMIT :offset, :limit";
        
        $stmt = $pdo->prepare($union_query);
        $stmt->bindValue(':date_from', $date_from);
        $stmt->bindValue(':date_to', $date_to);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $transactions = $stmt->fetchAll();
        
        // Apply search filter if needed
        if (!empty($search)) {
            $transactions = array_filter($transactions, function($t) use ($search) {
                return stripos($t['description'], $search) !== false || 
                       stripos($t['voucher_no'], $search) !== false ||
                       stripos($t['category'], $search) !== false;
            });
        }
        
        // Calculate running balance
        $opening_balance = getOpeningBalance($pdo, $date_from);
        $running_balance = $opening_balance;
        
        foreach ($transactions as &$trans) {
            $running_balance = $running_balance + floatval($trans['receipts']) - floatval($trans['payments']);
            $trans['balance'] = $running_balance;
        }
        
    } catch (Exception $e) {
        error_log("Cash book transactions error: " . $e->getMessage());
    }
    
    return $transactions;
}

// Get total count for pagination
function getTotalTransactionsCount($pdo, $date_from, $date_to, $transaction_type = 'all', $search = '') {
    try {
        $transactions = getCashBookTransactions($pdo, $date_from, $date_to, $transaction_type, $search, 0, 999999);
        return count($transactions);
    } catch (Exception $e) {
        return 0;
    }
}

// Get transactions for current page
$transactions = getCashBookTransactions($pdo, $filter_date_from, $filter_date_to, $transaction_type, $search, $offset, $records_per_page);
$total_records = getTotalTransactionsCount($pdo, $filter_date_from, $filter_date_to, $transaction_type, $search);
$total_pages = ceil($total_records / $records_per_page);

// Get summary statistics
$total_receipts = 0;
$total_payments = 0;

foreach ($transactions as $trans) {
    $total_receipts += floatval($trans['receipts']);
    $total_payments += floatval($trans['payments']);
}

$opening_balance = getOpeningBalance($pdo, $filter_date_from);
$closing_balance = $opening_balance + $total_receipts - $total_payments;

// Get cash balance trend for mini chart
try {
    $trend_query = "
        SELECT 
            amount_date,
            closing_cash
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
        ORDER BY amount_date ASC
    ";
    $trendStmt = $pdo->prepare($trend_query);
    $trendStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $trend_data = $trendStmt->fetchAll();
} catch (Exception $e) {
    $trend_data = [];
}

// Handle success/error messages
$message = isset($_GET['message']) ? $_GET['message'] : '';
$messageType = isset($_GET['message_type']) ? $_GET['message_type'] : 'info';
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
                            <h4 class="mb-0 font-size-18">Cash Book</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Accounts</a></li>
                                    <li class="breadcrumb-item active">Cash Book</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Alert Message -->
                <?php if (!empty($message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-<?= $messageType == 'success' ? 'check-circle' : 'alert-circle' ?> me-2"></i>
                            <?= htmlspecialchars($message) ?>
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
                                    <a href="add-cash-receipt.php" class="btn btn-success">
                                        <i class="mdi mdi-cash-plus"></i> Add Receipt
                                    </a>
                                    <a href="add-cash-payment.php" class="btn btn-danger">
                                        <i class="mdi mdi-cash-minus"></i> Add Payment
                                    </a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                    <button type="button" class="btn btn-warning" id="refreshBtn">
                                        <i class="mdi mdi-refresh"></i> Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Balance Summary Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-cash font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Opening Balance</p>
                                        <h4>₹<?= number_format($opening_balance, 2) ?></h4>
                                        <small class="text-muted">As on <?= date('d M Y', strtotime($filter_date_from)) ?></small>
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
                                                <i class="mdi mdi-arrow-up-bold-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Receipts</p>
                                        <h4>₹<?= number_format($total_receipts, 2) ?></h4>
                                        <small class="text-muted">During period</small>
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
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="mdi mdi-arrow-down-bold-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Payments</p>
                                        <h4>₹<?= number_format($total_payments, 2) ?></h4>
                                        <small class="text-muted">During period</small>
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
                                                <i class="mdi mdi-bank font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Closing Balance</p>
                                        <h4>₹<?= number_format($closing_balance, 2) ?></h4>
                                        <small class="text-muted">As on <?= date('d M Y', strtotime($filter_date_to)) ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Balance Trend Mini Chart -->
                <?php if (!empty($trend_data)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Cash Balance Trend</h4>
                                <div id="balance-trend-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Cash Book</h4>
                                <form method="GET" action="cash-book.php" class="row" id="filterForm">
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
                                            <label for="transaction_type" class="form-label">Transaction Type</label>
                                            <select class="form-control" id="transaction_type" name="transaction_type">
                                                <option value="all" <?= $transaction_type == 'all' ? 'selected' : '' ?>>All Transactions</option>
                                                <option value="receipts" <?= $transaction_type == 'receipts' ? 'selected' : '' ?>>Only Receipts</option>
                                                <option value="payments" <?= $transaction_type == 'payments' ? 'selected' : '' ?>>Only Payments</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="Description, Voucher #..." value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="cash-book.php" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i> Reset
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Book Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Cash Book Entries</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <form method="GET" action="cash-book.php" class="d-inline-block">
                                                <select name="per_page" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                    <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                                                    <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                                    <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                                    <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200 per page</option>
                                                </select>
                                                <?php foreach (['filter_date_from', 'filter_date_to', 'transaction_type', 'search'] as $param): ?>
                                                    <?php if (!empty($_GET[$param])): ?>
                                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param]) ?>">
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Voucher Type</th>
                                                <th>Voucher No</th>
                                                <th>Description</th>
                                                <th>Category</th>
                                                <th>Payment Mode</th>
                                                <th class="text-success">Receipts (Dr)</th>
                                                <th class="text-danger">Payments (Cr)</th>
                                                <th>Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Opening Balance Row -->
                                            <tr class="table-primary">
                                                <td><strong><?= date('d M Y', strtotime($filter_date_from)) ?></strong></td>
                                                <td><strong>Opening</strong></td>
                                                <td>—</td>
                                                <td><strong>Opening Balance</strong></td>
                                                <td>—</td>
                                                <td>—</td>
                                                <td>—</td>
                                                <td>—</td>
                                                <td><strong>₹<?= number_format($opening_balance, 2) ?></strong></td>
                                            </tr>
                                            
                                            <?php if (empty($transactions)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No transactions found for selected filters</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php 
                                                $running_balance = $opening_balance;
                                                foreach ($transactions as $trans): 
                                                    $running_balance = $trans['balance'];
                                                ?>
                                                <tr>
                                                    <td><?= date('d M Y', strtotime($trans['date'])) ?></td>
                                                    <td>
                                                        <?php
                                                        $badgeClass = 'secondary';
                                                        switch($trans['voucher_type']) {
                                                            case 'Sales':
                                                            case 'Receipt':
                                                            case 'Invoice':
                                                                $badgeClass = 'success';
                                                                break;
                                                            case 'Purchase':
                                                            case 'Expense':
                                                            case 'Payment':
                                                                $badgeClass = 'danger';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $badgeClass ?> text-<?= $badgeClass ?>">
                                                            <?= htmlspecialchars($trans['voucher_type']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="#" class="text-primary"><?= htmlspecialchars($trans['voucher_no']) ?></a>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($trans['description']) ?>">
                                                            <?= htmlspecialchars($trans['description']) ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($trans['category']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($trans['payment_mode']): ?>
                                                            <span class="badge bg-soft-info text-info">
                                                                <?= ucfirst($trans['payment_mode']) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-success">
                                                        <?php if ($trans['receipts'] > 0): ?>
                                                            <strong>₹<?= number_format($trans['receipts'], 2) ?></strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-danger">
                                                        <?php if ($trans['payments'] > 0): ?>
                                                            <strong>₹<?= number_format($trans['payments'], 2) ?></strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong>₹<?= number_format($trans['balance'], 2) ?></strong>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Closing Balance Row -->
                                            <tr class="table-info">
                                                <td><strong><?= date('d M Y', strtotime($filter_date_to)) ?></strong></td>
                                                <td><strong>Closing</strong></td>
                                                <td>—</td>
                                                <td><strong>Closing Balance</strong></td>
                                                <td>—</td>
                                                <td>—</td>
                                                <td class="text-success"><strong>₹<?= number_format($total_receipts, 2) ?></strong></td>
                                                <td class="text-danger"><strong>₹<?= number_format($total_payments, 2) ?></strong></td>
                                                <td><strong>₹<?= number_format($closing_balance, 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="row mt-4">
                                    <div class="col-sm-6">
                                        <div class="text-muted">
                                            Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1, $_GET) ?>" tabindex="-1">Previous</a>
                                            </li>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($i, $_GET) ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1, $_GET) ?>">Next</a>
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

    // Balance Trend Chart
    <?php if (!empty($trend_data)): ?>
    var trendData = <?= json_encode($trend_data) ?>;
    var trendDates = trendData.map(item => {
        var date = new Date(item.amount_date + 'T12:00:00');
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    var trendBalances = trendData.map(item => parseFloat(item.closing_cash));

    var options = {
        chart: {
            height: 100,
            type: 'area',
            sparkline: {
                enabled: true
            },
            toolbar: {
                show: false
            }
        },
        series: [{
            name: 'Cash Balance',
            data: trendBalances
        }],
        stroke: {
            curve: 'smooth',
            width: 2
        },
        fill: {
            opacity: 0.3,
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.3
            }
        },
        colors: ['#556ee6'],
        tooltip: {
            fixed: {
                enabled: false
            },
            x: {
                show: true,
                formatter: function(index) {
                    return trendDates[index] || '';
                }
            },
            y: {
                formatter: function(val) {
                    return '₹' + val.toFixed(2);
                }
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#balance-trend-chart"), options);
    chart.render();
    <?php endif; ?>

    // Refresh button
    document.getElementById('refreshBtn').addEventListener('click', function() {
        location.reload();
    });

    // Auto-refresh opening balance when date changes
    document.getElementById('filter_date_from').addEventListener('change', function() {
        var date = this.value;
        if (date) {
            fetch('cash-book.php?ajax=get_opening_balance&as_on_date=' + encodeURIComponent(date))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // You could update the opening balance display here
                        console.log('Opening balance for ' + date + ': ' + data.formatted);
                    }
                })
                .catch(error => console.error('Error:', error));
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

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + R - Refresh
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            location.reload();
        }
        // Ctrl + E - Export
        if (e.ctrlKey && e.key === 'e') {
            e.preventDefault();
            window.location.href = '?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>';
        }
        // Ctrl + P - Print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page, $params) {
    $params['page'] = $page;
    return 'cash-book.php?' . http_build_query($params);
}
?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    #refreshBtn, form, .apex-charts {
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
        font-size: 9pt;
    }
    .badge {
        border: 1px solid #000;
        color: #000 !important;
        background: transparent !important;
    }
    .table-primary, .table-info {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

.table td {
    vertical-align: middle;
}

.table-primary {
    background-color: #e7f1ff;
}

.table-info {
    background-color: #d1ecf1;
}

/* Hover effect on rows */
.table tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}

/* Balance column styling */
.table td:last-child {
    font-weight: 500;
    color: #495057;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table td:nth-child(4) {
        max-width: 150px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
}
</style>

</body>
</html>