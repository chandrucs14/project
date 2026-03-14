<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

//

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

// Helper function to convert number to words (fixed deprecation error)
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
    
    // Handle float numbers by converting to string to preserve decimal places
    $numberStr = (string)$number;
    
    if (strpos($numberStr, '.') !== false) {
        list($whole, $fraction) = explode('.', $numberStr);
        $whole = (int)$whole;
        $fraction = (int)substr($fraction, 0, 2); // Only take first 2 decimal places for currency
    } else {
        $whole = (int)$number;
        $fraction = 0;
    }
    
    if ($whole < 0) {
        return $negative . numberToWords(abs($whole));
    }
    
    $string = '';
    
    // Convert whole number part
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
    
    // Add fraction part for paise
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
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="row">
                <div class="col-6">
                    <div class="company-details">
                        <div class="company-name"><?= htmlspecialchars($settings['company_name'] ?? 'Your Company Name') ?></div>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($settings['company_address'] ?? 'Your Company Address')) ?></p>
                        <p class="mb-0">GST: <?= htmlspecialchars($settings['company_gst'] ?? '22AAAAA0000A1Z5') ?></p>
                        <p class="mb-0">Phone: <?= htmlspecialchars($settings['company_phone'] ?? '') ?></p>
                        <p class="mb-0">Email: <?= htmlspecialchars($settings['company_email'] ?? '') ?></p>
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
                    <h5><i class="bi bi-person-bounding-box me-2"></i>Bill To:</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong><?= htmlspecialchars($invoice['customer_name']) ?></strong></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['customer_code']) ?></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['address'] ?? '') ?></p>
                            <p class="mb-1"><?= htmlspecialchars($invoice['city'] ?? '') ?>, <?= htmlspecialchars($invoice['state'] ?? '') ?> - <?= htmlspecialchars($invoice['pincode'] ?? '') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($invoice['phone'] ?? 'N/A') ?></p>
                            <p class="mb-1"><i class="bi bi-envelope me-1"></i> <?= htmlspecialchars($invoice['email'] ?? 'N/A') ?></p>
                            <p class="mb-1"><i class="bi bi-building me-1"></i> GST: <?= htmlspecialchars($invoice['gst_number'] ?? 'N/A') ?></p>
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
                        <th width="10%">HSN/SAC</th>
                        <th width="8%">Qty</th>
                        <th width="10%">Unit</th>
                        <th width="10%">Unit Price</th>
                        <th width="8%">GST %</th>
                        <th width="12%">GST Amount</th>
                        <th width="12%">Total</th>
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
                        <td class="text-center"><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                        <td class="text-center"><?= number_format(floatval($item['quantity']), 2) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['unit'] ?? 'Nos') ?></td>
                        <td class="text-end">₹<?= number_format(floatval($item['unit_price']), 2) ?></td>
                        <td class="text-center"><?= number_format(floatval($item['gst_rate'] ?? 0), 2) ?>%</td>
                        <td class="text-end">₹<?= number_format($item_gst, 2) ?></td>
                        <td class="text-end">₹<?= number_format($item_total, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="8" class="text-end"><strong>Subtotal:</strong></td>
                        <td class="text-end">₹<?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="8" class="text-end"><strong>GST Total:</strong></td>
                        <td class="text-end">₹<?= number_format($gst_total, 2) ?></td>
                    </tr>
                    <?php if (floatval($invoice['discount_amount'] ?? 0) > 0): ?>
                    <tr>
                        <td colspan="8" class="text-end"><strong>Discount:</strong></td>
                        <td class="text-end text-danger">-₹<?= number_format(floatval($invoice['discount_amount']), 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td colspan="8" class="text-end"><strong>Total Amount:</strong></td>
                        <td class="text-end grand-total"><strong>₹<?= number_format(floatval($invoice['total_amount']), 2) ?></strong></td>
                    </tr>
                    <tr>
                        <td colspan="9" class="amount-in-words">
                            <strong>Amount in Words:</strong> <?= ucwords(numberToWords(floatval($invoice['total_amount']))) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Notes and Terms -->
        <?php if (!empty($invoice['notes']) || !empty($invoice['terms'])): ?>
        <div class="row mt-4">
            <?php if (!empty($invoice['notes'])): ?>
            <div class="col-6">
                <h6><i class="bi bi-pencil-square me-2"></i>Notes:</h6>
                <p class="text-muted"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
            </div>
            <?php endif; ?>
            <?php if (!empty($invoice['terms'])): ?>
            <div class="col-6">
                <h6><i class="bi bi-file-text me-2"></i>Terms & Conditions:</h6>
                <p class="text-muted"><?= nl2br(htmlspecialchars($invoice['terms'])) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Bank Details (if available) -->
        <?php if (!empty($settings['bank_name'])): ?>
        <div class="row mt-4">
            <div class="col-12">
                <h6><i class="bi bi-bank me-2"></i>Bank Details:</h6>
                <div class="row">
                    <div class="col-md-3">
                        <p class="mb-1"><strong>Bank Name:</strong> <?= htmlspecialchars($settings['bank_name'] ?? '') ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>Account No:</strong> <?= htmlspecialchars($settings['account_no'] ?? '') ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>IFSC Code:</strong> <?= htmlspecialchars($settings['ifsc_code'] ?? '') ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="mb-1"><strong>Branch:</strong> <?= htmlspecialchars($settings['branch'] ?? '') ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-note">
            <p class="mb-1">This is a computer generated invoice - no signature required.</p>
            <p class="mb-0">Thank you for your business!</p>
        </div>

        <!-- Signature Section -->
        <div class="row mt-5">
            <div class="col-6">
                <p>_________________________</p>
                <p><i class="bi bi-person me-1"></i>Customer Signature</p>
            </div>
            <div class="col-6 text-end">
                <p>_________________________</p>
                <p><i class="bi bi-pen me-1"></i>Authorized Signatory</p>
            </div>
        </div>

        <!-- Created By -->
        <div class="text-center text-muted small mt-3 pt-3 border-top">
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