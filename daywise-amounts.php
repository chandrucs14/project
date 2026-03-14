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
$view_mode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'daily'; // daily, weekly, monthly

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 31;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Handle AJAX request for chart data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'chart_data') {
    header('Content-Type: application/json');
    
    try {
        $chart_query = "
            SELECT 
                amount_date,
                opening_cash,
                opening_bank,
                cash_sales,
                credit_sales,
                cash_purchases,
                credit_purchases,
                expenses_cash,
                expenses_bank,
                cash_received,
                cash_paid,
                bank_deposits,
                bank_withdrawals,
                closing_cash,
                closing_bank,
                (closing_cash + closing_bank) as total_balance
            FROM daywise_amounts
            WHERE amount_date BETWEEN :date_from AND :date_to
            ORDER BY amount_date ASC
        ";
        
        $chartStmt = $pdo->prepare($chart_query);
        $chartStmt->execute([
            ':date_from' => $_GET['filter_date_from'] ?? $filter_date_from,
            ':date_to' => $_GET['filter_date_to'] ?? $filter_date_to
        ]);
        $chart_data = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $chart_data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($pdo, $filter_date_from, $filter_date_to);
    exit();
}

// Export function
function exportToCSV($pdo, $date_from, $date_to) {
    $filename = 'daywise_amounts_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, [
        'Date',
        'Opening Cash',
        'Opening Bank',
        'Cash Sales',
        'Credit Sales',
        'Cash Purchases',
        'Credit Purchases',
        'Cash Expenses',
        'Bank Expenses',
        'Cash Received',
        'Cash Paid',
        'Bank Deposits',
        'Bank Withdrawals',
        'Closing Cash',
        'Closing Bank',
        'Total Balance'
    ]);
    
    try {
        $query = "
            SELECT 
                amount_date,
                opening_cash,
                opening_bank,
                cash_sales,
                credit_sales,
                cash_purchases,
                credit_purchases,
                expenses_cash,
                expenses_bank,
                cash_received,
                cash_paid,
                bank_deposits,
                bank_withdrawals,
                closing_cash,
                closing_bank,
                (closing_cash + closing_bank) as total_balance
            FROM daywise_amounts
            WHERE amount_date BETWEEN :date_from AND :date_to
            ORDER BY amount_date ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['amount_date'],
                number_format($row['opening_cash'], 2),
                number_format($row['opening_bank'], 2),
                number_format($row['cash_sales'], 2),
                number_format($row['credit_sales'], 2),
                number_format($row['cash_purchases'], 2),
                number_format($row['credit_purchases'], 2),
                number_format($row['expenses_cash'], 2),
                number_format($row['expenses_bank'], 2),
                number_format($row['cash_received'], 2),
                number_format($row['cash_paid'], 2),
                number_format($row['bank_deposits'], 2),
                number_format($row['bank_withdrawals'], 2),
                number_format($row['closing_cash'], 2),
                number_format($row['closing_bank'], 2),
                number_format($row['total_balance'], 2)
            ]);
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error: ' . $e->getMessage()]);
    }
    
    fclose($output);
}

