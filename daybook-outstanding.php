<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Handle AJAX request for getting entry details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $entry_id = intval($_GET['id']);
        
        // Get entry details
        $stmt = $pdo->prepare("
            SELECT d.*, u.full_name as created_by_name
            FROM daybook d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$entry_id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            echo json_encode(['success' => false, 'message' => 'Entry not found']);
            exit();
        }
        
        // Get items
        $itemStmt = $pdo->prepare("
            SELECT di.*, p.name as product_name
            FROM daybook_items di
            LEFT JOIN products p ON di.product_id = p.id
            WHERE di.daybook_id = ?
            ORDER BY di.id ASC
        ");
        $itemStmt->execute([$entry_id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $entry['items'] = $items;
        
        echo json_encode(['success' => true, 'data' => $entry]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for payment update
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_payment') {
    header('Content-Type: application/json');
    
    try {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        if ($entry_id <= 0 || $payment_amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment details']);
            exit();
        }
        
        $pdo->beginTransaction();
        
        // Get current entry details
        $stmt = $pdo->prepare("SELECT * FROM daybook WHERE id = ?");
        $stmt->execute([$entry_id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            echo json_encode(['success' => false, 'message' => 'Entry not found']);
            exit();
        }
        
        $new_paid_amount = ($entry['paid_amount'] ?? 0) + $payment_amount;
        $new_outstanding = $entry['grand_total'] - $new_paid_amount;
        
        // Determine new payment status
        $new_status = 'pending';
        if ($new_outstanding <= 0) {
            $new_status = 'paid';
        } elseif ($new_paid_amount > 0) {
            $new_status = 'partial';
        }
        
        // Update daybook entry
        $updateStmt = $pdo->prepare("
            UPDATE daybook 
            SET paid_amount = :paid_amount,
                payment_status = :payment_status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':paid_amount' => $new_paid_amount,
            ':payment_status' => $new_status,
            ':id' => $entry_id
        ]);
        
        // Record payment transaction (if payments table exists, otherwise skip)
        try {
            $paymentStmt = $pdo->prepare("
                INSERT INTO payments (
                    reference_type, reference_id, payment_date,
                    amount, payment_method, status, notes, created_by, created_at
                ) VALUES (
                    'daybook', :reference_id, :payment_date,
                    :amount, :payment_method, 'completed', :notes, :created_by, NOW()
                )
            ");
            $paymentStmt->execute([
                ':reference_id' => $entry_id,
                ':payment_date' => $payment_date,
                ':amount' => $payment_amount,
                ':payment_method' => $payment_method,
                ':notes' => "Payment received for daybook entry: " . $entry['invoice_number'],
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
        } catch (Exception $e) {
            // Payments table might not exist, log but continue
            error_log("Payments table error: " . $e->getMessage());
        }
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (:user_id, 4, :description, :activity_data, :created_by)
        ");
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':description' => "Payment received for daybook entry: " . $entry['invoice_number'],
            ':activity_data' => json_encode([
                'entry_id' => $entry_id,
                'invoice_number' => $entry['invoice_number'],
                'amount' => $payment_amount,
                'new_status' => $new_status
            ]),
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'new_paid_amount' => $new_paid_amount,
            'new_outstanding' => $new_outstanding,
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Get filter parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer_type = isset($_GET['customer_type']) ? $_GET['customer_type'] : 'all';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'pending';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get outstanding entries
try {
    $query = "
        SELECT 
            d.*,
            COUNT(di.id) as item_count,
            GROUP_CONCAT(DISTINCT CONCAT(di.quantity, ' ', di.unit, ' ', di.product_name) SEPARATOR ', ') as items_list
        FROM daybook d
        LEFT JOIN daybook_items di ON d.id = di.daybook_id
        WHERE d.invoice_date BETWEEN :date_from AND :date_to
        AND d.payment_status != 'paid'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if ($customer_type != 'all') {
        $query .= " AND d.customer_type = :customer_type";
        $params[':customer_type'] = $customer_type;
    }
    
    if ($payment_status != 'all') {
        $query .= " AND d.payment_status = :payment_status";
        $params[':payment_status'] = $payment_status;
    }
    
    if (!empty($search)) {
        $query .= " AND (d.customer_name LIKE :search OR d.driver_name LIKE :search OR d.invoice_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY d.id ORDER BY d.invoice_date DESC, d.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $outstanding_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $summary = [
        'total_entries' => 0,
        'total_outstanding' => 0,
        'total_due_today' => 0,
        'total_overdue' => 0,
        'total_partial' => 0,
        'by_customer_type' => [
            'constructor' => 0,
            'customer' => 0,
            'dealer' => 0
        ]
    ];
    
    $today = date('Y-m-d');
    
    foreach ($outstanding_entries as $entry) {
        $outstanding = $entry['grand_total'] - ($entry['paid_amount'] ?? 0);
        $summary['total_entries']++;
        $summary['total_outstanding'] += $outstanding;
        
        // Count by customer type
        if ($entry['customer_type'] == 'constructor') {
            $summary['by_customer_type']['constructor'] += $outstanding;
        } elseif ($entry['customer_type'] == 'customer') {
            $summary['by_customer_type']['customer'] += $outstanding;
        } elseif ($entry['customer_type'] == 'dealer') {
            $summary['by_customer_type']['dealer'] += $outstanding;
        }
        
        // Check if due today or overdue
        $due_date = $entry['invoice_date'];
        if ($due_date == $today) {
            $summary['total_due_today'] += $outstanding;
        } elseif ($due_date < $today) {
            $summary['total_overdue'] += $outstanding;
        }
        
        if ($entry['payment_status'] == 'partial') {
            $summary['total_partial'] += $outstanding;
        }
    }
    
} catch (Exception $e) {
    $outstanding_entries = [];
    $summary = [];
    error_log("Error fetching outstanding entries: " . $e->getMessage());
}

// Handle export to CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daybook_outstanding_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Daybook Outstanding Report']);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    fputcsv($output, []);
    fputcsv($output, ['Invoice #', 'Date', 'Driver', 'Location', 'Customer Type', 'Customer', 'Products', 'Total Amount', 'Paid', 'Outstanding', 'Status']);
    
    foreach ($outstanding_entries as $entry) {
        fputcsv($output, [
            $entry['invoice_number'],
            date('d-m-Y', strtotime($entry['invoice_date'])),
            $entry['driver_name'],
            $entry['location'],
            ucfirst($entry['customer_type']),
            $entry['customer_name'],
            $entry['items_list'] ?? '-',
            number_format($entry['grand_total'], 2),
            number_format($entry['paid_amount'] ?? 0, 2),
            number_format($entry['grand_total'] - ($entry['paid_amount'] ?? 0), 2),
            ucfirst($entry['payment_status'])
        ]);
    }
    
    fclose($output);
    exit();
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
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
        
        .summary-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            cursor: pointer;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background-color: #fed7aa;
            color: #92400e;
        }
        
        .status-partial {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .status-overdue {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .btn-receive-payment {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
        }
        
        .btn-receive-payment:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-view-details {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            color: white;
        }
        
        .btn-view-details:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .outstanding-amount {
            font-weight: bold;
            color: #dc3545;
        }
        
        .partial-amount {
            color: #fd7e14;
        }
        
        .paid-amount {
            color: #28a745;
        }
        
        @media print {
            .vertical-menu, .topbar, .footer, .btn, .modal, 
            .page-title-right, .card-title .btn, .action-buttons,
            .filter-section, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
        }
        
        .data-row {
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .details-table td {
            padding: 5px;
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
                            <h4 class="mb-0 font-size-18">Daybook Outstanding Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="daybook-list.php">Daybook</a></li>
                                    <li class="breadcrumb-item active">Outstanding</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-file-document font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Outstanding Entries</p>
                                        <h4><?= number_format($summary['total_entries'] ?? 0) ?></h4>
                                        <small class="text-muted">Pending collection</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="mdi mdi-currency-inr font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Outstanding Amount</p>
                                        <h4>₹<?= number_format($summary['total_outstanding'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">To be collected</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-calendar-alert font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Due Today</p>
                                        <h4>₹<?= number_format($summary['total_due_today'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Due for collection</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-clock-alert font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Overdue Amount</p>
                                        <h4>₹<?= number_format($summary['total_overdue'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Past due date</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body filter-section">
                                <h4 class="card-title mb-4">Filter Outstanding Entries</h4>
                                <form method="GET" action="daybook-outstanding.php" id="filterForm">
                                    <div class="row g-3 align-items-end">
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="date_from" class="form-label">From Date</label>
                                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="date_to" class="form-label">To Date</label>
                                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="customer_type" class="form-label">Customer Type</label>
                                                <select class="form-control" id="customer_type" name="customer_type">
                                                    <option value="all" <?= $customer_type == 'all' ? 'selected' : '' ?>>All Types</option>
                                                    <option value="constructor" <?= $customer_type == 'constructor' ? 'selected' : '' ?>>Constructor</option>
                                                    <option value="customer" <?= $customer_type == 'customer' ? 'selected' : '' ?>>Customer</option>
                                                    <option value="dealer" <?= $customer_type == 'dealer' ? 'selected' : '' ?>>Dealer</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="payment_status" class="form-label">Payment Status</label>
                                                <select class="form-control" id="payment_status" name="payment_status">
                                                    <option value="all" <?= $payment_status == 'all' ? 'selected' : '' ?>>All</option>
                                                    <option value="pending" <?= $payment_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="partial" <?= $payment_status == 'partial' ? 'selected' : '' ?>>Partial</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="search" class="form-label">Search</label>
                                                <input type="text" class="form-control" id="search" name="search" placeholder="Customer, Driver, Invoice..." value="<?= htmlspecialchars($search) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="filter-buttons">
                                                <button type="submit" class="btn btn-primary w-100 mb-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="daybook-outstanding.php" class="btn btn-secondary w-100">
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

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print Report
                                    </button>
                                    <button type="button" class="btn btn-primary" id="refreshBtn">
                                        <i class="mdi mdi-refresh"></i> Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Entries Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Outstanding Entries</h4>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0" id="outstandingTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Date</th>
                                                <th>Driver</th>
                                                <th>Location</th>
                                                <th>Customer Type</th>
                                                <th>Customer</th>
                                                <th>Products</th>
                                                <th>Total (₹)</th>
                                                <th>Paid (₹)</th>
                                                <th>Outstanding (₹)</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($outstanding_entries)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No outstanding entries found for selected filters</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($outstanding_entries as $entry): 
                                                    $outstanding = $entry['grand_total'] - ($entry['paid_amount'] ?? 0);
                                                    $status_class = '';
                                                    if ($entry['payment_status'] == 'pending') {
                                                        $status_class = 'status-pending';
                                                    } elseif ($entry['payment_status'] == 'partial') {
                                                        $status_class = 'status-partial';
                                                    }
                                                    
                                                    $due_date = $entry['invoice_date'];
                                                    $today = date('Y-m-d');
                                                    if ($due_date < $today && $outstanding > 0) {
                                                        $status_class = 'status-overdue';
                                                    }
                                                ?>
                                                <tr class="data-row" data-entry-id="<?= $entry['id'] ?>">
                                                    <td>
                                                        <strong><?= htmlspecialchars($entry['invoice_number']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">ID: <?= $entry['id'] ?></small>
                                                    </td>
                                                    <td><?= date('d-m-Y', strtotime($entry['invoice_date'])) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($entry['driver_name']) ?>
                                                        <?php if ($entry['driver_number']): ?>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($entry['driver_number']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($entry['location']) ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-info">
                                                            <?= ucfirst(htmlspecialchars($entry['customer_type'])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($entry['customer_name']) ?>
                                                        <?php if ($entry['customer_mobile']): ?>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($entry['customer_mobile']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars(substr($entry['items_list'] ?? '-', 0, 50)) ?></small>
                                                        <?php if (strlen($entry['items_list'] ?? '') > 50): ?>
                                                            <br>
                                                            <small class="text-muted">...</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong>₹<?= number_format($entry['grand_total'], 2) ?></strong>
                                                    </td>
                                                    <td class="text-end paid-amount">
                                                        ₹<?= number_format($entry['paid_amount'] ?? 0, 2) ?>
                                                    </td>
                                                    <td class="text-end outstanding-amount">
                                                        <strong>₹<?= number_format($outstanding, 2) ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?= $status_class ?>">
                                                            <?= ucfirst($entry['payment_status']) ?>
                                                            <?php if ($due_date < $today && $outstanding > 0): ?>
                                                                <br>
                                                                <small>Overdue</small>
                                                            <?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-receive-payment mb-1" onclick="receivePayment(<?= $entry['id'] ?>, '<?= htmlspecialchars(addslashes($entry['invoice_number'])) ?>', <?= $outstanding ?>)">
                                                            <i class="mdi mdi-cash"></i> Receive Payment
                                                        </button>
                                                        <button class="btn btn-sm btn-view-details" onclick="viewDetails(<?= $entry['id'] ?>)">
                                                            <i class="mdi mdi-eye"></i> Details
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Type Summary -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Outstanding by Customer Type</h4>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-domain font-size-36 text-primary"></i>
                                            <h5 class="mt-2">Constructor/Builder</h5>
                                            <h4 class="text-danger">₹<?= number_format($summary['by_customer_type']['constructor'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Total outstanding</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-account-group font-size-36 text-success"></i>
                                            <h5 class="mt-2">Regular Customers</h5>
                                            <h4 class="text-danger">₹<?= number_format($summary['by_customer_type']['customer'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Total outstanding</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-truck-delivery font-size-36 text-info"></i>
                                            <h5 class="mt-2">Dealers</h5>
                                            <h4 class="text-danger">₹<?= number_format($summary['by_customer_type']['dealer'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Total outstanding</small>
                                        </div>
                                    </div>
                                </div>
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

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receive Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
                    <input type="hidden" id="payment_entry_id" name="entry_id">
                    <div class="mb-3">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" class="form-control" id="payment_invoice_number" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Outstanding Amount (₹)</label>
                        <input type="text" class="form-control" id="payment_outstanding" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount (₹) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="payment_amount" name="payment_amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select class="form-control" id="payment_method" name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online Payment</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitPayment()">
                    <i class="mdi mdi-cash"></i> Process Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Entry Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="mdi mdi-printer"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
    // Initialize DataTable
    $(document).ready(function() {
        $('#outstandingTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    previous: "<",
                    next: ">"
                }
            },
            columnDefs: [
                { orderable: false, targets: [6, 11] }
            ]
        });
    });

    // Receive payment function
    function receivePayment(entryId, invoiceNumber, outstanding) {
        $('#payment_entry_id').val(entryId);
        $('#payment_invoice_number').val(invoiceNumber);
        $('#payment_outstanding').val('₹' + outstanding.toFixed(2));
        $('#payment_amount').val(outstanding);
        $('#payment_amount').attr('max', outstanding);
        $('#paymentModal').modal('show');
    }

    // Submit payment
    function submitPayment() {
        const amount = parseFloat($('#payment_amount').val());
        const outstanding = parseFloat($('#payment_outstanding').val().replace('₹', ''));
        
        if (!amount || amount <= 0) {
            Swal.fire('Error', 'Please enter a valid payment amount', 'error');
            return;
        }
        
        if (amount > outstanding) {
            Swal.fire('Error', 'Payment amount cannot exceed outstanding amount', 'error');
            return;
        }
        
        const formData = {
            ajax: 'update_payment',
            entry_id: $('#payment_entry_id').val(),
            payment_amount: amount,
            payment_date: $('#payment_date').val(),
            payment_method: $('#payment_method').val()
        };
        
        Swal.fire({
            title: 'Processing Payment',
            text: 'Please wait...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        $.ajax({
            url: 'daybook-outstanding.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Received!',
                        html: `Payment of ₹${amount.toFixed(2)} recorded successfully.<br>
                               Outstanding amount: ₹${response.new_outstanding.toFixed(2)}`,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        $('#paymentModal').modal('hide');
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Failed to process payment', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'An error occurred while processing payment', 'error');
            }
        });
    }

    // View entry details
    function viewDetails(entryId) {
        $('#detailsModal').modal('show');
        $('#detailsContent').html(`
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading details...</p>
            </div>
        `);
        
        // Fetch entry details via AJAX
        $.ajax({
            url: 'daybook-outstanding.php',
            type: 'GET',
            data: { ajax: 'get_details', id: entryId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayEntryDetails(response.data);
                } else {
                    $('#detailsContent').html(`
                        <div class="text-center py-4 text-danger">
                            <i class="mdi mdi-alert-circle font-size-48"></i>
                            <p class="mt-2">Failed to load details: ${response.message || 'Unknown error'}</p>
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                $('#detailsContent').html(`
                    <div class="text-center py-4 text-danger">
                        <i class="mdi mdi-alert-circle font-size-48"></i>
                        <p class="mt-2">Error loading details. Please try again.</p>
                        <p class="small text-muted">Status: ${status}</p>
                    </div>
                `);
            }
        });
    }

    // Display entry details
    function displayEntryDetails(entry) {
        const outstanding = entry.grand_total - (entry.paid_amount || 0);
        
        let itemsHtml = '';
        if (entry.items && entry.items.length > 0) {
            itemsHtml = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th></tr></thead><tbody>';
            entry.items.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td>${escapeHtml(item.product_name)}</td>
                        <td>${escapeHtml(item.unit)}</td>
                        <td class="text-end">${parseFloat(item.quantity).toFixed(2)}</td>
                        <td class="text-end">₹${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td class="text-end">${parseFloat(item.discount_amount || 0).toFixed(2)}</td>
                        <td class="text-end">₹${parseFloat(item.total_amount).toFixed(2)}</td>
                    </tr>
                `;
            });
            itemsHtml += '</tbody></table></div>';
        } else {
            itemsHtml = '<p class="text-muted">No items found</p>';
        }
        
        $('#detailsContent').html(`
            <div class="container-fluid">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm details-table">
                            <tr><td width="40%"><strong>Invoice Number:</strong></td><td>${escapeHtml(entry.invoice_number)}</td></tr>
                            <tr><td><strong>Date:</strong></td><td>${formatDate(entry.invoice_date)}</td></tr>
                            <tr><td><strong>Driver Name:</strong></td><td>${escapeHtml(entry.driver_name)}</td></tr>
                            <tr><td><strong>Driver Mobile:</strong></td><td>${escapeHtml(entry.driver_number || 'N/A')}</td></tr>
                            <tr><td><strong>Location:</strong></td><td>${escapeHtml(entry.location)}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm details-table">
                            <tr><td width="40%"><strong>Customer Type:</strong></td><td>${capitalize(escapeHtml(entry.customer_type))}</td></tr>
                            <tr><td><strong>Customer Name:</strong></td><td>${escapeHtml(entry.customer_name)}</td></tr>
                            <tr><td><strong>Customer Mobile:</strong></td><td>${escapeHtml(entry.customer_mobile || 'N/A')}</td></tr>
                            <tr><td><strong>Payment Status:</strong></td><td><span class="status-badge status-${entry.payment_status}">${capitalize(entry.payment_status)}</span></td></tr>
                            <tr><td><strong>Created By:</strong></td><td>${escapeHtml(entry.created_by_name || 'System')}</td></tr>
                        </table>
                    </div>
                </div>
                
                <h6 class="mt-3">Products / Services</h6>
                ${itemsHtml}
                
                <div class="row mt-3">
                    <div class="col-md-6 offset-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td class="text-end"><strong>Subtotal:</strong></td><td class="text-end">₹${parseFloat(entry.subtotal).toFixed(2)}</td></tr>
                            <tr><td class="text-end"><strong>Discount:</strong></td><td class="text-end text-danger">-₹${parseFloat(entry.discount_total).toFixed(2)}</td></tr>
                            <tr><td class="text-end"><strong>GST Amount:</strong></td><td class="text-end">₹${parseFloat(entry.gst_total || 0).toFixed(2)}</td></tr>
                            <tr class="border-top"><td class="text-end"><strong>Grand Total:</strong></td><td class="text-end"><strong>₹${parseFloat(entry.grand_total).toFixed(2)}</strong></td></tr>
                            <tr><td class="text-end"><strong>Paid Amount:</strong></td><td class="text-end text-success">₹${parseFloat(entry.paid_amount || 0).toFixed(2)}</td></tr>
                            <tr><td class="text-end"><strong>Outstanding:</strong></td><td class="text-end text-danger"><strong>₹${outstanding.toFixed(2)}</strong></td></tr>
                        </table>
                    </div>
                </div>
                
                ${entry.notes ? `<div class="mt-3"><strong>Notes:</strong><p class="mt-1">${escapeHtml(entry.notes)}</p></div>` : ''}
            </div>
        `);
    }

    // Helper functions
    function formatDate(dateString) {
        if (!dateString) return '-';
        var date = new Date(dateString);
        return date.toLocaleDateString('en-IN');
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
    
    // Refresh button
    $('#refreshBtn').click(function() {
        location.reload();
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