<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';


// Get order ID from URL
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$order_id) {
    header("Location: orders.php");
    exit();
}

// Get order details
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               c.name as customer_name, 
               c.customer_code, 
               c.phone, 
               c.email, 
               c.gst_number, 
               c.address, 
               c.city, 
               c.state, 
               c.pincode,
               u1.full_name as created_by_name,
               u2.full_name as updated_by_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u1 ON o.created_by = u1.id
        LEFT JOIN users u2 ON o.updated_by = u2.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: orders.php");
        exit();
    }

    // Get order items
    $itemsStmt = $pdo->prepare("
        SELECT oi.*, 
               p.name as product_name, 
               p.unit, 
               g.gst_rate, 
               g.hsn_code
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN gst_details g ON oi.gst_id = g.id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get invoice references if any
    $invoicesStmt = $pdo->prepare("
        SELECT id, invoice_number, invoice_date, total_amount, status
        FROM invoices 
        WHERE order_id = ?
        ORDER BY created_at DESC
    ");
    $invoicesStmt->execute([$order_id]);
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get company settings
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

} catch (Exception $e) {
    error_log("Error fetching order: " . $e->getMessage());
    header("Location: orders.php");
    exit();
}

// Check for success message
$show_success = isset($_GET['success']) && $_GET['success'] == 1;

// Helper function to convert number to words
function numberToWords($number) {
    $hyphen      = '-';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = array(
        0                   => 'Zero',
        1                   => 'One',
        2                   => 'Two',
        3                   => 'Three',
        4                   => 'Four',
        5                   => 'Five',
        6                   => 'Six',
        7                   => 'Seven',
        8                   => 'Eight',
        9                   => 'Nine',
        10                  => 'Ten',
        11                  => 'Eleven',
        12                  => 'Twelve',
        13                  => 'Thirteen',
        14                  => 'Fourteen',
        15                  => 'Fifteen',
        16                  => 'Sixteen',
        17                  => 'Seventeen',
        18                  => 'Eighteen',
        19                  => 'Nineteen',
        20                  => 'Twenty',
        30                  => 'Thirty',
        40                  => 'Forty',
        50                  => 'Fifty',
        60                  => 'Sixty',
        70                  => 'Seventy',
        80                  => 'Eighty',
        90                  => 'Ninety',
        100                 => 'Hundred',
        1000                => 'Thousand',
        1000000             => 'Million',
        1000000000          => 'Billion',
        1000000000000       => 'Trillion',
        1000000000000000    => 'Quadrillion',
        1000000000000000000 => 'Quintillion'
    );
    
    if (!is_numeric($number)) {
        return false;
    }
    
    $numberStr = (string)$number;
    
    if (strpos($numberStr, '.') !== false) {
        list($whole, $fraction) = explode('.', $numberStr);
        $whole = (int)$whole;
        $fraction = (int)substr($fraction, 0, 2);
    } else {
        $whole = (int)$number;
        $fraction = 0;
    }
    
    if ($whole < 0) {
        return $negative . numberToWords(abs($whole));
    }
    
    $string = '';
    
    switch (true) {
        case $whole < 21:
            $string = $dictionary[$whole];
            break;
        case $whole < 100:
            $tens   = ((int)($whole / 10)) * 10;
            $units  = $whole % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $whole < 1000:
            $hundreds  = (int)($whole / 100);
            $remainder = $whole % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . numberToWords($remainder);
            }
            break;
        default:
            $baseUnit = pow(1000, floor(log($whole, 1000)));
            $numBaseUnits = (int)($whole / $baseUnit);
            $remainder = $whole % $baseUnit;
            $string = numberToWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= numberToWords($remainder);
            }
            break;
    }
    
    if ($fraction > 0) {
        $string .= ' and ';
        if ($fraction < 10) {
            $string .= $dictionary[$fraction] . ' Paise';
        } elseif ($fraction < 20) {
            $string .= $dictionary[$fraction] . ' Paise';
        } else {
            $tens = ((int)($fraction / 10)) * 10;
            $units = $fraction % 10;
            $paiseStr = $dictionary[$tens];
            if ($units) {
                $paiseStr .= $hyphen . $dictionary[$units];
            }
            $string .= $paiseStr . ' Paise';
        }
    } else {
        $string .= ' Rupees Only';
    }
    
    return $string;
}