// Handle form submission for adding/updating daywise amounts
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            $pdo->beginTransaction();
            
            if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
                $amount_date = $_POST['amount_date'];
                $opening_cash = floatval($_POST['opening_cash'] ?? 0);
                $opening_bank = floatval($_POST['opening_bank'] ?? 0);
                $cash_sales = floatval($_POST['cash_sales'] ?? 0);
                $credit_sales = floatval($_POST['credit_sales'] ?? 0);
                $cash_purchases = floatval($_POST['cash_purchases'] ?? 0);
                $credit_purchases = floatval($_POST['credit_purchases'] ?? 0);
                $expenses_cash = floatval($_POST['expenses_cash'] ?? 0);
                $expenses_bank = floatval($_POST['expenses_bank'] ?? 0);
                $cash_received = floatval($_POST['cash_received'] ?? 0);
                $cash_paid = floatval($_POST['cash_paid'] ?? 0);
                $bank_deposits = floatval($_POST['bank_deposits'] ?? 0);
                $bank_withdrawals = floatval($_POST['bank_withdrawals'] ?? 0);
                
                // Calculate closing balances
                $closing_cash = $opening_cash + $cash_sales + $cash_received - $cash_purchases - $expenses_cash - $cash_paid;
                $closing_bank = $opening_bank + $credit_sales + $bank_deposits - $credit_purchases - $expenses_bank - $bank_withdrawals;
                
                // Check if record exists
                $checkStmt = $pdo->prepare("SELECT id FROM daywise_amounts WHERE amount_date = :amount_date");
                $checkStmt->execute([':amount_date' => $amount_date]);
                $existing = $checkStmt->fetch();
                
                if ($existing && $_POST['action'] === 'add') {
                    $message = "Record for this date already exists!";
                    $messageType = "danger";
                } else {
                    if ($existing) {
                        // Update existing
                        $updateStmt = $pdo->prepare("
                            UPDATE daywise_amounts 
                            SET opening_cash = :opening_cash,
                                opening_bank = :opening_bank,
                                cash_sales = :cash_sales,
                                credit_sales = :credit_sales,
                                cash_purchases = :cash_purchases,
                                credit_purchases = :credit_purchases,
                                expenses_cash = :expenses_cash,
                                expenses_bank = :expenses_bank,
                                cash_received = :cash_received,
                                cash_paid = :cash_paid,
                                bank_deposits = :bank_deposits,
                                bank_withdrawals = :bank_withdrawals,
                                closing_cash = :closing_cash,
                                closing_bank = :closing_bank,
                                updated_at = NOW(),
                                updated_by = :updated_by
                            WHERE id = :id
                        ");
                        $updateStmt->execute([
                            ':opening_cash' => $opening_cash,
                            ':opening_bank' => $opening_bank,
                            ':cash_sales' => $cash_sales,
                            ':credit_sales' => $credit_sales,
                            ':cash_purchases' => $cash_purchases,
                            ':credit_purchases' => $credit_purchases,
                            ':expenses_cash' => $expenses_cash,
                            ':expenses_bank' => $expenses_bank,
                            ':cash_received' => $cash_received,
                            ':cash_paid' => $cash_paid,
                            ':bank_deposits' => $bank_deposits,
                            ':bank_withdrawals' => $bank_withdrawals,
                            ':closing_cash' => $closing_cash,
                            ':closing_bank' => $closing_bank,
                            ':updated_by' => $_SESSION['user_id'] ?? null,
                            ':id' => $existing['id']
                        ]);
                        $message = "Daywise amounts updated successfully!";
                        $messageType = "success";
                    } else {
                        // Insert new
                        $insertStmt = $pdo->prepare("
                            INSERT INTO daywise_amounts (
                                amount_date, opening_cash, opening_bank, cash_sales, credit_sales,
                                cash_purchases, credit_purchases, expenses_cash, expenses_bank,
                                cash_received, cash_paid, bank_deposits, bank_withdrawals,
                                closing_cash, closing_bank, created_by, created_at
                            ) VALUES (
                                :amount_date, :opening_cash, :opening_bank, :cash_sales, :credit_sales,
                                :cash_purchases, :credit_purchases, :expenses_cash, :expenses_bank,
                                :cash_received, :cash_paid, :bank_deposits, :bank_withdrawals,
                                :closing_cash, :closing_bank, :created_by, NOW()
                            )
                        ");
                        $insertStmt->execute([
                            ':amount_date' => $amount_date,
                            ':opening_cash' => $opening_cash,
                            ':opening_bank' => $opening_bank,
                            ':cash_sales' => $cash_sales,
                            ':credit_sales' => $credit_sales,
                            ':cash_purchases' => $cash_purchases,
                            ':credit_purchases' => $credit_purchases,
                            ':expenses_cash' => $expenses_cash,
                            ':expenses_bank' => $expenses_bank,
                            ':cash_received' => $cash_received,
                            ':cash_paid' => $cash_paid,
                            ':bank_deposits' => $bank_deposits,
                            ':bank_withdrawals' => $bank_withdrawals,
                            ':closing_cash' => $closing_cash,
                            ':closing_bank' => $closing_bank,
                            ':created_by' => $_SESSION['user_id'] ?? null
                        ]);
                        $message = "Daywise amounts added successfully!";
                        $messageType = "success";
                    }
                    
                    // Log activity
                    $logStmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                        VALUES (:user_id, 3, :description, :activity_data, :created_by)
                    ");
                    $logStmt->execute([
                        ':user_id' => $_SESSION['user_id'] ?? null,
                        ':description' => "Daywise amounts " . ($existing ? 'updated' : 'added') . " for date: " . $amount_date,
                        ':activity_data' => json_encode([
                            'amount_date' => $amount_date,
                            'closing_cash' => $closing_cash,
                            'closing_bank' => $closing_bank
                        ]),
                        ':created_by' => $_SESSION['user_id'] ?? null
                    ]);
                    
                    $pdo->commit();
                    
                    // Redirect to refresh page
                    header("Location: daywise-amounts.php?filter_date_from=" . urlencode($filter_date_from) . "&filter_date_to=" . urlencode($filter_date_to) . "&view_mode=" . $view_mode . "&message=" . urlencode($message) . "&message_type=success");
                    exit();
                }
            } elseif ($_POST['action'] === 'reconcile' && isset($_POST['record_id'])) {
                // Check if reconciled column exists, if not add it
                try {
                    $pdo->query("SELECT reconciled FROM daywise_amounts LIMIT 1");
                } catch (Exception $e) {
                    // Column doesn't exist, add it
                    $pdo->exec("ALTER TABLE daywise_amounts ADD COLUMN reconciled TINYINT(1) DEFAULT 0 AFTER closing_bank");
                    $pdo->exec("ALTER TABLE daywise_amounts ADD COLUMN reconciled_at DATETIME DEFAULT NULL AFTER reconciled");
                    $pdo->exec("ALTER TABLE daywise_amounts ADD COLUMN reconciled_by INT(11) DEFAULT NULL AFTER reconciled_at");
                }
                
                // Update record
                $updateStmt = $pdo->prepare("
                    UPDATE daywise_amounts 
                    SET reconciled = 1,
                        reconciled_at = NOW(),
                        reconciled_by = :reconciled_by
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':reconciled_by' => $_SESSION['user_id'] ?? null,
                    ':id' => $_POST['record_id']
                ]);
                
                $message = "Record reconciled successfully!";
                $messageType = "success";
                
                $pdo->commit();
                
                // Redirect to refresh page
                header("Location: daywise-amounts.php?filter_date_from=" . urlencode($filter_date_from) . "&filter_date_to=" . urlencode($filter_date_to) . "&view_mode=" . $view_mode . "&message=" . urlencode($message) . "&message_type=success");
                exit();
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            error_log("Daywise amounts operation error: " . $e->getMessage());
        }
    }
}

