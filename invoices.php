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
$payment_type = isset($_GET['payment_type']) ? trim($_GET['payment_type']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle invoice status update
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $invoice_id = (int)$_GET['id'];
    
    $allowed_actions = ['draft', 'sent', 'partially_paid', 'paid', 'cancelled'];
    if (in_array($action, $allowed_actions)) {
        try {
            $pdo->beginTransaction();
            
            // Get invoice details before update
            $getStmt = $pdo->prepare("SELECT invoice_number, customer_id, total_amount, paid_amount FROM invoices WHERE id = ?");
            $getStmt->execute([$invoice_id]);
            $invoice = $getStmt->fetch();
            
            if (!$invoice) {
                throw new Exception("Invoice not found.");
            }
            
            // Update invoice status
            $stmt = $pdo->prepare("UPDATE invoices SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$action, $_SESSION['user_id'], $invoice_id]);
            
            // If status is paid, update paid_amount to total_amount
            if ($action === 'paid') {
                $updatePaid = $pdo->prepare("UPDATE invoices SET paid_amount = total_amount, outstanding_amount = 0 WHERE id = ?");
                $updatePaid->execute([$invoice_id]);
                
                // Update customer outstanding balance
                $updateCustStmt = $pdo->prepare("UPDATE customers SET outstanding_balance = outstanding_balance - ? WHERE id = ?");
                $updateCustStmt->execute([$invoice['total_amount'] - $invoice['paid_amount'], $invoice['customer_id']]);
            }
            
            // If status is cancelled, revert any paid amount
            if ($action === 'cancelled' && $invoice['paid_amount'] > 0) {
                $updateCustStmt = $pdo->prepare("UPDATE customers SET outstanding_balance = outstanding_balance - ? WHERE id = ?");
                $updateCustStmt->execute([$invoice['paid_amount'], $invoice['customer_id']]);
            }
            
            // Get customer name for logging
            $custStmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $custStmt->execute([$invoice['customer_id']]);
            $customer = $custStmt->fetch();
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice['invoice_number'],
                'customer_name' => $customer['name'],
                'new_status' => $action
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Invoice #{$invoice['invoice_number']} status updated to $action",
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Invoice status updated successfully.";
            header("Location: invoices.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'payment_type' => $payment_type, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to update invoice status: " . $e->getMessage();
            error_log("Invoice status update error: " . $e->getMessage());
        }
    }
}

// Handle delete invoice
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $invoice_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if invoice has items
        $checkItems = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?");
        $checkItems->execute([$invoice_id]);
        $itemCount = $checkItems->fetchColumn();
        
        // Get invoice details for logging
        $getStmt = $pdo->prepare("SELECT invoice_number, customer_id, paid_amount FROM invoices WHERE id = ?");
        $getStmt->execute([$invoice_id]);
        $invoice = $getStmt->fetch();
        
        if (!$invoice) {
            throw new Exception("Invoice not found.");
        }
        
        // Get customer name
        $custStmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $custStmt->execute([$invoice['customer_id']]);
        $customer = $custStmt->fetch();
        
        // Update customer outstanding balance if payment was made
        if ($invoice['paid_amount'] > 0) {
            $updateCustStmt = $pdo->prepare("UPDATE customers SET outstanding_balance = outstanding_balance - ? WHERE id = ?");
            $updateCustStmt->execute([$invoice['paid_amount'], $invoice['customer_id']]);
        }
        
        // Delete invoice vehicles first
        $delVehicles = $pdo->prepare("DELETE FROM invoice_vehicles WHERE invoice_id = ?");
        $delVehicles->execute([$invoice_id]);
        
        // Delete invoice items
        if ($itemCount > 0) {
            $delItems = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $delItems->execute([$invoice_id]);
        }
        
        // Delete the invoice
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
        $result = $stmt->execute([$invoice_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice['invoice_number'],
                'customer_name' => $customer['name']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Invoice #{$invoice['invoice_number']} deleted",
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Invoice deleted successfully.";
        }
        
        header("Location: invoices.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'payment_type' => $payment_type, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to delete invoice: " . $e->getMessage();
        header("Location: invoices.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'payment_type' => $payment_type, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
    }
}

// Build the query
$query = "
    SELECT 
        i.*,
        c.name as customer_name,
        c.customer_code,
        c.phone as customer_phone,
        c.email as customer_email,
        c.city as customer_city,
        u.full_name as created_by_name,
        u2.full_name as updated_by_name,
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count,
        (SELECT COUNT(*) FROM invoice_vehicles WHERE invoice_id = i.id) as vehicle_count
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    LEFT JOIN users u2 ON i.updated_by = u2.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE 1=1
";

$params = [];

if ($customer_id > 0) {
    $query .= " AND i.customer_id = ?";
    $countQuery .= " AND i.customer_id = ?";
    $params[] = $customer_id;
}

if (!empty($search)) {
    $query .= " AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $countQuery .= " AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $query .= " AND i.status = ?";
    $countQuery .= " AND i.status = ?";
    $params[] = $status;
}

if ($payment_type !== 'all') {
    $query .= " AND i.payment_type = ?";
    $countQuery .= " AND i.payment_type = ?";
    $params[] = $payment_type;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY i.invoice_date DESC, i.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_invoices,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_invoices,
        SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_invoices,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_invoices,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_invoices,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(outstanding_amount), 0) as total_outstanding
    FROM invoices
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get all customers for dropdown
$custStmt = $pdo->query("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $custStmt->fetchAll();

// Get selected customer details if customer_id is provided
$selectedCustomer = null;
if ($customer_id > 0) {
    $selCustStmt = $pdo->prepare("SELECT name, customer_code, phone, email, city FROM customers WHERE id = ?");
    $selCustStmt->execute([$customer_id]);
    $selectedCustomer = $selCustStmt->fetch();
}

// Check for overdue invoices
$overdueStmt = $pdo->query("
    SELECT COUNT(*) as count, COALESCE(SUM(outstanding_amount), 0) as amount 
    FROM invoices 
    WHERE status IN ('sent', 'partially_paid') AND due_date < CURDATE()
");
$overdue = $overdueStmt->fetch();

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
                            <h4 class="mb-0 font-size-18">Manage Invoices</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-customers.php">Customers</a></li>
                                    <li class="breadcrumb-item active">Invoices</li>
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

                <!-- Overdue Alert -->
                <?php if ($overdue['count'] > 0): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-outline me-2"></i>
                            <strong><?= $overdue['count'] ?> invoice(s)</strong> are overdue with total outstanding of <strong>₹<?= number_format($overdue['amount'], 2) ?></strong>.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total_invoices'] ?? 0) ?></h3>
                                Total Invoices
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['draft_invoices'] ?? 0) ?></h3>
                                Draft
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['paid_invoices'] ?? 0) ?></h3>
                                Paid
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">₹<?= number_format($stats['total_outstanding'] ?? 0, 2) ?></h3>
                                Outstanding
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats Row -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2"><?= number_format($stats['sent_invoices'] ?? 0) ?></h3>
                                Sent
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-secondary mt-2"><?= number_format($stats['partially_paid_invoices'] ?? 0) ?></h3>
                                Partially Paid
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2"><?= number_format($stats['overdue_invoices'] ?? 0) ?></h3>
                                Overdue
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
                                                            <?php foreach ($customers as $cust): ?>
                                                                <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($cust['name']) ?> (<?= htmlspecialchars($cust['customer_code']) ?>)
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
                                                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                            <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                                                            <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                                                            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Payment Type</label>
                                                        <select name="payment_type" class="form-control">
                                                            <option value="all" <?= $payment_type === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="cash" <?= $payment_type === 'cash' ? 'selected' : '' ?>>Cash</option>
                                                            <option value="credit" <?= $payment_type === 'credit' ? 'selected' : '' ?>>Credit</option>
                                                            <option value="bank_transfer" <?= $payment_type === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                                            <option value="cheque" <?= $payment_type === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                            <option value="online" <?= $payment_type === 'online' ? 'selected' : '' ?>>Online</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-md-9">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               name="search" 
                                                               placeholder="Search by invoice number, customer name, phone or email..." 
                                                               value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" class="btn btn-primary w-50">
                                                            <i class="mdi mdi-filter me-1"></i> Filter
                                                        </button>
                                                        <a href="invoices.php" class="btn btn-secondary w-50">
                                                            <i class="mdi mdi-refresh me-1"></i> Reset
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="create-invoice.php" class="btn btn-success">
                                                <i class="mdi mdi-file-document-plus me-1"></i> Create Invoice
                                            </a>
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
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Customer</h6>
                                        <h5><?= htmlspecialchars($selectedCustomer['name']) ?></h5>
                                        <p class="mb-0">Code: <?= htmlspecialchars($selectedCustomer['customer_code']) ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Contact</h6>
                                        <p class="mb-1"><i class="mdi mdi-phone me-1"></i> <?= htmlspecialchars($selectedCustomer['phone']) ?></p>
                                        <p class="mb-0"><i class="mdi mdi-email me-1"></i> <?= htmlspecialchars($selectedCustomer['email'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Location</h6>
                                        <p class="mb-0"><?= htmlspecialchars($selectedCustomer['city'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Invoices Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Invoices List</h4>
                                
                                <?php if (empty($invoices)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-file-document-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No invoices found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="create-invoice.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-file-document-plus me-1"></i> Create New Invoice
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Customer</th>
                                                    <th>Date</th>
                                                    <th>Due Date</th>
                                                    <th>Items</th>
                                                    <th>Total</th>
                                                    <th>Paid</th>
                                                    <th>Outstanding</th>
                                                    <th>Payment Type</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($invoices as $invoice): ?>
                                                    <tr class="<?= ($invoice['status'] == 'overdue' || (strtotime($invoice['due_date']) < time() && $invoice['status'] != 'paid' && $invoice['status'] != 'cancelled')) ? 'table-warning' : '' ?>">
                                                        <td>
                                                            <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                                            <?php if ($invoice['vehicle_count'] > 0): ?>
                                                                <span class="badge bg-soft-info text-info ms-1" title="Has vehicle details">
                                                                    <i class="mdi mdi-truck"></i>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <a href="view-customer.php?id=<?= $invoice['customer_id'] ?>" class="text-primary">
                                                                <?= htmlspecialchars($invoice['customer_name']) ?>
                                                            </a>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($invoice['customer_code']) ?></small>
                                                        </td>
                                                        <td><?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?></td>
                                                        <td>
                                                            <?= $invoice['due_date'] ? date('d-m-Y', strtotime($invoice['due_date'])) : '-' ?>
                                                            <?php 
                                                            if ($invoice['due_date'] && $invoice['status'] != 'paid' && $invoice['status'] != 'cancelled') {
                                                                $due_date = strtotime($invoice['due_date']);
                                                                $today = strtotime(date('Y-m-d'));
                                                                if ($due_date < $today) {
                                                                    echo '<br><small class="text-danger">Overdue</small>';
                                                                }
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info"><?= $invoice['item_count'] ?> items</span>
                                                        </td>
                                                        <td>₹<?= number_format($invoice['total_amount'], 2) ?></td>
                                                        <td class="text-success">₹<?= number_format($invoice['paid_amount'], 2) ?></td>
                                                        <td class="<?= $invoice['outstanding_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                                            ₹<?= number_format($invoice['outstanding_amount'], 2) ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $paymentTypeClass = '';
                                                            switch($invoice['payment_type']) {
                                                                case 'cash':
                                                                    $paymentTypeClass = 'success';
                                                                    break;
                                                                case 'credit':
                                                                    $paymentTypeClass = 'warning';
                                                                    break;
                                                                case 'bank_transfer':
                                                                    $paymentTypeClass = 'primary';
                                                                    break;
                                                                case 'cheque':
                                                                    $paymentTypeClass = 'info';
                                                                    break;
                                                                case 'online':
                                                                    $paymentTypeClass = 'secondary';
                                                                    break;
                                                                default:
                                                                    $paymentTypeClass = 'secondary';
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $paymentTypeClass ?> text-<?= $paymentTypeClass ?>">
                                                                <?= ucfirst(str_replace('_', ' ', $invoice['payment_type'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = '';
                                                            $statusIcon = '';
                                                            switch($invoice['status']) {
                                                                case 'draft':
                                                                    $statusClass = 'secondary';
                                                                    $statusIcon = 'file-document-outline';
                                                                    break;
                                                                case 'sent':
                                                                    $statusClass = 'primary';
                                                                    $statusIcon = 'send';
                                                                    break;
                                                                case 'partially_paid':
                                                                    $statusClass = 'warning';
                                                                    $statusIcon = 'clock-outline';
                                                                    break;
                                                                case 'paid':
                                                                    $statusClass = 'success';
                                                                    $statusIcon = 'check-circle';
                                                                    break;
                                                                case 'overdue':
                                                                    $statusClass = 'danger';
                                                                    $statusIcon = 'alert-circle';
                                                                    break;
                                                                case 'cancelled':
                                                                    $statusClass = 'secondary';
                                                                    $statusIcon = 'close-circle';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                                <i class="mdi mdi-<?= $statusIcon ?> me-1"></i>
                                                                <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="view-invoice.php?id=<?= $invoice['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-primary" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="View Invoice">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </a>
                                                                <a href="edit-invoice.php?id=<?= $invoice['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-success" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </a>
                                                                <a href="print-invoice.php?id=<?= $invoice['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-info" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Print"
                                                                   target="_blank">
                                                                    <i class="mdi mdi-printer"></i>
                                                                </a>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-soft-warning dropdown-toggle" 
                                                                        data-bs-toggle="dropdown" 
                                                                        aria-expanded="false"
                                                                        data-bs-toggle="tooltip" 
                                                                        title="Change Status">
                                                                    <i class="mdi mdi-cog"></i>
                                                                </button>
                                                                <ul class="dropdown-menu">
                                                                    <li><a class="dropdown-item <?= $invoice['status'] == 'draft' ? 'disabled' : '' ?>" 
                                                                           href="?action=draft&id=<?= $invoice['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-file-document-outline text-secondary me-2"></i> Draft</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $invoice['status'] == 'sent' ? 'disabled' : '' ?>" 
                                                                           href="?action=sent&id=<?= $invoice['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-send text-primary me-2"></i> Sent</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $invoice['status'] == 'partially_paid' ? 'disabled' : '' ?>" 
                                                                           href="?action=partially_paid&id=<?= $invoice['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-clock-outline text-warning me-2"></i> Partially Paid</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $invoice['status'] == 'paid' ? 'disabled' : '' ?>" 
                                                                           href="?action=paid&id=<?= $invoice['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-check-circle text-success me-2"></i> Paid</a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li><a class="dropdown-item <?= $invoice['status'] == 'cancelled' ? 'disabled' : '' ?> text-danger" 
                                                                           href="?action=cancelled&id=<?= $invoice['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-close-circle text-danger me-2"></i> Cancel Invoice</a>
                                                                    </li>
                                                                </ul>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $invoice['id'] ?>, '<?= htmlspecialchars(addslashes($invoice['invoice_number'])) ?>', '<?= htmlspecialchars(addslashes($search)) ?>', '<?= $customer_id ?>', '<?= $status ?>', '<?= $payment_type ?>', '<?= $from_date ?>', '<?= $to_date ?>', <?= $page ?>)"
                                                                   class="btn btn-sm btn-soft-danger"
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Delete">
                                                                    <i class="mdi mdi-delete"></i>
                                                                </a>
                                                            </div>
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&payment_type=<?= $payment_type ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
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

                <!-- Financial Summary -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Invoice Value Summary</h5>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Invoice Value:</span>
                                        <strong>₹<?= number_format($stats['total_amount'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Paid Amount:</span>
                                        <strong class="text-success">₹<?= number_format($stats['total_paid'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Outstanding:</span>
                                        <strong class="text-danger">₹<?= number_format($stats['total_outstanding'] ?? 0, 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Invoice Status Distribution</h5>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-secondary"><?= number_format($stats['draft_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Draft</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?= number_format($stats['sent_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Sent</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?= number_format($stats['partially_paid_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Partially Paid</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-success"><?= number_format($stats['paid_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Paid</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-danger"><?= number_format($stats['overdue_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Overdue</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-secondary"><?= number_format($stats['cancelled_invoices'] ?? 0) ?></h4>
                                            <p class="mb-0">Cancelled</p>
                                        </div>
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

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 5000);
    
    // Search with debounce
    let searchTimeout;
    document.querySelector('input[name="search"]')?.addEventListener('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });
    
    // Auto-submit on filter changes
    document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="payment_type"]')?.addEventListener('change', function() {
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
    
    // Confirm delete action
    function confirmDelete(id, invoiceNumber, search, customerId, status, paymentType, fromDate, toDate, page) {
        Swal.fire({
            title: 'Delete Invoice?',
            html: `Are you sure you want to delete invoice <strong>#${invoiceNumber}</strong>?<br><br>
                   <span class="text-danger">This action cannot be undone!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Deleting...',
                    text: 'Please wait while we delete the invoice',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                window.location.href = `invoices.php?delete=1&id=${id}&customer_id=${customerId}&search=${encodeURIComponent(search)}&status=${status}&payment_type=${paymentType}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Confirm status change
    function confirmStatusChange(action, id, invoiceNumber, search, customerId, status, paymentType, fromDate, toDate, page) {
        let title, text, icon, confirmButtonColor;
        
        switch(action) {
            case 'cancelled':
                title = 'Cancel Invoice?';
                text = `Are you sure you want to cancel invoice <strong>#${invoiceNumber}</strong>?`;
                icon = 'warning';
                confirmButtonColor = '#f46a6a';
                break;
            case 'paid':
                title = 'Mark as Paid?';
                text = `Are you sure you want to mark invoice <strong>#${invoiceNumber}</strong> as paid?`;
                icon = 'success';
                confirmButtonColor = '#34c38f';
                break;
            default:
                title = 'Change Invoice Status?';
                text = `Are you sure you want to change the status of invoice <strong>#${invoiceNumber}</strong>?`;
                icon = 'question';
                confirmButtonColor = '#556ee6';
        }
        
        Swal.fire({
            title: title,
            html: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: confirmButtonColor,
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `invoices.php?action=${action}&id=${id}&customer_id=${customerId}&search=${encodeURIComponent(search)}&status=${status}&payment_type=${paymentType}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + I for new invoice
        if (e.altKey && e.key === 'i') {
            e.preventDefault();
            window.location.href = 'create-invoice.php';
        }
        
        // Ctrl + F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.querySelector('input[name="search"]')?.focus();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value !== '') {
                searchInput.value = '';
                document.getElementById('filterForm').submit();
            }
        }
    });
</script>

</body>
</html>