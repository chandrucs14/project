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
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01'); // First day of current month
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Records per page
$offset = ($page - 1) * $limit;

// Handle payment entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $payment_customer_id = (int)$_POST['customer_id'];
    $amount = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($amount <= 0) {
        $error = "Please enter a valid amount.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get customer current outstanding
            $custStmt = $pdo->prepare("SELECT outstanding_balance, name FROM customers WHERE id = ?");
            $custStmt->execute([$payment_customer_id]);
            $customer = $custStmt->fetch();
            
            if (!$customer) {
                throw new Exception("Customer not found.");
            }
            
            $current_outstanding = $customer['outstanding_balance'];
            $new_outstanding = $current_outstanding - $amount;
            
            // Insert into customer_outstanding
            $stmt = $pdo->prepare("
                INSERT INTO customer_outstanding (
                    customer_id, transaction_type, reference_id, transaction_date, 
                    amount, balance_after, due_date, status, created_by, created_at
                ) VALUES (?, 'payment', 0, ?, ?, ?, NULL, 'settled', ?, NOW())
            ");
            
            $stmt->execute([
                $payment_customer_id,
                $payment_date,
                $amount,
                $new_outstanding,
                $_SESSION['user_id']
            ]);
            
            // Update customer outstanding balance
            $updateStmt = $pdo->prepare("UPDATE customers SET outstanding_balance = ? WHERE id = ?");
            $updateStmt->execute([$new_outstanding, $payment_customer_id]);
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 3, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'customer_id' => $payment_customer_id,
                'customer_name' => $customer['name'],
                'amount' => $amount,
                'type' => 'payment',
                'new_balance' => $new_outstanding
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Payment of ₹$amount received from " . $customer['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $success = "Payment recorded successfully. New outstanding balance: ₹" . number_format($new_outstanding, 2);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to record payment: " . $e->getMessage();
            error_log("Payment error: " . $e->getMessage());
        }
    }
}

// Build the query for outstanding records
$query = "
    SELECT 
        co.*,
        c.name as customer_name,
        c.customer_code,
        c.phone as customer_phone,
        c.email as customer_email,
        CASE 
            WHEN co.transaction_type = 'invoice' THEN i.invoice_number 
            WHEN co.transaction_type = 'payment' THEN 'PAYMENT'
            WHEN co.transaction_type = 'credit_note' THEN 'CREDIT'
            WHEN co.transaction_type = 'debit_note' THEN 'DEBIT'
            ELSE '-'
        END as reference_number,
        CASE 
            WHEN co.transaction_type = 'invoice' THEN i.total_amount 
            ELSE NULL
        END as invoice_amount
    FROM customer_outstanding co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN invoices i ON co.transaction_type = 'invoice' AND co.reference_id = i.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM customer_outstanding co
    JOIN customers c ON co.customer_id = c.id
    WHERE 1=1
";

$params = [];

if ($customer_id > 0) {
    $query .= " AND co.customer_id = ?";
    $countQuery .= " AND co.customer_id = ?";
    $params[] = $customer_id;
}