// Get daywise amounts data
try {
    // Check if reconciled column exists
    $reconciled_column_exists = false;
    try {
        $pdo->query("SELECT reconciled FROM daywise_amounts LIMIT 1");
        $reconciled_column_exists = true;
    } catch (Exception $e) {
        // Column doesn't exist
    }
    
    // Base query
    $query = "
        SELECT 
            da.*
    ";
    
    if ($reconciled_column_exists) {
        $query .= ", u3.full_name as reconciled_by_name";
    } else {
        $query .= ", NULL as reconciled, NULL as reconciled_at, NULL as reconciled_by, NULL as reconciled_by_name";
    }
    
    $query .= "
        FROM daywise_amounts da
        LEFT JOIN users u1 ON da.created_by = u1.id
        LEFT JOIN users u2 ON da.updated_by = u2.id
    ";
    
    if ($reconciled_column_exists) {
        $query .= " LEFT JOIN users u3 ON da.reconciled_by = u3.id";
    }
    
    $query .= " WHERE 1=1";
    
    $count_query = "SELECT COUNT(*) as total FROM daywise_amounts WHERE 1=1";
    $params = [];
    
    // Apply date filters
    if (!empty($filter_date_from)) {
        $query .= " AND da.amount_date >= :date_from";
        $count_query .= " AND amount_date >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND da.amount_date <= :date_to";
        $count_query .= " AND amount_date <= :date_to";
        $params[':date_to'] = $filter_date_to;
    }
    
    // Get total records for pagination
    $countStmt = $pdo->prepare($count_query);
    $countStmt->execute($params);
    $total_records = $countStmt->fetch()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    
    // Add ordering and pagination
    $query .= " ORDER BY da.amount_date DESC LIMIT :offset, :limit";
    
    $stmt = $pdo->prepare($query);
    
    // Bind parameters for pagination
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    
    $stmt->execute();
    $daywise_amounts = $stmt->fetchAll();
    
    // Get summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_days,
            SUM(closing_cash) as total_cash,
            SUM(closing_bank) as total_bank,
            AVG(closing_cash + closing_bank) as avg_daily_balance,
            SUM(cash_sales + credit_sales) as total_sales,
            SUM(cash_purchases + credit_purchases) as total_purchases,
            SUM(expenses_cash + expenses_bank) as total_expenses,
            SUM(cash_received) as total_cash_received,
            SUM(cash_paid) as total_cash_paid,
            SUM(bank_deposits) as total_bank_deposits,
            SUM(bank_withdrawals) as total_bank_withdrawals,
            MAX(amount_date) as last_entry_date
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
    ";
    
    $summaryStmt = $pdo->prepare($summary_query);
    $summaryStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $summary = $summaryStmt->fetch();
    
    // Get running totals for chart
    $chart_query = "
        SELECT 
            amount_date,
            closing_cash,
            closing_bank,
            (closing_cash + closing_bank) as total_balance
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
        ORDER BY amount_date ASC
    ";
    $chartStmt = $pdo->prepare($chart_query);
    $chartStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $chart_data = $chartStmt->fetchAll();
    
    // Get missing dates for warning
    $date_range_query = "
        SELECT COUNT(*) as missing_days
        FROM (
            SELECT DATE_ADD(:date_from, INTERVAL seq DAY) as date
            FROM (
                SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                UNION SELECT 30
            ) as numbers
            WHERE DATE_ADD(:date_from, INTERVAL seq DAY) <= :date_to
        ) as all_dates
        LEFT JOIN daywise_amounts da ON all_dates.date = da.amount_date
        WHERE da.id IS NULL
    ";
    $missingStmt = $pdo->prepare($date_range_query);
    $missingStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $missing_count = $missingStmt->fetch()['missing_days'];
    
} catch (Exception $e) {
    error_log("Daywise amounts fetch error: " . $e->getMessage());
    $daywise_amounts = [];
    $summary = [
        'total_days' => 0,
        'total_cash' => 0,
        'total_bank' => 0,
        'avg_daily_balance' => 0,
        'total_sales' => 0,
        'total_purchases' => 0,
        'total_expenses' => 0,
        'total_cash_received' => 0,
        'total_cash_paid' => 0,
        'total_bank_deposits' => 0,
        'total_bank_withdrawals' => 0,
        'last_entry_date' => null
    ];
    $chart_data = [];
    $missing_count = 0;
}

