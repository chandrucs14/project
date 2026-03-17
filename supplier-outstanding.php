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
$error = '';
$success = '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle payment to supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_payment'])) {
    $payment_supplier_id = (int)$_POST['supplier_id'];
    $amount = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $reference_no = trim($_POST['reference_no'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes = trim($_POST['notes'] ?? '');
    
    if ($amount <= 0) {
        $error = "Please enter a valid amount.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get supplier current outstanding
            $suppStmt = $pdo->prepare("SELECT outstanding_balance, name, supplier_code FROM suppliers WHERE id = ?");
            $suppStmt->execute([$payment_supplier_id]);
            $supplier = $suppStmt->fetch();
            
            if (!$supplier) {
                throw new Exception("Supplier not found.");
            }
            
            $current_outstanding = $supplier['outstanding_balance'];
            $new_outstanding = $current_outstanding - $amount;
            
            // Insert into supplier_outstanding
            $stmt = $pdo->prepare("
                INSERT INTO supplier_outstanding (
                    supplier_id, transaction_type, reference_id, transaction_date, 
                    amount, balance_after, due_date, status, created_by, created_at
                ) VALUES (?, 'payment', 0, ?, ?, ?, NULL, 'settled', ?, NOW())
            ");
            
            $stmt->execute([
                $payment_supplier_id,
                $payment_date,
                $amount,
                $new_outstanding,
                $_SESSION['user_id']
            ]);
            
            $outstanding_id = $pdo->lastInsertId();
            
            // Update supplier outstanding balance
            $updateStmt = $pdo->prepare("UPDATE suppliers SET outstanding_balance = ? WHERE id = ?");
            $updateStmt->execute([$new_outstanding, $payment_supplier_id]);
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 3, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'supplier_id' => $payment_supplier_id,
                'supplier_name' => $supplier['name'],
                'supplier_code' => $supplier['supplier_code'],
                'amount' => $amount,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'new_balance' => $new_outstanding
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Payment of ₹$amount made to supplier: " . $supplier['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Payment recorded successfully. New outstanding balance: ₹" . number_format($new_outstanding, 2);
            header("Location: supplier-outstanding.php?" . http_build_query([
                'supplier_id' => $supplier_id,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'status' => $status,
                'page' => $page
            ]));
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to record payment: " . $e->getMessage();
            error_log("Supplier payment error: " . $e->getMessage());
        }
    }
}

// Build the query for outstanding records
$query = "
    SELECT 
        so.*,
        s.name as supplier_name,
        s.supplier_code,
        s.phone as supplier_phone,
        s.email as supplier_email,
        CASE 
            WHEN so.transaction_type = 'purchase' THEN po.po_number 
            WHEN so.transaction_type = 'payment' THEN 'PAYMENT'
            WHEN so.transaction_type = 'credit_note' THEN 'CREDIT'
            WHEN so.transaction_type = 'debit_note' THEN 'DEBIT'
            ELSE '-'
        END as reference_number,
        CASE 
            WHEN so.transaction_type = 'purchase' THEN po.total_amount 
            ELSE NULL
        END as purchase_amount,
        u.full_name as created_by_name
    FROM supplier_outstanding so
    JOIN suppliers s ON so.supplier_id = s.id
    LEFT JOIN purchase_orders po ON so.transaction_type = 'purchase' AND so.reference_id = po.id
    LEFT JOIN users u ON so.created_by = u.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM supplier_outstanding so
    JOIN suppliers s ON so.supplier_id = s.id
    WHERE 1=1
";

$params = [];

if ($supplier_id > 0) {
    $query .= " AND so.supplier_id = ?";
    $countQuery .= " AND so.supplier_id = ?";
    $params[] = $supplier_id;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.phone LIKE ? OR po.po_number LIKE ?)";
    $countQuery .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.phone LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $query .= " AND so.status = ?";
    $countQuery .= " AND so.status = ?";
    $params[] = $status;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(so.transaction_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(so.transaction_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

// Get total records for pagination
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get outstanding records for current page
$query .= " ORDER BY so.transaction_date DESC, so.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$outstanding_records = $stmt->fetchAll();

// Get summary statistics
$summaryQuery = "
    SELECT 
        COUNT(DISTINCT so.supplier_id) as total_suppliers_with_outstanding,
        SUM(CASE WHEN so.transaction_type = 'purchase' THEN so.amount ELSE 0 END) as total_purchases,
        SUM(CASE WHEN so.transaction_type = 'payment' THEN so.amount ELSE 0 END) as total_payments,
        SUM(CASE WHEN so.status = 'pending' THEN so.amount ELSE 0 END) as total_pending,
        SUM(CASE WHEN so.status = 'partial' THEN so.amount ELSE 0 END) as total_partial,
        SUM(CASE WHEN so.status = 'settled' THEN so.amount ELSE 0 END) as total_settled,
        COALESCE(SUM(s.outstanding_balance), 0) as current_total_outstanding
    FROM suppliers s
    LEFT JOIN supplier_outstanding so ON s.id = so.supplier_id
    WHERE s.outstanding_balance > 0
";

$summaryStmt = $pdo->query($summaryQuery);
$summary = $summaryStmt->fetch();

// Get top suppliers with highest outstanding
$topSuppliersStmt = $pdo->query("
    SELECT id, name, supplier_code, phone, company_name, outstanding_balance 
    FROM suppliers 
    WHERE outstanding_balance > 0 
    ORDER BY outstanding_balance DESC 
    LIMIT 5
");
$topSuppliers = $topSuppliersStmt->fetchAll();

// Get all suppliers for dropdown
$allSuppliersStmt = $pdo->query("SELECT id, name, supplier_code, company_name, outstanding_balance FROM suppliers WHERE is_active = 1 ORDER BY name");
$allSuppliers = $allSuppliersStmt->fetchAll();

// Get selected supplier details if supplier_id is provided
$selectedSupplier = null;
if ($supplier_id > 0) {
    $suppStmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $suppStmt->execute([$supplier_id]);
    $selectedSupplier = $suppStmt->fetch();
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
                            <h4 class="mb-0 font-size-18">Supplier Outstanding</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-suppliers.php">Suppliers</a></li>
                                    <li class="breadcrumb-item active">Outstanding</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Alerts -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="bi bi-truck font-size-22"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Suppliers with Outstanding</p>
                                        <h4><?= number_format($summary['total_suppliers_with_outstanding'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="bi bi-cash-stack font-size-22"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Outstanding</p>
                                        <h4>₹<?= number_format($summary['current_total_outstanding'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="bi bi-cart-check font-size-22"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Purchases</p>
                                        <h4>₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="bi bi-cash font-size-22"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Payments</p>
                                        <h4>₹<?= number_format($summary['total_payments'] ?? 0, 2) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Filter and Actions Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row g-3">
                                                <div class="col-md-3">
                                                    <label class="form-label">Supplier</label>
                                                    <select name="supplier_id" class="form-control select2">
                                                        <option value="0">All Suppliers</option>
                                                        <?php foreach ($allSuppliers as $supp): ?>
                                                            <option value="<?= $supp['id'] ?>" <?= $supplier_id == $supp['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($supp['name']) ?> (<?= htmlspecialchars($supp['supplier_code']) ?>)
                                                                <?php if ($supp['outstanding_balance'] > 0): ?>
                                                                    - ₹<?= number_format($supp['outstanding_balance'], 2) ?>
                                                                <?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">From Date</label>
                                                    <input type="date" class="form-control" name="from_date" value="<?= $from_date ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">To Date</label>
                                                    <input type="date" class="form-control" name="to_date" value="<?= $to_date ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Status</label>
                                                    <select name="status" class="form-control">
                                                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
                                                        <option value="settled" <?= $status === 'settled' ? 'selected' : '' ?>>Settled</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">&nbsp;</label>
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="bi bi-filter"></i> Apply
                                                        </button>
                                                        <a href="supplier-outstanding.php" class="btn btn-secondary">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                                                <i class="bi bi-cash"></i> Make Payment
                                            </button>
                                            <a href="create-purchase.php" class="btn btn-primary ms-2">
                                                <i class="bi bi-cart-plus"></i> New Purchase
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Supplier Details (if selected) -->
                <?php if ($selectedSupplier): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Supplier</h6>
                                        <h5><?= htmlspecialchars($selectedSupplier['name']) ?></h5>
                                        <p class="mb-0">Code: <?= htmlspecialchars($selectedSupplier['supplier_code']) ?></p>
                                        <?php if ($selectedSupplier['company_name']): ?>
                                            <small><?= htmlspecialchars($selectedSupplier['company_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Contact</h6>
                                        <p class="mb-1"><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($selectedSupplier['phone']) ?></p>
                                        <p class="mb-0"><i class="bi bi-envelope me-1"></i> <?= htmlspecialchars($selectedSupplier['email'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Outstanding Balance</h6>
                                        <h3 class="<?= $selectedSupplier['outstanding_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                            ₹<?= number_format($selectedSupplier['outstanding_balance'], 2) ?>
                                        </h3>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Payment Terms</h6>
                                        <h5><?= $selectedSupplier['payment_terms'] ? $selectedSupplier['payment_terms'] . ' days' : 'Not set' ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Outstanding Records Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Transaction History</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <span class="text-muted">
                                                Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (empty($outstanding_records)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-cash-stack" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No transactions found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <div>
                                            <button type="button" class="btn btn-success mt-2" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                                                <i class="bi bi-cash"></i> Make Payment
                                            </button>
                                            <a href="create-purchase.php<?= $supplier_id > 0 ? '?supplier_id=' . $supplier_id : '' ?>" class="btn btn-primary mt-2 ms-2">
                                                <i class="bi bi-cart-plus"></i> New Purchase
                                            </a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-centered mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Type</th>
                                                    <th>Reference #</th>
                                                    <th>Amount</th>
                                                    <th>Balance After</th>
                                                    <th>Status</th>
                                                    <th>Due Date</th>
                                                    <th>Created By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($outstanding_records as $record): ?>
                                                    <tr>
                                                        <td><?= date('d-m-Y', strtotime($record['transaction_date'])) ?></td>
                                                        <td>
                                                            <strong><?= htmlspecialchars($record['supplier_name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($record['supplier_code']) ?></small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $typeClass = '';
                                                            $typeIcon = '';
                                                            switch($record['transaction_type']) {
                                                                case 'purchase':
                                                                    $typeClass = 'primary';
                                                                    $typeIcon = 'bi-cart';
                                                                    break;
                                                                case 'payment':
                                                                    $typeClass = 'success';
                                                                    $typeIcon = 'bi-cash';
                                                                    break;
                                                                case 'credit_note':
                                                                    $typeClass = 'info';
                                                                    $typeIcon = 'bi-credit-card';
                                                                    break;
                                                                case 'debit_note':
                                                                    $typeClass = 'warning';
                                                                    $typeIcon = 'bi-card-text';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $typeClass ?> text-<?= $typeClass ?>">
                                                                <i class="bi <?= $typeIcon ?> me-1"></i>
                                                                <?= ucfirst(str_replace('_', ' ', $record['transaction_type'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($record['transaction_type'] === 'purchase'): ?>
                                                                <a href="view-purchase-order.php?id=<?= $record['reference_id'] ?>" class="text-primary">
                                                                    <?= htmlspecialchars($record['reference_number']) ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <?= htmlspecialchars($record['reference_number']) ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="<?= ($record['transaction_type'] === 'payment' || $record['transaction_type'] === 'credit_note') ? 'text-success' : 'text-danger' ?> fw-bold">
                                                            <?= ($record['transaction_type'] === 'payment' || $record['transaction_type'] === 'credit_note') ? '-' : '+' ?>
                                                            ₹<?= number_format($record['amount'], 2) ?>
                                                        </td>
                                                        <td>
                                                            <strong>₹<?= number_format($record['balance_after'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = '';
                                                            switch($record['status']) {
                                                                case 'pending':
                                                                    $statusClass = 'warning';
                                                                    break;
                                                                case 'partial':
                                                                    $statusClass = 'info';
                                                                    break;
                                                                case 'settled':
                                                                    $statusClass = 'success';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                                <?= ucfirst($record['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?= $record['due_date'] ? date('d-m-Y', strtotime($record['due_date'])) : '-' ?>
                                                            <?php 
                                                            if ($record['due_date'] && $record['status'] !== 'settled') {
                                                                $due_date = strtotime($record['due_date']);
                                                                $today = strtotime(date('Y-m-d'));
                                                                if ($due_date < $today) {
                                                                    echo '<br><small class="text-danger">Overdue</small>';
                                                                }
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <small><?= htmlspecialchars($record['created_by_name'] ?? 'N/A') ?></small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Pagination -->
                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-sm-6">
                                                <div class="text-muted">
                                                    Page <?= $page ?> of <?= $totalPages ?>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <ul class="pagination justify-content-end">
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>">
                                                            <i class="bi bi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="<?= buildPaginationUrl($i) ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">
                                                            <i class="bi bi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Top Suppliers Widget -->
                <?php if (!empty($topSuppliers)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Top Suppliers by Outstanding</h4>

                                <div class="table-responsive">
                                    <table class="table table-hover table-centered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Supplier</th>
                                                <th>Code</th>
                                                <th>Company</th>
                                                <th>Phone</th>
                                                <th>Outstanding</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topSuppliers as $top): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($top['name']) ?></strong></td>
                                                <td><?= htmlspecialchars($top['supplier_code']) ?></td>
                                                <td><?= htmlspecialchars($top['company_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($top['phone']) ?></td>
                                                <td class="text-danger fw-bold">₹<?= number_format($top['outstanding_balance'], 2) ?></td>
                                                <td>
                                                    <a href="supplier-outstanding.php?supplier_id=<?= $top['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- end top suppliers -->

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

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-labelledby="addPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title text-white" id="addPaymentModalLabel">
                    <i class="bi bi-cash"></i> Make Payment to Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="paymentForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                        <select name="supplier_id" class="form-control select2-modal" required>
                            <option value="">Choose supplier...</option>
                            <?php foreach ($allSuppliers as $supp): ?>
                                <option value="<?= $supp['id'] ?>" <?= ($supplier_id == $supp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($supp['name']) ?> (<?= htmlspecialchars($supp['supplier_code']) ?>) 
                                    <?php if ($supp['outstanding_balance'] > 0): ?>
                                        - Outstanding: ₹<?= number_format($supp['outstanding_balance'], 2) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-currency-rupee"></i></span>
                            <input type="number" name="amount" class="form-control" placeholder="Enter amount" min="0.01" step="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference No.</label>
                        <input type="text" name="reference_no" class="form-control" placeholder="Cheque/Transaction No.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x"></i> Cancel
                    </button>
                    <button type="submit" name="make_payment" class="btn btn-success" id="paymentSubmitBtn">
                        <i class="bi bi-check"></i>
                        <span id="paymentBtnText">Record Payment</span>
                        <span id="paymentLoading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                            Processing...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            width: '100%',
            placeholder: 'Select option'
        });
        
        $('.select2-modal').select2({
            width: '100%',
            placeholder: 'Choose supplier...',
            dropdownParent: $('#addPaymentModal')
        });
    });

    // Form submission loading state
    document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('paymentSubmitBtn');
        const btnText = document.getElementById('paymentBtnText');
        const loading = document.getElementById('paymentLoading');
        
        btn.disabled = true;
        btnText.style.display = 'none';
        loading.style.display = 'inline-block';
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 500);
        });
    }, 5000);
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Date validation
    document.querySelector('input[name="from_date"]')?.addEventListener('change', function() {
        const toDate = document.querySelector('input[name="to_date"]');
        if (toDate.value < this.value) {
            toDate.value = this.value;
        }
    });
    
    document.querySelector('input[name="to_date"]')?.addEventListener('change', function() {
        const fromDate = document.querySelector('input[name="from_date"]');
        if (fromDate.value > this.value) {
            fromDate.value = this.value;
        }
    });
    
    // Amount validation
    document.querySelector('input[name="amount"]')?.addEventListener('input', function() {
        if (this.value < 0) {
            this.value = 0;
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + P to open payment modal
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            $('#addPaymentModal').modal('show');
        }
        
        // Alt + N to go to new purchase
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'create-purchase.php<?= $supplier_id > 0 ? '?supplier_id=' . $supplier_id : '' ?>';
        }
        
        // Ctrl + F to focus supplier filter
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.querySelector('select[name="supplier_id"]')?.focus();
        }
    });
    
    // Export function
    function exportOutstanding() {
        Swal.fire({
            title: 'Export Data?',
            text: 'This will download the outstanding transactions as CSV file',
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#34c38f',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Export',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'export-supplier-outstanding.php?' + new URLSearchParams({
                    supplier_id: '<?= $supplier_id ?>',
                    from_date: '<?= $from_date ?>',
                    to_date: '<?= $to_date ?>',
                    status: '<?= $status ?>'
                }).toString();
            }
        });
    }
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'supplier-outstanding.php?' . http_build_query($params);
}
?>

<style>
.avatar-sm {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
}

/* Button hover effects */
.btn-soft-primary:hover,
.btn-soft-success:hover,
.btn-soft-info:hover,
.btn-soft-danger:hover {
    transform: translateY(-2px);
    transition: transform 0.2s;
}

/* Table row hover */
.table-hover tbody tr:hover {
    background-color: rgba(85, 110, 230, 0.02);
}

/* Badge styling */
.badge {
    padding: 6px 10px;
    font-size: 11px;
}

/* Alert animations */
.alert {
    transition: opacity 0.5s ease;
}

/* SweetAlert2 customization */
.swal2-popup {
    font-family: inherit;
}

.swal2-title {
    font-size: 1.2rem;
}

.swal2-html-container {
    font-size: 0.95rem;
}

.swal2-confirm {
    background-color: #556ee6 !important;
}

.swal2-cancel {
    background-color: #f46a6a !important;
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

/* Pagination styling */
.pagination .page-link {
    color: #556ee6;
    border: none;
    margin: 0 3px;
    border-radius: 5px;
}

.pagination .page-item.active .page-link {
    background-color: #556ee6;
    color: white;
}

/* Card styling */
.card.bg-soft-primary {
    background-color: rgba(85, 110, 230, 0.1) !important;
}

/* Modal select2 */
.select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: #6c757d;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .btn-group .btn {
        border-radius: 4px !important;
        margin: 0;
    }
    
    .table td {
        min-width: 120px;
    }
}
</style>

</body>
</html>