if (!empty($search)) {
    $query .= " AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.phone LIKE ?)";
    $countQuery .= " AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.phone LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $query .= " AND co.status = ?";
    $countQuery .= " AND co.status = ?";
    $params[] = $status;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(co.transaction_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(co.transaction_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

// Get total records for pagination
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get outstanding records for current page
$query .= " ORDER BY co.transaction_date DESC, co.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$outstanding_records = $stmt->fetchAll();

// Get summary statistics
$summaryQuery = "
    SELECT 
        COUNT(DISTINCT co.customer_id) as total_customers_with_outstanding,
        SUM(CASE WHEN co.transaction_type = 'invoice' THEN co.amount ELSE 0 END) as total_invoices,
        SUM(CASE WHEN co.transaction_type = 'payment' THEN co.amount ELSE 0 END) as total_payments,
        SUM(CASE WHEN co.status = 'pending' THEN co.amount ELSE 0 END) as total_pending,
        SUM(CASE WHEN co.status = 'partial' THEN co.amount ELSE 0 END) as total_partial,
        SUM(CASE WHEN co.status = 'settled' THEN co.amount ELSE 0 END) as total_settled,
        SUM(c.outstanding_balance) as current_total_outstanding
    FROM customers c
    LEFT JOIN customer_outstanding co ON c.id = co.customer_id
    WHERE c.outstanding_balance > 0
";

$summaryStmt = $pdo->query($summaryQuery);
$summary = $summaryStmt->fetch();

// Get top customers with highest outstanding
$topCustomersStmt = $pdo->query("
    SELECT id, name, customer_code, phone, outstanding_balance 
    FROM customers 
    WHERE outstanding_balance > 0 
    ORDER BY outstanding_balance DESC 
    LIMIT 5
");
$topCustomers = $topCustomersStmt->fetchAll();

// Get all customers for dropdown
$allCustomersStmt = $pdo->query("SELECT id, name, customer_code, outstanding_balance FROM customers WHERE is_active = 1 ORDER BY name");
$allCustomers = $allCustomersStmt->fetchAll();

// Get selected customer details if customer_id is provided
$selectedCustomer = null;
if ($customer_id > 0) {
    $custStmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $custStmt->execute([$customer_id]);
    $selectedCustomer = $custStmt->fetch();
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
                            <h4 class="mb-0 font-size-18">Customer Outstanding</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-customers.php">Customers</a></li>
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
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($summary['total_customers_with_outstanding'] ?? 0) ?></h3>
                                Customers with Outstanding
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2">₹<?= number_format($summary['current_total_outstanding'] ?? 0, 2) ?></h3>
                                Total Outstanding
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">₹<?= number_format($summary['total_pending'] ?? 0, 2) ?></h3>
                                Pending Amount
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">₹<?= number_format($summary['total_payments'] ?? 0, 2) ?></h3>
                                Total Payments
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
                                    <div class="col-md-9">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Customer</label>
                                                        <select name="customer_id" class="form-control">
                                                            <option value="0">All Customers</option>
                                                            <?php foreach ($allCustomers as $cust): ?>
                                                                <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($cust['name']) ?> (₹<?= number_format($cust['outstanding_balance'], 2) ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">From Date</label>
                                                        <input type="date" class="form-control" name="from_date" value="<?= $from_date ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">To Date</label>
                                                        <input type="date" class="form-control" name="to_date" value="<?= $to_date ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
                                                            <option value="settled" <?= $status === 'settled' ? 'selected' : '' ?>>Settled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-50">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                            <a href="customer-outstanding.php" class="btn btn-secondary w-50">
                                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                                                <i class="mdi mdi-cash-plus me-1"></i> Record Payment
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Customer Details (if selected) -->
                <?php if ($selectedCustomer): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Customer</h6>
                                        <h5><?= htmlspecialchars($selectedCustomer['name']) ?></h5>
                                        <p class="mb-0">Code: <?= htmlspecialchars($selectedCustomer['customer_code']) ?></p>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Contact</h6>
                                        <p class="mb-1"><i class="mdi mdi-phone me-1"></i> <?= htmlspecialchars($selectedCustomer['phone']) ?></p>
                                        <p class="mb-0"><i class="mdi mdi-email me-1"></i> <?= htmlspecialchars($selectedCustomer['email'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Outstanding Balance</h6>
                                        <h3 class="text-danger">₹<?= number_format($selectedCustomer['outstanding_balance'], 2) ?></h3>
                                    </div>
                                    <div class="col-md-3">
                                        <h6 class="text-primary">Credit Limit</h6>
                                        <h5><?= $selectedCustomer['credit_limit'] ? '₹'.number_format($selectedCustomer['credit_limit'], 2) : 'No Limit' ?></h5>
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
                                <h4 class="card-title mb-4">Transaction History</h4>
                                
                                <?php if (empty($outstanding_records)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-cash-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No transactions found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Customer</th>
                                                    <th>Type</th>
                                                    <th>Reference #</th>
                                                    <th>Amount</th>
                                                    <th>Balance After</th>
                                                    <th>Status</th>
                                                    <th>Due Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($outstanding_records as $record): ?>
                                                    <tr>
                                                        <td><?= date('d-m-Y', strtotime($record['transaction_date'])) ?></td>
                                                        <td>
                                                            <strong><?= htmlspecialchars($record['customer_name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($record['customer_code']) ?></small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $typeClass = '';
                                                            $typeIcon = '';
                                                            switch($record['transaction_type']) {
                                                                case 'invoice':
                                                                    $typeClass = 'primary';
                                                                    $typeIcon = 'file-document';
                                                                    break;
                                                                case 'payment':
                                                                    $typeClass = 'success';
                                                                    $typeIcon = 'cash';
                                                                    break;
                                                                case 'credit_note':
                                                                    $typeClass = 'info';
                                                                    $typeIcon = 'credit-card';
                                                                    break;
                                                                case 'debit_note':
                                                                    $typeClass = 'warning';
                                                                    $typeIcon = 'card-bulleted';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $typeClass ?> text-<?= $typeClass ?>">
                                                                <i class="mdi mdi-<?= $typeIcon ?> me-1"></i>
                                                                <?= ucfirst(str_replace('_', ' ', $record['transaction_type'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($record['transaction_type'] === 'invoice'): ?>
                                                                <a href="view-invoice.php?id=<?= $record['reference_id'] ?>" class="text-primary">
                                                                    <?= htmlspecialchars($record['reference_number']) ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <?= htmlspecialchars($record['reference_number']) ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="<?= ($record['transaction_type'] === 'payment' || $record['transaction_type'] === 'credit_note') ? 'text-success' : 'text-danger' ?> font-weight-bold">
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
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- end table-responsive -->

                                    <!-- Pagination -->
                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-sm-6">
                                                <div class="text-muted">
                                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <ul class="pagination justify-content-end">
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-right"></i>
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

                <!-- Top Customers Widget -->
                <?php if (!empty($topCustomers)): ?>
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Top Customers by Outstanding</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Customer</th>
                                                <th>Code</th>
                                                <th>Phone</th>
                                                <th>Outstanding</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topCustomers as $top): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($top['name']) ?></td>
                                                <td><?= htmlspecialchars($top['customer_code']) ?></td>
                                                <td><?= htmlspecialchars($top['phone']) ?></td>
                                                <td class="text-danger font-weight-bold">₹<?= number_format($top['outstanding_balance'], 2) ?></td>
                                                <td>
                                                    <a href="customer-outstanding.php?customer_id=<?= $top['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i> View Details
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
                <!-- end top customers -->

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
                    <i class="mdi mdi-cash-plus me-2"></i>
                    Record Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="paymentForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-control" required>
                            <option value="">Choose customer...</option>
                            <?php foreach ($allCustomers as $cust): ?>
                                <option value="<?= $cust['id'] ?>" <?= ($customer_id == $cust['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cust['name']) ?> (Outstanding: ₹<?= number_format($cust['outstanding_balance'], 2) ?>)
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
                            <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                            <input type="number" name="amount" class="form-control" placeholder="Enter amount" min="0.01" step="0.01" required>
                        </div>
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
                        <i class="mdi mdi-close me-1"></i> Cancel
                    </button>
                    <button type="submit" name="add_payment" class="btn btn-success" id="paymentSubmitBtn">
                        <i class="mdi mdi-check me-1"></i>
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

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
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
                alert.remove();
            }, 500);
        });
    }, 5000);
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-submit on filter changes
    document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
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
    
    // Amount validation in payment modal
    document.querySelector('input[name="amount"]')?.addEventListener('input', function() {
        if (this.value < 0) {
            this.value = 0;
        }
    });
    
    // Show customer details when selected
    document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
        if (this.value) {
            const selectedOption = this.options[this.selectedIndex];
            const outstanding = selectedOption.text.match(/₹([\d,]+\.\d{2})/);
            if (outstanding) {
                // Could display the outstanding balance dynamically
            }
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + P to open payment modal
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            $('#addPaymentModal').modal('show');
        }
        
        // Ctrl + F to focus customer filter
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.querySelector('select[name="customer_id"]')?.focus();
        }
    });
    
    // Export functionality
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
                window.location.href = 'export-outstanding.php?' + new URLSearchParams({
                    customer_id: '<?= $customer_id ?>',
                    from_date: '<?= $from_date ?>',
                    to_date: '<?= $to_date ?>',
                    status: '<?= $status ?>'
                }).toString();
            }
        });
    }
</script>

</body>
</html>