// Check for message from redirect
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['message_type'] ?? 'info';
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
                            <h4 class="mb-0 font-size-18">Day-wise Amounts</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Accounts</a></li>
                                    <li class="breadcrumb-item active">Day-wise Amounts</li>
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

                <!-- Missing Days Warning -->
                <?php if ($missing_count > 0): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert me-2"></i>
                            <strong>Warning!</strong> <?= $missing_count ?> day(s) missing in the selected date range. 
                            <a href="#" data-bs-toggle="modal" data-bs-target="#addModal" class="alert-link">Click here to add missing entries</a>.
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
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                        <i class="mdi mdi-plus"></i> Add Daily Entry
                                    </button>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                    <button type="button" class="btn btn-warning" id="refreshChartBtn">
                                        <i class="mdi mdi-chart-line"></i> Refresh Chart
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
                                                <i class="mdi mdi-calendar font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Days Covered</p>
                                        <h4><?= number_format($summary['total_days'] ?? 0) ?></h4>
                                        <small class="text-muted">Last: <?= $summary['last_entry_date'] ? date('d M Y', strtotime($summary['last_entry_date'])) : 'N/A' ?></small>
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
                                                <i class="mdi mdi-cash-multiple font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Cash Balance</p>
                                        <h4>₹<?= number_format($summary['total_cash'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Closing cash</small>
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
                                        <p class="text-muted mb-2">Total Bank Balance</p>
                                        <h4>₹<?= number_format($summary['total_bank'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Closing bank</small>
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
                                        <p class="text-muted mb-2">Avg Daily Balance</p>
                                        <h4>₹<?= number_format($summary['avg_daily_balance'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Cash + Bank</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Records</h4>
                                <form method="GET" action="daywise-amounts.php" class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="view_mode" class="form-label">View Mode</label>
                                            <select class="form-control" id="view_mode" name="view_mode">
                                                <option value="daily" <?= $view_mode == 'daily' ? 'selected' : '' ?>>Daily View</option>
                                                <option value="weekly" <?= $view_mode == 'weekly' ? 'selected' : '' ?>>Weekly Summary</option>
                                                <option value="monthly" <?= $view_mode == 'monthly' ? 'selected' : '' ?>>Monthly Summary</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="daywise-amounts.php" class="btn btn-secondary">
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

                <!-- Balance Chart -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Daily Balance Trend</h4>
                                <div id="balance-chart" class="apex-charts" dir="ltr"></div>
                                <div id="chart-no-data" class="text-center text-muted py-4" style="display: none;">
                                    <i class="mdi mdi-chart-line font-size-48"></i>
                                    <p class="mt-2">No chart data available for selected date range</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction Summary Cards -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-transparent border-bottom">
                                <h5 class="card-title mb-0">Sales & Purchases</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cash Sales</p>
                                        <h5 class="text-success">₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Credit Sales</p>
                                        <h5 class="text-info">₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h5>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cash Purchases</p>
                                        <h5 class="text-danger">₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Credit Purchases</p>
                                        <h5 class="text-warning">₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-transparent border-bottom">
                                <h5 class="card-title mb-0">Cash Movements</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cash Received</p>
                                        <h5 class="text-success">₹<?= number_format($summary['total_cash_received'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cash Paid</p>
                                        <h5 class="text-danger">₹<?= number_format($summary['total_cash_paid'] ?? 0, 2) ?></h5>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Cash Expenses</p>
                                        <h5 class="text-danger">₹<?= number_format($summary['total_expenses'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Net Cash Flow</p>
                                        <h5 class="<?= (($summary['total_cash_received'] ?? 0) - ($summary['total_cash_paid'] ?? 0) - ($summary['total_expenses'] ?? 0)) >= 0 ? 'text-success' : 'text-danger' ?>">
                                            ₹<?= number_format(($summary['total_cash_received'] ?? 0) - ($summary['total_cash_paid'] ?? 0) - ($summary['total_expenses'] ?? 0), 2) ?>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-transparent border-bottom">
                                <h5 class="card-title mb-0">Bank Transactions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Deposits</p>
                                        <h5 class="text-success">₹<?= number_format($summary['total_bank_deposits'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Withdrawals</p>
                                        <h5 class="text-danger">₹<?= number_format($summary['total_bank_withdrawals'] ?? 0, 2) ?></h5>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Bank Expenses</p>
                                        <h5 class="text-danger">₹<?= number_format($summary['total_expenses'] ?? 0, 2) ?></h5>
                                    </div>
                                    <div class="col-6">
                                        <p class="text-muted mb-1">Net Bank Flow</p>
                                        <h5 class="<?= (($summary['total_bank_deposits'] ?? 0) - ($summary['total_bank_withdrawals'] ?? 0)) >= 0 ? 'text-success' : 'text-danger' ?>">
                                            ₹<?= number_format(($summary['total_bank_deposits'] ?? 0) - ($summary['total_bank_withdrawals'] ?? 0), 2) ?>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daywise Amounts Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Daily Records</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <form method="GET" action="daywise-amounts.php" class="d-inline-block">
                                                <select name="per_page" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                    <option value="15" <?= $records_per_page == 15 ? 'selected' : '' ?>>15 per page</option>
                                                    <option value="31" <?= $records_per_page == 31 ? 'selected' : '' ?>>31 per page</option>
                                                    <option value="62" <?= $records_per_page == 62 ? 'selected' : '' ?>>62 per page</option>
                                                    <option value="93" <?= $records_per_page == 93 ? 'selected' : '' ?>>93 per page</option>
                                                </select>
                                                <?php foreach (['filter_date_from', 'filter_date_to', 'view_mode'] as $param): ?>
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
                                                <th>Opening</th>
                                                <th colspan="2">Sales</th>
                                                <th colspan="2">Purchases</th>
                                                <th>Expenses</th>
                                                <th>Cash Movement</th>
                                                <th>Bank Movement</th>
                                                <th>Closing Balance</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                            <tr>
                                                <th></th>
                                                <th><small>Cash/Bank</small></th>
                                                <th><small>Cash</small></th>
                                                <th><small>Credit</small></th>
                                                <th><small>Cash</small></th>
                                                <th><small>Credit</small></th>
                                                <th><small>Cash/Bank</small></th>
                                                <th><small>In/Out</small></th>
                                                <th><small>Dep/WDL</small></th>
                                                <th><small>Cash/Bank</small></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($daywise_amounts)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No records found for selected date range</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($daywise_amounts as $record): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= date('d M Y', strtotime($record['amount_date'])) ?></strong>
                                                    </td>
                                                    <td>
                                                        <small class="d-block">₹<?= number_format($record['opening_cash'], 2) ?></small>
                                                        <small class="d-block text-muted">₹<?= number_format($record['opening_bank'], 2) ?></small>
                                                    </td>
                                                    <td class="text-success">₹<?= number_format($record['cash_sales'], 2) ?></td>
                                                    <td class="text-info">₹<?= number_format($record['credit_sales'], 2) ?></td>
                                                    <td class="text-danger">₹<?= number_format($record['cash_purchases'], 2) ?></td>
                                                    <td class="text-warning">₹<?= number_format($record['credit_purchases'], 2) ?></td>
                                                    <td>
                                                        <small class="d-block text-danger">₹<?= number_format($record['expenses_cash'], 2) ?></small>
                                                        <small class="d-block text-danger">₹<?= number_format($record['expenses_bank'], 2) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($record['cash_received'] > 0): ?>
                                                            <small class="d-block text-success">+₹<?= number_format($record['cash_received'], 2) ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($record['cash_paid'] > 0): ?>
                                                            <small class="d-block text-danger">-₹<?= number_format($record['cash_paid'], 2) ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($record['cash_received'] == 0 && $record['cash_paid'] == 0): ?>
                                                            <small class="d-block text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($record['bank_deposits'] > 0): ?>
                                                            <small class="d-block text-success">+₹<?= number_format($record['bank_deposits'], 2) ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($record['bank_withdrawals'] > 0): ?>
                                                            <small class="d-block text-danger">-₹<?= number_format($record['bank_withdrawals'], 2) ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($record['bank_deposits'] == 0 && $record['bank_withdrawals'] == 0): ?>
                                                            <small class="d-block text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong class="d-block">₹<?= number_format($record['closing_cash'], 2) ?></strong>
                                                        <strong class="d-block text-primary">₹<?= number_format($record['closing_bank'], 2) ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($record['reconciled']) && $record['reconciled']): ?>
                                                            <span class="badge bg-soft-success text-success" title="Reconciled by <?= htmlspecialchars($record['reconciled_by_name'] ?? 'Unknown') ?> on <?= isset($record['reconciled_at']) ? date('d M Y', strtotime($record['reconciled_at'])) : '' ?>">
                                                                <i class="mdi mdi-check-circle"></i> Reconciled
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-warning text-warning">
                                                                <i class="mdi mdi-alert"></i> Pending
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button class="btn btn-sm btn-soft-primary" onclick='editRecord(<?= json_encode($record) ?>)'>
                                                                <i class="mdi mdi-pencil"></i>
                                                            </button>
                                                            <?php if (!isset($record['reconciled']) || !$record['reconciled']): ?>
                                                            <button class="btn btn-sm btn-soft-success" onclick="reconcileRecord(<?= $record['id'] ?>)">
                                                                <i class="mdi mdi-check"></i>
                                                            </button>
                                                            <?php endif; ?>
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Add Daily Amounts</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="daywise-amounts.php" id="amountForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="form_action" value="add">
                    <input type="hidden" name="record_id" id="record_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="amount_date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="amount_date" name="amount_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Day</label>
                                <input type="text" class="form-control" id="day_name" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Opening Balances</h5>
                            <div class="mb-3">
                                <label for="opening_cash" class="form-label">Opening Cash</label>
                                <input type="number" step="0.01" class="form-control" id="opening_cash" name="opening_cash" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="opening_bank" class="form-label">Opening Bank</label>
                                <input type="number" step="0.01" class="form-control" id="opening_bank" name="opening_bank" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Sales</h5>
                            <div class="mb-3">
                                <label for="cash_sales" class="form-label">Cash Sales</label>
                                <input type="number" step="0.01" class="form-control" id="cash_sales" name="cash_sales" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="credit_sales" class="form-label">Credit Sales</label>
                                <input type="number" step="0.01" class="form-control" id="credit_sales" name="credit_sales" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Purchases</h5>
                            <div class="mb-3">
                                <label for="cash_purchases" class="form-label">Cash Purchases</label>
                                <input type="number" step="0.01" class="form-control" id="cash_purchases" name="cash_purchases" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="credit_purchases" class="form-label">Credit Purchases</label>
                                <input type="number" step="0.01" class="form-control" id="credit_purchases" name="credit_purchases" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Expenses</h5>
                            <div class="mb-3">
                                <label for="expenses_cash" class="form-label">Cash Expenses</label>
                                <input type="number" step="0.01" class="form-control" id="expenses_cash" name="expenses_cash" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="expenses_bank" class="form-label">Bank Expenses</label>
                                <input type="number" step="0.01" class="form-control" id="expenses_bank" name="expenses_bank" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Cash Movements</h5>
                            <div class="mb-3">
                                <label for="cash_received" class="form-label">Cash Received</label>
                                <input type="number" step="0.01" class="form-control" id="cash_received" name="cash_received" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="cash_paid" class="form-label">Cash Paid</label>
                                <input type="number" step="0.01" class="form-control" id="cash_paid" name="cash_paid" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="font-size-14 mb-3">Bank Movements</h5>
                            <div class="mb-3">
                                <label for="bank_deposits" class="form-label">Bank Deposits</label>
                                <input type="number" step="0.01" class="form-control" id="bank_deposits" name="bank_deposits" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="bank_withdrawals" class="form-label">Bank Withdrawals</label>
                                <input type="number" step="0.01" class="form-control" id="bank_withdrawals" name="bank_withdrawals" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="card bg-soft-primary">
                                <div class="card-body">
                                    <h5 class="font-size-14">Closing Cash</h5>
                                    <h3 id="preview_closing_cash">₹0.00</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-soft-info">
                                <div class="card-body">
                                    <h5 class="font-size-14">Closing Bank</h5>
                                    <h3 id="preview_closing_bank">₹0.00</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
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

    // Chart initialization
    var chart;
    var chartData = <?= json_encode($chart_data) ?>;

    function renderBalanceChart() {
        if (chartData.length === 0) {
            document.getElementById('balance-chart').style.display = 'none';
            document.getElementById('chart-no-data').style.display = 'block';
            return;
        }
        
        document.getElementById('balance-chart').style.display = 'block';
        document.getElementById('chart-no-data').style.display = 'none';
        
        var dates = chartData.map(item => {
            var date = new Date(item.amount_date + 'T12:00:00');
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        var cashBalances = chartData.map(item => parseFloat(item.closing_cash));
        var bankBalances = chartData.map(item => parseFloat(item.closing_bank));
        var totalBalances = chartData.map(item => parseFloat(item.total_balance));

        var options = {
            chart: {
                height: 350,
                type: 'line',
                toolbar: {
                    show: true,
                    tools: {
                        download: true,
                        selection: true,
                        zoom: true,
                        zoomin: true,
                        zoomout: true,
                        pan: true,
                        reset: true
                    }
                },
                animations: {
                    enabled: true
                }
            },
            series: [
                {
                    name: 'Cash Balance',
                    type: 'column',
                    data: cashBalances
                },
                {
                    name: 'Bank Balance',
                    type: 'column',
                    data: bankBalances
                },
                {
                    name: 'Total Balance',
                    type: 'line',
                    data: totalBalances
                }
            ],
            stroke: {
                width: [0, 0, 3],
                curve: 'smooth'
            },
            plotOptions: {
                bar: {
                    columnWidth: '50%',
                    endingShape: 'rounded'
                }
            },
            fill: {
                opacity: [1, 1, 1]
            },
            labels: dates,
            colors: ['#34c38f', '#556ee6', '#f46a6a'],
            xaxis: {
                type: 'category',
                tickAmount: 10,
                title: {
                    text: 'Date'
                },
                labels: {
                    rotate: -45,
                    rotateAlways: false,
                    hideOverlappingLabels: true
                }
            },
            yaxis: {
                title: {
                    text: 'Amount (₹)'
                },
                labels: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(0);
                    }
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            },
            legend: {
                position: 'top'
            },
            dataLabels: {
                enabled: false
            }
        };

        if (chart) {
            chart.destroy();
        }

        chart = new ApexCharts(document.querySelector("#balance-chart"), options);
        chart.render();
    }

    // Initial render
    renderBalanceChart();

    // Refresh chart with AJAX
    document.getElementById('refreshChartBtn').addEventListener('click', function() {
        var dateFrom = document.getElementById('filter_date_from').value;
        var dateTo = document.getElementById('filter_date_to').value;
        
        // Show loading state
        this.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Loading...';
        this.disabled = true;
        
        fetch('daywise-amounts.php?ajax=chart_data&filter_date_from=' + encodeURIComponent(dateFrom) + '&filter_date_to=' + encodeURIComponent(dateTo))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    chartData = data.data;
                    renderBalanceChart();
                } else {
                    alert('Error refreshing chart: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while refreshing the chart');
            })
            .finally(() => {
                // Reset button state
                this.innerHTML = '<i class="mdi mdi-chart-line"></i> Refresh Chart';
                this.disabled = false;
            });
    });

    // Edit record function
    function editRecord(record) {
        document.getElementById('form_action').value = 'update';
        document.getElementById('record_id').value = record.id;
        document.getElementById('amount_date').value = record.amount_date;
        document.getElementById('opening_cash').value = record.opening_cash;
        document.getElementById('opening_bank').value = record.opening_bank;
        document.getElementById('cash_sales').value = record.cash_sales;
        document.getElementById('credit_sales').value = record.credit_sales;
        document.getElementById('cash_purchases').value = record.cash_purchases;
        document.getElementById('credit_purchases').value = record.credit_purchases;
        document.getElementById('expenses_cash').value = record.expenses_cash;
        document.getElementById('expenses_bank').value = record.expenses_bank;
        document.getElementById('cash_received').value = record.cash_received;
        document.getElementById('cash_paid').value = record.cash_paid;
        document.getElementById('bank_deposits').value = record.bank_deposits;
        document.getElementById('bank_withdrawals').value = record.bank_withdrawals;
        
        updateDayName();
        calculateClosing();
        
        document.getElementById('addModalLabel').textContent = 'Edit Daily Amounts';
        var modal = new bootstrap.Modal(document.getElementById('addModal'));
        modal.show();
    }

    // Reconcile record
    function reconcileRecord(id) {
        if (confirm('Mark this record as reconciled? This action cannot be undone.')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="reconcile"><input type="hidden" name="record_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Update day name when date changes
    document.getElementById('amount_date').addEventListener('change', updateDayName);
    
    function updateDayName() {
        var dateStr = document.getElementById('amount_date').value;
        if (dateStr) {
            var date = new Date(dateStr + 'T12:00:00');
            var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var dayName = days[date.getDay()];
            document.getElementById('day_name').value = dayName;
        }
    }

    // Calculate closing balances preview
    var inputs = ['opening_cash', 'opening_bank', 'cash_sales', 'credit_sales', 'cash_purchases', 
                  'credit_purchases', 'expenses_cash', 'expenses_bank', 'cash_received', 'cash_paid',
                  'bank_deposits', 'bank_withdrawals'];
    
    inputs.forEach(id => {
        var element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', calculateClosing);
        }
    });

    function calculateClosing() {
        var openingCash = parseFloat(document.getElementById('opening_cash').value) || 0;
        var cashSales = parseFloat(document.getElementById('cash_sales').value) || 0;
        var cashPurchases = parseFloat(document.getElementById('cash_purchases').value) || 0;
        var expensesCash = parseFloat(document.getElementById('expenses_cash').value) || 0;
        var cashReceived = parseFloat(document.getElementById('cash_received').value) || 0;
        var cashPaid = parseFloat(document.getElementById('cash_paid').value) || 0;
        
        var closingCash = openingCash + cashSales + cashReceived - cashPurchases - expensesCash - cashPaid;
        document.getElementById('preview_closing_cash').textContent = '₹' + closingCash.toFixed(2);

        var openingBank = parseFloat(document.getElementById('opening_bank').value) || 0;
        var creditSales = parseFloat(document.getElementById('credit_sales').value) || 0;
        var creditPurchases = parseFloat(document.getElementById('credit_purchases').value) || 0;
        var expensesBank = parseFloat(document.getElementById('expenses_bank').value) || 0;
        var bankDeposits = parseFloat(document.getElementById('bank_deposits').value) || 0;
        var bankWithdrawals = parseFloat(document.getElementById('bank_withdrawals').value) || 0;
        
        var closingBank = openingBank + creditSales + bankDeposits - creditPurchases - expensesBank - bankWithdrawals;
        document.getElementById('preview_closing_bank').textContent = '₹' + closingBank.toFixed(2);
    }

    // Reset modal on open
    document.getElementById('addModal').addEventListener('show.bs.modal', function() {
        if (!document.getElementById('record_id').value) {
            document.getElementById('form_action').value = 'add';
            document.getElementById('amount_date').value = new Date().toISOString().split('T')[0];
            updateDayName();
            
            // Set default values
            inputs.forEach(id => {
                var element = document.getElementById(id);
                if (element) {
                    element.value = '0';
                }
            });
            
            calculateClosing();
            document.getElementById('addModalLabel').textContent = 'Add Daily Amounts';
        }
    });

    // Clear record_id when modal is hidden
    document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('record_id').value = '';
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

    // Initialize day name on page load
    updateDayName();
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page, $params) {
    $params['page'] = $page;
    return 'daywise-amounts.php?' . http_build_query($params);
}
?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    #refreshChartBtn, form, .apex-charts {
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
}

.table td {
    vertical-align: middle;
}

.table thead tr:first-child th {
    border-bottom: none;
}

.table thead tr:last-child th {
    font-size: 0.7rem;
    color: #6c757d;
    padding-top: 0;
}

.card.bg-soft-primary, .card.bg-soft-info {
    transition: all 0.3s;
}

.card.bg-soft-primary:hover, .card.bg-soft-info:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.mdi-spin {
    animation: mdi-spin 2s infinite linear;
}

@keyframes mdi-spin {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(359deg);
    }
}
</style>

</body>
</html>