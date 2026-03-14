<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';



// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    header("Location: invoices.php");
    exit();
}

// Get invoice details
try {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.name as customer_name, 
               c.customer_code, 
               c.phone, 
               c.email, 
               c.gst_number, 
               c.address, 
               c.city, 
               c.state, 
               c.pincode,
               c.outstanding_balance as customer_outstanding,
               c.credit_limit,
               u1.full_name as created_by_name,
               u2.full_name as updated_by_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u1 ON i.created_by = u1.id
        LEFT JOIN users u2 ON i.updated_by = u2.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        header("Location: invoices.php");
        exit();
    }

    // Get invoice items
    $itemsStmt = $pdo->prepare("
        SELECT ii.*, 
               p.name as product_name, 
               p.unit, 
               g.gst_rate, 
               g.hsn_code
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN gst_details g ON ii.gst_id = g.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ");
    $itemsStmt->execute([$invoice_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get payment history
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM customer_outstanding 
        WHERE reference_id = ? AND transaction_type = 'payment'
        ORDER BY transaction_date DESC
    ");
    $paymentsStmt->execute([$invoice_id]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get company settings
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

} catch (Exception $e) {
    error_log("Error fetching invoice: " . $e->getMessage());
    header("Location: invoices.php");
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

// Calculate days overdue
$days_overdue = 0;
if ($invoice['status'] != 'paid' && $invoice['status'] != 'cancelled') {
    $due_date = new DateTime($invoice['due_date']);
    $today = new DateTime();
    if ($due_date < $today) {
        $days_overdue = $today->diff($due_date)->days;
    }
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
                            <h4 class="mb-0 font-size-18">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="invoices.php">Invoices</a></li>
                                    <li class="breadcrumb-item active">View Invoice</li>
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
                            Invoice updated successfully!
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
                                    <a href="print-invoice.php?id=<?= $invoice_id ?>" target="_blank" class="btn btn-primary">
                                        <i class="mdi mdi-printer"></i> Print Invoice
                                    </a>
                                    <a href="edit-invoice.php?id=<?= $invoice_id ?>" class="btn btn-info">
                                        <i class="mdi mdi-pencil"></i> Edit Invoice
                                    </a>
                                    <?php if ($invoice['outstanding_amount'] > 0): ?>
                                    <button type="button" class="btn btn-success" onclick="recordPayment()">
                                        <i class="mdi mdi-cash"></i> Record Payment
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-warning" onclick="sendEmail()">
                                        <i class="mdi mdi-email"></i> Send Email
                                    </button>
                                    <a href="invoices.php" class="btn btn-secondary">
                                        <i class="mdi mdi-arrow-left"></i> Back to Invoices
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Status Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Invoice Status</p>
                                        <h4>
                                            <span class="badge bg-<?= 
                                                $invoice['status'] == 'paid' ? 'success' : 
                                                ($invoice['status'] == 'overdue' ? 'danger' : 
                                                ($invoice['status'] == 'cancelled' ? 'secondary' : 
                                                ($invoice['status'] == 'partially_paid' ? 'info' : 'warning'))) 
                                            ?> p-2">
                                                <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                            </span>
                                        </h4>
                                        <?php if ($days_overdue > 0): ?>
                                        <p class="text-danger mt-2">
                                            <i class="mdi mdi-alert-circle"></i> Overdue by <?= $days_overdue ?> days
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Invoice Date</p>
                                        <h5><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></h5>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Due Date</p>
                                        <h5 class="<?= $days_overdue > 0 ? 'text-danger' : '' ?>">
                                            <?= date('d M Y', strtotime($invoice['due_date'])) ?>
                                        </h5>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="text-muted mb-1">Payment Type</p>
                                        <h5><?= ucfirst(str_replace('_', ' ', $invoice['payment_type'])) ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Details -->
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
                                        <p class="mb-1"><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
                                        <p class="mb-1"><?= htmlspecialchars($invoice['customer_code']) ?></p>
                                        <p class="mb-1"><?= htmlspecialchars($invoice['address'] ?? '') ?></p>
                                        <p class="mb-1"><?= htmlspecialchars($invoice['city'] ?? '') ?>, <?= htmlspecialchars($invoice['state'] ?? '') ?> - <?= htmlspecialchars($invoice['pincode'] ?? '') ?></p>
                                        <p class="mb-1">Phone: <?= htmlspecialchars($invoice['phone'] ?? 'N/A') ?></p>
                                        <p class="mb-1">Email: <?= htmlspecialchars($invoice['email'] ?? 'N/A') ?></p>
                                        <p class="mb-1">GST: <?= htmlspecialchars($invoice['gst_number'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Items -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Invoice Items</h5>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>HSN/SAC</th>
                                                <th class="text-center">Quantity</th>
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
                                                
                                                $subtotal += $item_subtotal;
                                                $gst_total += $item_gst;
                                            ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                <td><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                                                <td class="text-center"><?= number_format($item['quantity'], 2) ?> <?= htmlspecialchars($item['unit'] ?? '') ?></td>
                                                <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                                                <td class="text-center"><?= number_format($item['gst_rate'] ?? 0, 2) ?>%</td>
                                                <td class="text-end">₹<?= number_format($item_gst, 2) ?></td>
                                                <td class="text-end">₹<?= number_format($item_total, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>Subtotal:</strong></td>
                                                <td class="text-end">₹<?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>GST Total:</strong></td>
                                                <td class="text-end">₹<?= number_format($gst_total, 2) ?></td>
                                            </tr>
                                            <?php if (floatval($invoice['discount_amount'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>Discount:</strong></td>
                                                <td class="text-end text-danger">-₹<?= number_format(floatval($invoice['discount_amount']), 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr class="table-primary">
                                                <td colspan="7" class="text-end"><strong>Total Amount:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format(floatval($invoice['total_amount']), 2) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td colspan="8" class="text-muted">
                                                    <strong>Amount in Words:</strong> <?= ucwords(numberToWords(floatval($invoice['total_amount']))) ?>
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
                                        <td class="text-end"><strong>₹<?= number_format($invoice['total_amount'], 2) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Paid Amount:</td>
                                        <td class="text-end text-success">₹<?= number_format($invoice['paid_amount'], 2) ?></td>
                                    </tr>
                                    <tr class="border-top">
                                        <td>Outstanding:</td>
                                        <td class="text-end text-danger"><strong>₹<?= number_format($invoice['outstanding_amount'], 2) ?></strong></td>
                                    </tr>
                                </table>
                                
                                <?php if ($invoice['outstanding_amount'] > 0): ?>
                                <div class="progress mt-2" style="height: 8px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?= ($invoice['paid_amount'] / $invoice['total_amount']) * 100 ?>%"></div>
                                </div>
                                <p class="text-muted small mt-1">
                                    <?= number_format(($invoice['paid_amount'] / $invoice['total_amount']) * 100, 1) ?>% Paid
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Payment History</h5>
                                <?php if (empty($payments)): ?>
                                <p class="text-muted text-center py-3">No payments recorded yet</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-end">Balance After</th>
                                                <th>Reference</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($payment['transaction_date'])) ?></td>
                                                <td class="text-end text-success">₹<?= number_format($payment['amount'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format($payment['balance_after'], 2) ?></td>
                                                <td><?= htmlspecialchars($payment['reference_id'] ?? '-') ?></td>
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

                <!-- Notes and Terms -->
                <?php if (!empty($invoice['notes']) || !empty($invoice['terms'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <?php if (!empty($invoice['notes'])): ?>
                                <h6 class="mb-2">Notes:</h6>
                                <p class="text-muted mb-3"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($invoice['terms'])): ?>
                                <h6 class="mb-2">Terms & Conditions:</h6>
                                <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($invoice['terms'])) ?></p>
                                <?php endif; ?>
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
                                        Created: <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?> 
                                        by <?= htmlspecialchars($invoice['created_by_name'] ?? 'System') ?>
                                    </div>
                                    <?php if (!empty($invoice['updated_at'])): ?>
                                    <div class="col-md-4">
                                        <i class="mdi mdi-clock-edit-outline me-1"></i>
                                        Last Updated: <?= date('d M Y, h:i A', strtotime($invoice['updated_at'])) ?>
                                        by <?= htmlspecialchars($invoice['updated_by_name'] ?? 'System') ?>
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

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paymentForm" method="POST" action="record-payment.php">
                <div class="modal-body">
                    <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                    <input type="hidden" name="customer_id" value="<?= $invoice['customer_id'] ?>">
                    
                    <div class="mb-3">
                        <label for="payment_date" class="form-label">Payment Date</label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_amount" class="form-label">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="payment_amount" name="amount" 
                                   value="<?= $invoice['outstanding_amount'] ?>" min="0.01" 
                                   max="<?= $invoice['outstanding_amount'] ?>" step="0.01" required>
                        </div>
                        <small class="text-muted">Outstanding: ₹<?= number_format($invoice['outstanding_amount'], 2) ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-control" id="payment_method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online Payment</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_reference" class="form-label">Reference (Optional)</label>
                        <input type="text" class="form-control" id="payment_reference" name="reference" 
                               placeholder="Cheque/Transaction number">
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="payment_notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Record payment function
    function recordPayment() {
        $('#paymentModal').modal('show');
    }

    // Send email function
    function sendEmail() {
        Swal.fire({
            title: 'Send Invoice via Email',
            html: `
                <div class="mb-3 text-start">
                    <label class="form-label">Email Address</label>
                    <input type="email" id="email" class="form-control" value="<?= htmlspecialchars($invoice['email'] ?? '') ?>" placeholder="customer@example.com">
                </div>
                <div class="mb-3 text-start">
                    <label class="form-label">Subject</label>
                    <input type="text" id="subject" class="form-control" value="Invoice #<?= $invoice['invoice_number'] ?>" placeholder="Subject">
                </div>
                <div class="mb-3 text-start">
                    <label class="form-label">Message (Optional)</label>
                    <textarea id="message" class="form-control" rows="3" placeholder="Enter additional message..."></textarea>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Send Email',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#556ee6',
            preConfirm: () => {
                const email = document.getElementById('email').value;
                const subject = document.getElementById('subject').value;
                const message = document.getElementById('message').value;
                
                if (!email) {
                    Swal.showValidationMessage('Please enter an email address');
                    return false;
                }
                
                return { email, subject, message };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Here you would make an AJAX call to send the email
                Swal.fire({
                    icon: 'success',
                    title: 'Email Sent!',
                    text: 'Invoice has been sent to ' + result.value.email,
                    timer: 3000,
                    showConfirmButton: false
                });
            }
        });
    }

    // Payment form submission
    $('#paymentForm').submit(function(e) {
        e.preventDefault();
        
        const amount = parseFloat($('#payment_amount').val());
        const maxAmount = <?= $invoice['outstanding_amount'] ?>;
        
        if (amount > maxAmount) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Payment amount cannot exceed outstanding amount of ₹' + maxAmount.toFixed(2),
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        // Submit form via AJAX
        $.ajax({
            url: 'record-payment.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Recorded',
                        text: 'Payment has been recorded successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to record payment',
                        confirmButtonColor: '#556ee6'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while recording payment',
                    confirmButtonColor: '#556ee6'
                });
            }
        });
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

/* Payment progress bar */
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

/* Modal customization */
.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
}
</style>

</body>
</html>