// Calculate delivery status
$delivery_status = $order['status'];
$delivery_progress = 0;
switch ($order['status']) {
    case 'pending':
        $delivery_progress = 0;
        break;
    case 'confirmed':
        $delivery_progress = 25;
        break;
    case 'processing':
        $delivery_progress = 50;
        break;
    case 'delivered':
        $delivery_progress = 100;
        break;
    case 'cancelled':
        $delivery_progress = 0;
        break;
}
?>
<!DOCTYPE html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                            <h4 class="mb-0 font-size-18">Order #<?= htmlspecialchars($order['order_number']) ?></h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="orders.php">Orders</a></li>
                                    <li class="breadcrumb-item active">View Order</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success Message -->
                <?php if ($show_success): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i>
                            Order updated successfully!
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
                                    <a href="print-order.php?id=<?= $order_id ?>" target="_blank" class="btn btn-primary">
                                        <i class="mdi mdi-printer"></i> Print Order
                                    </a>
                                    <a href="edit-order.php?id=<?= $order_id ?>" class="btn btn-info">
                                        <i class="mdi mdi-pencil"></i> Edit Order
                                    </a>
                                    <?php if ($order['status'] != 'delivered' && $order['status'] != 'cancelled'): ?>
                                    <button type="button" class="btn btn-success" onclick="updateStatus('delivered')">
                                        <i class="mdi mdi-truck-delivery"></i> Mark as Delivered
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="updateStatus('processing')">
                                        <i class="mdi mdi-progress-clock"></i> Mark as Processing
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($order['status'] != 'cancelled' && $order['status'] != 'delivered'): ?>
                                    <button type="button" class="btn btn-danger" onclick="updateStatus('cancelled')">
                                        <i class="mdi mdi-cancel"></i> Cancel Order
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($order['status'] == 'delivered' && empty($invoices)): ?>
                                    <a href="create-invoice.php?order_id=<?= $order_id ?>" class="btn btn-success">
                                        <i class="mdi mdi-file-document"></i> Create Invoice
                                    </a>
                                    <?php endif; ?>
                                    <a href="orders.php" class="btn btn-secondary">
                                        <i class="mdi mdi-arrow-left"></i> Back to Orders
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Status Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Order Status</p>
                                        <h4>
                                            <span class="badge bg-<?= 
                                                $order['status'] == 'delivered' ? 'success' : 
                                                ($order['status'] == 'cancelled' ? 'danger' : 
                                                ($order['status'] == 'confirmed' ? 'info' : 
                                                ($order['status'] == 'processing' ? 'warning' : 'secondary'))) 
                                            ?> p-2">
                                                <?= ucfirst($order['status']) ?>
                                            </span>
                                        </h4>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Order Date</p>
                                        <h5><?= date('d M Y', strtotime($order['order_date'])) ?></h5>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Delivery Date</p>
                                        <h5><?= $order['delivery_date'] ? date('d M Y', strtotime($order['delivery_date'])) : 'Not set' ?></h5>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Advance Paid</p>
                                        <h5 class="text-success">₹<?= number_format($order['advance_paid'], 2) ?></h5>
                                    </div>
                                </div>
                                
                                <!-- Progress Bar -->
                                <?php if ($order['status'] != 'cancelled'): ?>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Order Progress</span>
                                        <span><?= $delivery_progress ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?= 
                                            $delivery_progress == 100 ? 'success' : 
                                            ($delivery_progress >= 50 ? 'info' : 'warning') 
                                        ?>" role="progressbar" 
                                             style="width: <?= $delivery_progress ?>%"></div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1 small text-muted">
                                        <span>Pending</span>
                                        <span>Confirmed</span>
                                        <span>Processing</span>
                                        <span>Delivered</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <h5 class="card-title mb-3">Company Details</h5>
                                        <p class="mb-1"><strong><?= htmlspecialchars($settings['company_name'] ?? 'Your Company') ?></strong></p>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($settings['company_address'] ?? '')) ?></p>
                                        <p class="mb-1">GST: <?= htmlspecialchars($settings['company_gst'] ?? '') ?></p>
                                        <p class="mb-1">Phone: <?= htmlspecialchars($settings['company_phone'] ?? '') ?></p>
                                        <p class="mb-1">Email: <?= htmlspecialchars($settings['company_email'] ?? '') ?></p>
                                    </div>
                                    <div class="col-6">
                                        <h5 class="card-title mb-3">Customer Details</h5>
                                        <p class="mb-1"><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
                                        <p class="mb-1"><?= htmlspecialchars($order['customer_code']) ?></p>
                                        <p class="mb-1"><?= htmlspecialchars($order['address'] ?? '') ?></p>
                                        <p class="mb-1"><?= htmlspecialchars($order['city'] ?? '') ?>, <?= htmlspecialchars($order['state'] ?? '') ?> - <?= htmlspecialchars($order['pincode'] ?? '') ?></p>
                                        <p class="mb-1">Phone: <?= htmlspecialchars($order['phone'] ?? 'N/A') ?></p>
                                        <p class="mb-1">Email: <?= htmlspecialchars($order['email'] ?? 'N/A') ?></p>
                                        <p class="mb-1">GST: <?= htmlspecialchars($order['gst_number'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Order Items</h5>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>HSN/SAC</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-center">Delivered</th>
                                                <th class="text-center">Pending</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-center">GST %</th>
                                                <th class="text-end">GST Amount</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $subtotal = 0;
                                            $gst_total = 0;
                                            foreach ($items as $index => $item): 
                                                $item_subtotal = floatval($item['quantity']) * floatval($item['unit_price']);
                                                $item_gst = floatval($item['gst_amount']);
                                                $item_total = $item_subtotal + $item_gst;
                                                $delivered = floatval($item['delivered_quantity'] ?? 0);
                                                $pending = floatval($item['quantity']) - $delivered;
                                                
                                                $subtotal += $item_subtotal;
                                                $gst_total += $item_gst;
                                            ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                <td><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                                                <td class="text-center"><?= number_format($item['quantity'], 2) ?> <?= htmlspecialchars($item['unit'] ?? '') ?></td>
                                                <td class="text-center text-success"><?= number_format($delivered, 2) ?></td>
                                                <td class="text-center <?= $pending > 0 ? 'text-warning' : 'text-muted' ?>"><?= number_format($pending, 2) ?></td>
                                                <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                                                <td class="text-center"><?= number_format($item['gst_rate'] ?? 0, 2) ?>%</td>
                                                <td class="text-end">₹<?= number_format($item_gst, 2) ?></td>
                                                <td class="text-end">₹<?= number_format($item_total, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="9" class="text-end"><strong>Subtotal:</strong></td>
                                                <td class="text-end">₹<?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="9" class="text-end"><strong>GST Total:</strong></td>
                                                <td class="text-end">₹<?= number_format($gst_total, 2) ?></td>
                                            </tr>
                                            <?php if (floatval($order['discount_amount'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="9" class="text-end"><strong>Discount:</strong></td>
                                                <td class="text-end text-danger">-₹<?= number_format(floatval($order['discount_amount']), 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr class="table-primary">
                                                <td colspan="9" class="text-end"><strong>Total Amount:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format(floatval($order['total_amount']), 2) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td colspan="10" class="text-muted">
                                                    <strong>Amount in Words:</strong> <?= ucwords(numberToWords(floatval($order['total_amount']))) ?>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Summary -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Payment Summary</h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td>Total Amount:</td>
                                        <td class="text-end"><strong>₹<?= number_format($order['total_amount'], 2) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Advance Paid:</td>
                                        <td class="text-end text-success">₹<?= number_format($order['advance_paid'], 2) ?></td>
                                    </tr>
                                    <tr class="border-top">
                                        <td>Balance:</td>
                                        <td class="text-end <?= $order['balance_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                            <strong>₹<?= number_format($order['balance_amount'], 2) ?></strong>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Related Invoices</h5>
                                <?php if (empty($invoices)): ?>
                                <p class="text-muted text-center py-3">No invoices created for this order yet</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($invoices as $invoice): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td class="text-end">₹<?= number_format($invoice['total_amount'], 2) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $invoice['status'] == 'paid' ? 'success' : 
                                                        ($invoice['status'] == 'overdue' ? 'danger' : 'warning') 
                                                    ?>">
                                                        <?= ucfirst($invoice['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <?php if (!empty($order['notes'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="mb-2">Notes:</h6>
                                <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Delivery Progress Tracking -->
                <?php if ($order['status'] != 'cancelled' && $order['status'] != 'delivered'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Update Delivery</h5>
                                <form method="POST" action="update-delivery.php" class="row g-3">
                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                    <div class="col-md-4">
                                        <label for="delivery_date" class="form-label">Delivery Date</label>
                                        <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                                               value="<?= $order['delivery_date'] ?? date('Y-m-d', strtotime('+1 day')) ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label for="delivery_notes" class="form-label">Delivery Notes</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="delivery_notes" name="notes" 
                                                   placeholder="Enter delivery notes">
                                            <button type="submit" class="btn btn-primary">Update Delivery</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Audit Information -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row text-muted small">
                                    <div class="col-md-4">
                                        <i class="mdi mdi-clock-outline me-1"></i>
                                        Created: <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?> 
                                        by <?= htmlspecialchars($order['created_by_name'] ?? 'System') ?>
                                    </div>
                                    <?php if (!empty($order['updated_at'])): ?>
                                    <div class="col-md-4">
                                        <i class="mdi mdi-clock-edit-outline me-1"></i>
                                        Last Updated: <?= date('d M Y, h:i A', strtotime($order['updated_at'])) ?>
                                        by <?= htmlspecialchars($order['updated_by_name'] ?? 'System') ?>
                                    </div>
                                    <?php endif; ?>
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

<!-- Right Sidebar -->
<?php include('includes/rightbar.php'); ?>
<!-- /Right-bar -->

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Update status function
    function updateStatus(status) {
        let title, text, icon;
        
        switch(status) {
            case 'delivered':
                title = 'Mark as Delivered';
                text = 'Are you sure you want to mark this order as delivered?';
                icon = 'success';
                break;
            case 'processing':
                title = 'Mark as Processing';
                text = 'Are you sure you want to mark this order as processing?';
                icon = 'info';
                break;
            case 'cancelled':
                title = 'Cancel Order';
                text = 'Are you sure you want to cancel this order? This action cannot be undone.';
                icon = 'warning';
                break;
            default:
                return;
        }
        
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: '#556ee6',
            cancelButtonColor: '#f46a6a',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Make AJAX call to update status
                $.ajax({
                    url: 'update-order-status.php',
                    method: 'POST',
                    data: {
                        order_id: <?= $order_id ?>,
                        status: status
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: 'Order status updated successfully!',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to update status',
                                confirmButtonColor: '#556ee6'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while updating status',
                            confirmButtonColor: '#556ee6'
                        });
                    }
                });
            }
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
</script>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .no-print {
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

.table td {
    vertical-align: middle;
}

/* Status badges */
.badge {
    font-size: 0.9rem;
    padding: 8px 12px;
}

/* Customer details */
.card-title {
    color: #495057;
    font-weight: 600;
}

/* Progress bar */
.progress {
    background-color: #e9ecef;
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
}

/* Hover effects */
.table-hover tbody tr:hover {
    background-color: rgba(85, 110, 230, 0.05);
}

/* Delivery status colors */
.text-pending { color: #6c757d; }
.text-confirmed { color: #17a2b8; }
.text-processing { color: #ffc107; }
.text-delivered { color: #28a745; }
.text-cancelled { color: #dc3545; }

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 12px;
    }
    
    .btn-sm {
        padding: 0.2rem 0.4rem;
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