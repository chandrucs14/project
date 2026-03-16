<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    header("Location: invoices.php");
    exit();
}

// Get invoice details
try {
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as customer_name, c.customer_code, c.phone, c.email, c.gst_number, c.address, c.city, c.state, c.pincode,
               u.full_name as created_by_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON i.created_by = u.id
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
        SELECT ii.*, p.name as product_name, p.unit, g.gst_rate, g.hsn_code
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.id
        LEFT JOIN gst_details g ON ii.gst_id = g.id
        WHERE ii.invoice_id = ?
    ");
    $itemsStmt->execute([$invoice_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get company settings from gst_settings table
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM gst_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Also get invoice settings for backward compatibility
    $invoiceSettingsStmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
    while ($row = $invoiceSettingsStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($settings[$row['setting_key']])) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

} catch (Exception $e) {
    error_log("Error fetching invoice: " . $e->getMessage());
    header("Location: invoices.php");
    exit();
}

// Check for success message
$show_success = isset($_GET['success']) && $_GET['success'] == 1;

// Helper function to convert number to words (fixed for Indian currency)
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
        100000              => 'Lakh',
        10000000            => 'Crore'
    );
    
    if (!is_numeric($number)) {
        return false;
    }
    
    // Handle float numbers for currency
    $numberStr = (string)number_format($number, 2, '.', '');
    
    if (strpos($numberStr, '.') !== false) {
        list($whole, $fraction) = explode('.', $numberStr);
        $whole = (int)$whole;
        $fraction = (int)$fraction;
    } else {
        $whole = (int)$number;
        $fraction = 0;
    }
    
    if ($whole < 0) {
        return $negative . numberToWords(abs($whole));
    }
    
    $string = '';
    
    // Handle Indian numbering system (Crores, Lakhs, Thousands)
    if ($whole >= 10000000) { // Crores
        $crores = floor($whole / 10000000);
        $remainder = $whole % 10000000;
        $string .= numberToWords($crores) . ' Crore';
        if ($remainder > 0) {
            $string .= ' ';
        }
        $whole = $remainder;
    }
    
    if ($whole >= 100000) { // Lakhs
        $lakhs = floor($whole / 100000);
        $remainder = $whole % 100000;
        $string .= numberToWords($lakhs) . ' Lakh';
        if ($remainder > 0) {
            $string .= ' ';
        }
        $whole = $remainder;
    }
    
    if ($whole >= 1000) { // Thousands
        $thousands = floor($whole / 1000);
        $remainder = $whole % 1000;
        $string .= numberToWords($thousands) . ' Thousand';
        if ($remainder > 0) {
            $string .= ' ';
        }
        $whole = $remainder;
    }
    
    if ($whole >= 100) { // Hundreds
        $hundreds = floor($whole / 100);
        $remainder = $whole % 100;
        $string .= numberToWords($hundreds) . ' Hundred';
        if ($remainder > 0) {
            $string .= ' and ';
        }
        $whole = $remainder;
    }
    
    if ($whole > 0) {
        if ($whole < 21) {
            $string .= $dictionary[$whole];
        } elseif ($whole < 100) {
            $tens   = floor($whole / 10) * 10;
            $units  = $whole % 10;
            $string .= $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
        }
    }
    
    // Add paise for fractional part
    if ($fraction > 0) {
        $string .= ' and ';
        if ($fraction < 10) {
            $string .= $dictionary[$fraction] . ' Paise';
        } elseif ($fraction < 20) {
            $string .= $dictionary[$fraction] . ' Paise';
        } else {
            $tens = floor($fraction / 10) * 10;
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

// Function to get logo path
function getCompanyLogo($settings) {
    if (!empty($settings['company_logo']) && file_exists($settings['company_logo'])) {
        return $settings['company_logo'];
    }
    return 'assets/images/default-logo.png'; // Fallback default logo
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .invoice-container {
            max-width: 1000px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 2px solid #556ee6;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .company-logo {
            max-height: 80px;
            max-width: 200px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .company-details {
            color: #333;
        }
        .company-name {
            color: #556ee6;
            font-weight: bold;
            font-size: 24px;
        }
        .invoice-title {
            color: #556ee6;
            font-weight: bold;
        }
        .customer-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .table th {
            background-color: #556ee6;
            color: white;
            font-weight: 500;
        }
        .table td {
            vertical-align: middle;
        }
        .total-row {
            font-weight: bold;
            background-color: #e9ecef;
        }
        .grand-total {
            font-size: 1.2rem;
            color: #556ee6;
        }
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #dee2e6;
            text-align: center;
            color: #6c757d;
        }
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        .success-alert {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            min-width: 300px;
            text-align: center;
            animation: slideDown 0.5s ease;
        }
        @keyframes slideDown {
            from {
                top: -100px;
                opacity: 0;
            }
            to {
                top: 20px;
                opacity: 1;
            }
        }
        .badge {
            padding: 5px 10px;
            font-size: 12px;
        }
        .amount-in-words {
            font-size: 14px;
            color: #495057;
            font-style: italic;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-top: 10px;
        }
        .gst-breakup {
            font-size: 12px;
            color: #6c757d;
            margin-top: 2px;
        }
        .company-logo-container {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        .tax-breakup-table {
            width: 100%;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        .tax-breakup-table td {
            padding: 3px 0;
        }
        @media print {
            .print-btn, .success-alert, .back-btn {
                display: none !important;
            }
            body {
                background-color: white;
                padding: 20px;
            }
            .invoice-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
            .table th {
                background-color: #f2f2f2 !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .badge {
                border: 1px solid #000;
                color: #000 !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>
    <?php if ($show_success): ?>
    <div class="alert alert-success alert-dismissible fade show success-alert" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        Invoice created successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="back-btn">
        <a href="invoices.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Invoices
        </a>
    </div>

    <div class="print-btn">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer"></i> Print Invoice
        </button>
    </div>

    <div class="invoice-container">
        <!-- Invoice Header with Logo -->
        <div class="invoice-header">
            <div class="row">
                <div class="col-6">
                    <?php 
                    $logo_path = getCompanyLogo($settings);
                    if (!empty($logo_path) && file_exists($logo_path)): 
                    ?>
                    <div class="company-logo-container mb-2">
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="Company Logo" class="company-logo">
                    </div>
                    <?php endif; ?>
                    <div class="company-details">
                        <div class="company-name"><?= htmlspecialchars($settings['company_name'] ?? 'Your Company Name') ?></div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($settings['company_address'] ?? '')) ?></p>
                        <?php if (!empty($settings['company_city']) || !empty($settings['company_state']) || !empty($settings['company_pincode'])): ?>
                        <p class="mb-0">
                            <?= htmlspecialchars($settings['company_city'] ?? '') ?> 
                            <?= !empty($settings['company_city']) && (!empty($settings['company_state']) || !empty($settings['company_pincode'])) ? ', ' : '' ?>
                            <?= htmlspecialchars($settings['company_state'] ?? '') ?> 
                            <?= !empty($settings['company_pincode']) ? ' - ' . htmlspecialchars($settings['company_pincode']) : '' ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($settings['company_phone'])): ?>
                        <p class="mb-0"><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($settings['company_phone']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($settings['company_email'])): ?>
                        <p class="mb-0"><i class="bi bi-envelope me-1"></i> <?= htmlspecialchars($settings['company_email']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($settings['company_gst']) && (!isset($settings['show_gst']) || $settings['show_gst'] != '0')): ?>
                        <p class="mb-0"><i class="bi bi-building me-1"></i> GST: <?= htmlspecialchars($settings['company_gst']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($settings['company_pan'])): ?>
                        <p class="mb-0"><i class="bi bi-card-text me-1"></i> PAN: <?= htmlspecialchars($settings['company_pan']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6 text-end">
                    <h2 class="invoice-title">TAX INVOICE</h2>
                    <p class="mb-0"><strong>Invoice No:</strong> <?= htmlspecialchars($invoice['invoice_number']) ?></p>
                    <p class="mb-0"><strong>Date:</strong> <?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?></p>
                    <p class="mb-0"><strong>Due Date:</strong> <?= date('d-m-Y', strtotime($invoice['due_date'])) ?></p>
                    <p class="mb-0"><strong>Payment Type:</strong> <?= ucfirst(str_replace('_', ' ', $invoice['payment_type'])) ?></p>
                    <p class="mb-0"><strong>Status:</strong> 
                        <span class="badge bg-<?= $invoice['status'] == 'paid' ? 'success' : ($invoice['status'] == 'overdue' ? 'danger' : 'warning') ?>">
                            <?= ucfirst($invoice['status']) ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Customer Details -->
        <div class="customer-details">
            <div class="row">
                <div class="col-12">
                    <h5><i class="bi bi-person-bounding-box me-2"></i> Bill To:</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['customer_code']) ?></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['address'] ?? '') ?></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['city'] ?? '') ?><?= !empty($invoice['city']) && (!empty($invoice['state']) || !empty($invoice['pincode'])) ? ', ' : '' ?><?= htmlspecialchars($invoice['state'] ?? '') ?> <?= !empty($invoice['pincode']) ? ' - ' . htmlspecialchars($invoice['pincode']) : '' ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($invoice['phone'] ?? 'N/A') ?></p>
                            <p class="mb-1"><i class="bi bi-envelope me-1"></i> <?= htmlspecialchars($invoice['email'] ?? 'N/A') ?></p>
                            <?php if (!empty($invoice['gst_number']) && (!isset($settings['show_gst']) || $settings['show_gst'] != '0')): ?>
                            <p class="mb-1"><i class="bi bi-building me-1"></i> GST: <?= htmlspecialchars($invoice['gst_number']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Items -->
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="25%">Description</th>
                        <?php if (!isset($settings['show_hsn']) || $settings['show_hsn'] != '0'): ?>
                        <th width="10%">HSN/SAC</th>
                        <?php endif; ?>
                        <th width="8%">Qty</th>
                        <th width="8%">Unit</th>
                        <th width="10%">Unit Price</th>
                        <?php if (!isset($settings['show_gst']) || $settings['show_gst'] != '0'): ?>
                        <th width="8%">GST %</th>
                        <th width="6%">CGST</th>
                        <th width="6%">SGST</th>
                        <th width="10%">Total</th>
                        <?php else: ?>
                        <th width="12%">Total</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $subtotal = 0;
                    $gst_total = 0;
                    $cgst_total = 0;
                    $sgst_total = 0;
                    
                    foreach ($items as $index => $item): 
                        $quantity = floatval($item['quantity']);
                        $unit_price = floatval($item['unit_price']);
                        $item_subtotal = $quantity * $unit_price;
                        $item_gst = floatval($item['gst_amount']);
                        $item_total = $item_subtotal + $item_gst;
                        
                        $gst_rate = floatval($item['gst_rate'] ?? 0);
                        $cgst = $gst_rate / 2;
                        $sgst = $gst_rate / 2;
                        $cgst_amount = $item_gst / 2;
                        $sgst_amount = $item_gst / 2;
                        
                        $subtotal += $item_subtotal;
                        $gst_total += $item_gst;
                        $cgst_total += $cgst_amount;
                        $sgst_total += $sgst_amount;
                    ?>
                    <tr>
                        <td class="text-center"><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <?php if (!isset($settings['show_hsn']) || $settings['show_hsn'] != '0'): ?>
                        <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <td class="text-center"><?= number_format($quantity, 2) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['unit'] ?? 'Nos') ?></td>
                        <td class="text-end">₹<?= number_format($unit_price, 2) ?></td>
                        <?php if (!isset($settings['show_gst']) || $settings['show_gst'] != '0'): ?>
                        <td class="text-center"><?= number_format($gst_rate, 2) ?>%</td>
                        <td class="text-end">₹<?= number_format($cgst_amount, 2) ?></td>
                        <td class="text-end">₹<?= number_format($sgst_amount, 2) ?></td>
                        <td class="text-end">₹<?= number_format($item_total, 2) ?></td>
                        <?php else: ?>
                        <td class="text-end">₹<?= number_format($item_total, 2) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php 
                    $colspan_left = 5 + (!isset($settings['show_hsn']) || $settings['show_hsn'] != '0' ? 1 : 0);
                    $colspan_middle = (!isset($settings['show_gst']) || $settings['show_gst'] != '0' ? 3 : 1);
                    ?>
                    <tr>
                        <td colspan="<?= $colspan_left ?>" class="text-end"><strong>Subtotal:</strong></td>
                        <td class="text-end" colspan="<?= $colspan_middle ?>"><strong>₹<?= number_format($subtotal, 2) ?></strong></td>
                    </tr>
                    <?php if (!isset($settings['show_gst']) || $settings['show_gst'] != '0'): ?>
                    <tr>
                        <td colspan="<?= $colspan_left ?>" class="text-end"><strong>CGST @9%:</strong></td>
                        <td class="text-end" colspan="<?= $colspan_middle ?>">₹<?= number_format($cgst_total, 2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="<?= $colspan_left ?>" class="text-end"><strong>SGST @9%:</strong></td>
                        <td class="text-end" colspan="<?= $colspan_middle ?>">₹<?= number_format($sgst_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (floatval($invoice['discount_amount'] ?? 0) > 0): ?>
                    <tr>
                        <td colspan="<?= $colspan_left ?>" class="text-end"><strong>Discount:</strong></td>
                        <td class="text-end text-danger" colspan="<?= $colspan_middle ?>">-₹<?= number_format(floatval($invoice['discount_amount']), 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td colspan="<?= $colspan_left ?>" class="text-end"><strong>Total Amount:</strong></td>
                        <td class="text-end grand-total" colspan="<?= $colspan_middle ?>"><strong>₹<?= number_format(floatval($invoice['total_amount']), 2) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Amount in Words -->
        <div class="amount-in-words">
            <strong>Amount in Words:</strong> <?= ucwords(numberToWords(floatval($invoice['total_amount']))) ?>
        </div>

        <!-- Bank Details (if available) -->
        <?php if (!empty($settings['bank_name']) || !empty($settings['bank_account_no']) || !empty($settings['bank_ifsc']) || !empty($settings['upi_id'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <h6><i class="bi bi-bank me-2"></i>Bank Details:</h6>
                <div class="row">
                    <?php if (!empty($settings['bank_name'])): ?>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>Bank:</strong> <?= htmlspecialchars($settings['bank_name']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($settings['bank_account_no'])): ?>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>A/c No:</strong> <?= htmlspecialchars($settings['bank_account_no']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($settings['bank_ifsc'])): ?>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>IFSC:</strong> <?= htmlspecialchars($settings['bank_ifsc']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($settings['bank_branch'])): ?>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>Branch:</strong> <?= htmlspecialchars($settings['bank_branch']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($settings['upi_id'])): ?>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>UPI ID:</strong> <?= htmlspecialchars($settings['upi_id']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if (!empty($invoice['notes'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <h6><i class="bi bi-pencil-square me-2"></i>Notes:</h6>
                <p class="text-muted"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-note">
            <?php if (!empty($settings['invoice_footer_text'])): ?>
            <p class="mb-1"><?= nl2br(htmlspecialchars($settings['invoice_footer_text'])) ?></p>
            <?php else: ?>
            <p class="mb-1">This is a computer generated invoice - no signature required.</p>
            <?php endif; ?>
            <p class="mb-0">Thank you for your business!</p>
        </div>

        <!-- Signature Section -->
        <div class="row mt-4">
            <div class="col-6">
                <p>_________________________</p>
                <p><i class="bi bi-person me-1"></i> Customer Signature</p>
            </div>
            <div class="col-6 text-end">
                <p>_________________________</p>
                <p><i class="bi bi-pen me-1"></i> Authorized Signatory</p>
            </div>
        </div>

        <!-- Created By -->
        <div class="text-center text-muted small mt-3 pt-2 border-top">
            <i class="bi bi-clock-history me-1"></i>
            Created by: <?= htmlspecialchars($invoice['created_by_name'] ?? 'System') ?> on <?= date('d M Y, h:i A', strtotime($invoice['created_at'])) ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide success alert after 3 seconds
        setTimeout(function() {
            var alert = document.querySelector('.alert');
            if (alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 3000);

        // Auto trigger print dialog after page load (optional)
        <?php if ($show_success): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>
</body>
</html>