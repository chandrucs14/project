<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Check if customer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage-customers.php");
    exit();
}

$customer_id = (int)$_GET['id'];
$error = '';
$success = '';

// Get customer details
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        header("Location: manage-customers.php");
        exit();
    }

    // Get recent invoices for this customer
    $invoicesStmt = $pdo->prepare("
        SELECT id, invoice_number, invoice_date, total_amount, paid_amount, 
               (total_amount - paid_amount) as due_amount, status 
        FROM invoices 
        WHERE customer_id = ? 
        ORDER BY invoice_date DESC 
        LIMIT 5
    ");
    $invoicesStmt->execute([$customer_id]);
    $recentInvoices = $invoicesStmt->fetchAll();

    // Get recent orders for this customer
    $ordersStmt = $pdo->prepare("
        SELECT id, order_number, order_date, total_amount, advance_paid, 
               (total_amount - advance_paid) as balance, status 
        FROM orders 
        WHERE customer_id = ? 
        ORDER BY order_date DESC 
        LIMIT 5
    ");
    $ordersStmt->execute([$customer_id]);
    $recentOrders = $ordersStmt->fetchAll();

    // Get payment history
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM customer_outstanding 
        WHERE customer_id = ? 
        ORDER BY transaction_date DESC 
        LIMIT 5
    ");
    $paymentsStmt->execute([$customer_id]);
    $recentPayments = $paymentsStmt->fetchAll();

    // Get created by user info
    $userStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $userStmt->execute([$customer['created_by']]);
    $createdBy = $userStmt->fetch();

} catch (Exception $e) {
    $error = "Failed to fetch customer details: " . $e->getMessage();
    error_log("Customer view error: " . $e->getMessage());
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
                            <h4 class="mb-0 font-size-18">View Customer</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-customers.php">Customers</a></li>
                                    <li class="breadcrumb-item active">View Customer</li>
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

                <!-- Customer Profile -->
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center">
                                    <div class="avatar-lg mx-auto mb-4">
                                        <div class="avatar-title bg-soft-primary text-primary display-5 m-0 rounded-circle">
                                            <?= strtoupper(substr($customer['name'], 0, 2)) ?>
                                        </div>
                                    </div>
                                    <h5 class="mb-1"><?= htmlspecialchars($customer['name']) ?></h5>
                                    <p class="text-muted"><?= htmlspecialchars($customer['customer_code']) ?></p>
                                    
                                    <div class="mt-4">
                                        <?php if ($customer['is_active']): ?>
                                            <span class="badge bg-success font-size-12 p-2">
                                                <i class="mdi mdi-check-circle me-1"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger font-size-12 p-2">
                                                <i class="mdi mdi-close-circle me-1"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="text-muted">
                                            <p class="mb-2"><i class="mdi mdi-phone me-2"></i> Phone</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($customer['phone']) ?></h6>
                                            
                                            <p class="mb-2"><i class="mdi mdi-email me-2"></i> Email</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></h6>
                                            
                                            <?php if ($customer['alternate_phone']): ?>
                                                <p class="mb-2"><i class="mdi mdi-phone-in-talk me-2"></i> Alternate</p>
                                                <h6 class="mb-4"><?= htmlspecialchars($customer['alternate_phone']) ?></h6>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-muted">
                                            <p class="mb-2"><i class="mdi mdi-calendar me-2"></i> Joined</p>
                                            <h6 class="mb-4"><?= date('d M Y', strtotime($customer['created_at'])) ?></h6>
                                            
                                            <p class="mb-2"><i class="mdi mdi-account me-2"></i> Created By</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($createdBy['full_name'] ?? 'N/A') ?></h6>
                                            
                                            <?php if ($customer['payment_terms']): ?>
                                                <p class="mb-2"><i class="mdi mdi-calendar-clock me-2"></i> Payment Terms</p>
                                                <h6 class="mb-4"><?= $customer['payment_terms'] ?> days</h6>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="text-center">
                                    <a href="edit-customer.php?id=<?= $customer['id'] ?>" class="btn btn-primary me-2">
                                        <i class="mdi mdi-pencil me-1"></i> Edit
                                    </a>
                                    <a href="manage-customers.php" class="btn btn-secondary">
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
                                                <h4 class="mb-0">₹<?= number_format($customer['outstanding_balance'], 2) ?></h4>
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
                                                <p class="text-muted fw-medium mb-2">Credit Limit</p>
                                                <h4 class="mb-0"><?= $customer['credit_limit'] ? '₹'.number_format($customer['credit_limit'], 2) : 'No Limit' ?></h4>
                                            </div>
                                            <div class="flex-shrink-0 align-self-center">
                                                <div class="mini-stat-icon avatar-sm rounded-circle bg-success">
                                                    <span class="avatar-title">
                                                        <i class="mdi mdi-credit-card font-size-24 text-white"></i>
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
                                
                                <?php if ($customer['address'] || $customer['city'] || $customer['state'] || $customer['pincode']): ?>
                                    <div class="row">
                                        <div class="col-sm-12">
                                            <p class="mb-2"><i class="mdi mdi-map-marker me-2"></i> Address</p>
                                            <h6 class="mb-4"><?= nl2br(htmlspecialchars($customer['address'] ?? '')) ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-city me-2"></i> City</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($customer['city'] ?? 'N/A') ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-map me-2"></i> State</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($customer['state'] ?? 'N/A') ?></h6>
                                        </div>
                                        <div class="col-sm-4">
                                            <p class="mb-2"><i class="mdi mdi-mailbox me-2"></i> Pincode</p>
                                            <h6 class="mb-4"><?= htmlspecialchars($customer['pincode'] ?? 'N/A') ?></h6>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No address information available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tax Information -->
                        <?php if ($customer['gst_number'] || $customer['pan_number']): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Tax Information</h4>
                                
                                <div class="row">
                                    <?php if ($customer['gst_number']): ?>
                                    <div class="col-sm-6">
                                        <p class="mb-2"><i class="mdi mdi-card-account-details me-2"></i> GST Number</p>
                                        <h6 class="mb-4"><?= htmlspecialchars($customer['gst_number']) ?></h6>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($customer['pan_number']): ?>
                                    <div class="col-sm-6">
                                        <p class="mb-2"><i class="mdi mdi-card-account-details-outline me-2"></i> PAN Number</p>
                                        <h6 class="mb-4"><?= htmlspecialchars($customer['pan_number']) ?></h6>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Invoices -->
                <?php if (!empty($recentInvoices)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Invoices</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Date</th>
                                                <th>Total Amount</th>
                                                <th>Paid</th>
                                                <th>Due</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentInvoices as $invoice): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td>₹<?= number_format($invoice['total_amount'], 2) ?></td>
                                                <td>₹<?= number_format($invoice['paid_amount'], 2) ?></td>
                                                <td>₹<?= number_format($invoice['due_amount'], 2) ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    $statusIcon = '';
                                                    switch($invoice['status']) {
                                                        case 'paid':
                                                            $statusClass = 'success';
                                                            $statusIcon = 'check-circle';
                                                            break;
                                                        case 'partially_paid':
                                                            $statusClass = 'warning';
                                                            $statusIcon = 'clock-outline';
                                                            break;
                                                        case 'overdue':
                                                            $statusClass = 'danger';
                                                            $statusIcon = 'alert-circle';
                                                            break;
                                                        default:
                                                            $statusClass = 'secondary';
                                                            $statusIcon = 'clock';
                                                    }
                                                    ?>
                                                    <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                        <i class="mdi mdi-<?= $statusIcon ?> me-1"></i>
                                                        <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="invoices.php?customer_id=<?= $customer['id'] ?>" class="text-primary">View All Invoices <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Orders -->
                <?php if (!empty($recentOrders)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Orders</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Order #</th>
                                                <th>Date</th>
                                                <th>Total Amount</th>
                                                <th>Advance</th>
                                                <th>Balance</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentOrders as $order): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($order['order_number']) ?></td>
                                                <td><?= date('d M Y', strtotime($order['order_date'])) ?></td>
                                                <td>₹<?= number_format($order['total_amount'], 2) ?></td>
                                                <td>₹<?= number_format($order['advance_paid'], 2) ?></td>
                                                <td>₹<?= number_format($order['balance'], 2) ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    switch($order['status']) {
                                                        case 'delivered':
                                                            $statusClass = 'success';
                                                            break;
                                                        case 'processing':
                                                            $statusClass = 'info';
                                                            break;
                                                        case 'confirmed':
                                                            $statusClass = 'primary';
                                                            break;
                                                        case 'cancelled':
                                                            $statusClass = 'danger';
                                                            break;
                                                        default:
                                                            $statusClass = 'secondary';
                                                    }
                                                    ?>
                                                    <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                        <?= ucfirst($order['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="orders.php?customer_id=<?= $customer['id'] ?>" class="text-primary">View All Orders <i class="mdi mdi-arrow-right"></i></a>
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