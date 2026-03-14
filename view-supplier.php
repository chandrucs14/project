<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Check if supplier ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage-suppliers.php");
    exit();
}

$supplier_id = (int)$_GET['id'];
$error = '';
$success = '';

// Get supplier details
try {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        header("Location: manage-suppliers.php");
        exit();
    }

    // Get recent purchase orders for this supplier
    $poStmt = $pdo->prepare("
        SELECT id, po_number, order_date, total_amount, status 
        FROM purchase_orders 
        WHERE supplier_id = ? 
        ORDER BY order_date DESC 
        LIMIT 5
    ");
    $poStmt->execute([$supplier_id]);
    $recentPOs = $poStmt->fetchAll();

    // Get recent expenses for this supplier
    $expenseStmt = $pdo->prepare("
        SELECT id, expense_number, expense_date, amount, payment_method, category 
        FROM expenses 
        WHERE supplier_id = ? 
        ORDER BY expense_date DESC 
        LIMIT 5
    ");
    $expenseStmt->execute([$supplier_id]);
    $recentExpenses = $expenseStmt->fetchAll();

    // Get outstanding records
    $outstandingStmt = $pdo->prepare("
        SELECT * FROM supplier_outstanding 
        WHERE supplier_id = ? 
        ORDER BY transaction_date DESC 
        LIMIT 5
    ");
    $outstandingStmt->execute([$supplier_id]);
    $recentOutstanding = $outstandingStmt->fetchAll();

    // Get created by user info
    $userStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $userStmt->execute([$supplier['created_by']]);
    $createdBy = $userStmt->fetch();

    // Get updated by user info if available
    $updatedBy = null;
    if ($supplier['updated_by']) {
        $updatedStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $updatedStmt->execute([$supplier['updated_by']]);
        $updatedBy = $updatedStmt->fetch();
    }

} catch (Exception $e) {
    $error = "Failed to fetch supplier details: " . $e->getMessage();
    error_log("Supplier view error: " . $e->getMessage());
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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
                            <h4 class="mb-0 font-size-18">View Supplier</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-suppliers.php">Suppliers</a></li>
                                    <li class="breadcrumb-item active">View Supplier</li>
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

                <!-- Supplier Profile -->
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="avatar-lg mx-auto mb-4">
                                        <div class="avatar-title bg-soft-primary text-primary display-5 m-0 rounded-circle">
                                            <?= strtoupper(substr($supplier['name'], 0, 2)) ?>
                                        </div>
                                    </div>
                                    <h5 class="mb-1"><?= htmlspecialchars($supplier['name']) ?></h5>
                                    <p class="text-muted"><?= htmlspecialchars($supplier['supplier_code']) ?></p>
                                    
                                    <div class="mt-4">
                                        <?php if ($supplier['is_active']): ?>
                                            <span class="badge bg-success font-size-12 p-2">
                                                <i class="mdi mdi-check-circle me-1"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger font-size-12 p-2">
                                                <i class="mdi mdi-close-circle me-1"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($supplier['company_name']): ?>
                                            <span class="badge bg-info font-size-12 p-2 ms-1">
                                                <i class="mdi mdi-domain me-1"></i> <?= htmlspecialchars($supplier['company_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="text-muted">
                                            <p class="mb-2"><i class="mdi mdi-phone me-2"></i> Phone</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($supplier['phone']) ?></h6>
                                            
                                            <p class="mb-2"><i class="mdi mdi-email me-2"></i> Email</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($supplier['email'] ?? 'N/A') ?></h6>
                                            
                                            <?php if ($supplier['alternate_phone']): ?>
                                                <p class="mb-2"><i class="mdi mdi-phone-in-talk me-2"></i> Alternate</p>
                                                <h6 class="mb-4"><?= htmlspecialchars($supplier['alternate_phone']) ?></h6>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-muted">
                                            <p class="mb-2"><i class="mdi mdi-calendar me-2"></i> Joined</p>
                                            <h6 class="mb-4"><?= date('d M Y', strtotime($supplier['created_at'])) ?></h6>
                                            
                                            <p class="mb-2"><i class="mdi mdi-account me-2"></i> Created By</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($createdBy['full_name'] ?? 'N/A') ?></h6>
                                            
                                            <?php if ($updatedBy): ?>
                                                <p class="mb-2"><i class="mdi mdi-account-edit me-2"></i> Last Updated By</p>
                                                <h6 class="mb-4"><?= htmlspecialchars($updatedBy['full_name']) ?></h6>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="text-center">
                                    <a href="edit-supplier.php?id=<?= $supplier['id'] ?>" class="btn btn-primary me-2">
                                        <i class="mdi mdi-pencil me-1"></i> Edit
                                    </a>
                                    <a href="manage-suppliers.php" class="btn btn-secondary">
                                        <i class="mdi mdi-arrow-left me-1"></i> Back
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <!-- Financial Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mini-stats-wid">
                                    <div class="card-body">
                                        <div class="d-flex">
                                            <div class="flex-grow-1">
                                                <p class="text-muted fw-medium mb-2">Outstanding Balance</p>
                                                <h4 class="mb-0 <?= $supplier['outstanding_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                                    ₹<?= number_format($supplier['outstanding_balance'], 2) ?>
                                                </h4>
                                            </div>
                                            <div class="flex-shrink-0 align-self-center">
                                                <div class="mini-stat-icon avatar-sm rounded-circle bg-primary">
                                                    <span class="avatar-title">
                                                        <i class="mdi mdi-currency-inr font-size-24 text-white"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mini-stats-wid">
                                    <div class="card-body">
                                        <div class="d-flex">
                                            <div class="flex-grow-1">
                                                <p class="text-muted fw-medium mb-2">Payment Terms</p>
                                                <h4 class="mb-0">
                                                    <?= $supplier['payment_terms'] ? $supplier['payment_terms'] . ' days' : 'Not set' ?>
                                                </h4>
                                            </div>
                                            <div class="flex-shrink-0 align-self-center">
                                                <div class="mini-stat-icon avatar-sm rounded-circle bg-success">
                                                    <span class="avatar-title">
                                                        <i class="mdi mdi-calendar-clock font-size-24 text-white"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Address Information</h4>
                                
                                <?php if ($supplier['address'] || $supplier['city'] || $supplier['state'] || $supplier['pincode']): ?>
                                    <div class="row">
                                        <div class="col-sm-12">
                                            <p class="mb-2"><i class="mdi mdi-map-marker me-2"></i> Address</p>
                                            <h6 class="mb-4"><?= nl2br(htmlspecialchars($supplier['address'] ?? '')) ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-city me-2"></i> City</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($supplier['city'] ?? 'N/A') ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-map me-2"></i> State</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($supplier['state'] ?? 'N/A') ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-mailbox me-2"></i> Pincode</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($supplier['pincode'] ?? 'N/A') ?></h6>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No address information available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tax Information -->
                        <?php if ($supplier['gst_number'] || $supplier['pan_number']): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Tax Information</h4>
                                
                                <div class="row">
                                    <?php if ($supplier['gst_number']): ?>
                                    <div class="col-sm-6">
                                        <p class="mb-2"><i class="mdi mdi-card-account-details me-2"></i> GST Number</p>
                                        <h6 class="mb-4"><?= htmlspecialchars($supplier['gst_number']) ?></h6>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($supplier['pan_number']): ?>
                                    <div class="col-sm-6">
                                        <p class="mb-2"><i class="mdi mdi-card-account-details-outline me-2"></i> PAN Number</p>
                                        <h6 class="mb-4"><?= htmlspecialchars($supplier['pan_number']) ?></h6>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Purchase Orders -->
                <?php if (!empty($recentPOs)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Purchase Orders</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>PO Number</th>
                                                <th>Date</th>
                                                <th>Total Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentPOs as $po): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($po['po_number']) ?></td>
                                                <td><?= date('d M Y', strtotime($po['order_date'])) ?></td>
                                                <td>₹<?= number_format($po['total_amount'], 2) ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    switch($po['status']) {
                                                        case 'completed':
                                                            $statusClass = 'success';
                                                            break;
                                                        case 'confirmed':
                                                            $statusClass = 'primary';
                                                            break;
                                                        case 'sent':
                                                            $statusClass = 'info';
                                                            break;
                                                        case 'draft':
                                                            $statusClass = 'secondary';
                                                            break;
                                                        case 'cancelled':
                                                            $statusClass = 'danger';
                                                            break;
                                                        default:
                                                            $statusClass = 'warning';
                                                    }
                                                    ?>
                                                    <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $po['status'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view-purchase-order.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="purchase-orders.php?supplier_id=<?= $supplier['id'] ?>" class="text-primary">View All Purchase Orders <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Expenses -->
                <?php if (!empty($recentExpenses)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Expenses</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Expense #</th>
                                                <th>Date</th>
                                                <th>Category</th>
                                                <th>Amount</th>
                                                <th>Payment Method</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentExpenses as $expense): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($expense['expense_number']) ?></td>
                                                <td><?= date('d M Y', strtotime($expense['expense_date'])) ?></td>
                                                <td><?= htmlspecialchars($expense['category']) ?></td>
                                                <td>₹<?= number_format($expense['amount'], 2) ?></td>
                                                <td>
                                                    <span class="badge bg-soft-info text-info">
                                                        <?= ucfirst($expense['payment_method']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view-expense.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="expenses.php?supplier_id=<?= $supplier['id'] ?>" class="text-primary">View All Expenses <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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

</body>
</html>