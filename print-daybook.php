<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$daybook_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch daybook details
$stmt = $pdo->prepare("
    SELECT d.*, u.full_name as created_by_name
    FROM daybook d
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ?
");

$stmt->execute([$daybook_id]);
$daybook = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$daybook) {
    header("Location: daybook-list.php");
    exit();
}

// Fetch daybook items
$itemStmt = $pdo->prepare("
    SELECT di.*, p.name as product_name, p.hsn_code
    FROM daybook_items di
    LEFT JOIN products p ON di.product_id = p.id
    WHERE di.daybook_id = ?
    ORDER BY di.id ASC
");

$itemStmt->execute([$daybook_id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Get settings from gst_settings table
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM gst_settings");
$settings = [];
while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to check if logo exists
function getCompanyLogo($settings) {
    if (!empty($settings['company_logo']) && file_exists($settings['company_logo'])) {
        return $settings['company_logo'];
    }
    return null;
}

$logo_path = getCompanyLogo($settings);

// Helper function to get payment status badge class
function getPaymentStatusClass($status) {
    switch ($status) {
        case 'paid':
            return 'success';
        case 'partial':
            return 'warning';
        default:
            return 'danger';
    }
}

// Helper function to get payment status text
function getPaymentStatusText($status) {
    switch ($status) {
        case 'paid':
            return 'Paid';
        case 'partial':
            return 'Partially Paid';
        default:
            return 'Pending';
    }
}

// Helper function to get customer type text
function getCustomerTypeText($type) {
    switch ($type) {
        case 'constructor':
            return 'Constructor/Builder';
        case 'dealer':
            return 'Dealer';
        default:
            return 'Regular Customer';
    }
}

// Simple number to words function
function numberToWords($number) {
    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
    return $f->format($number);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Daybook Entry #<?= htmlspecialchars($daybook['invoice_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f6fa;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        
        .invoice-box {
            max-width: 1000px;
            margin: auto;
            background: white;
            padding: 25px;
            box-shadow: 0 0 10px rgba(0,0,0,.15);
            border-radius: 8px;
        }
        
        .invoice-header {
            border-bottom: 2px solid #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
        }
        
        .company-logo {
            max-height: 70px;
            max-width: 200px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        
        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #333;
        }
        
        .gst-details {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 13px;
        }
        
        table th {
            background: #f0f0f0;
            text-align: center;
            padding: 10px 5px;
            font-weight: 600;
            border: 1px solid #ddd;
        }
        
        table td {
            padding: 8px 5px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        
        .text-end {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        tfoot td {
            font-weight: 600;
            background-color: #f9f9f9;
        }
        
        .footer {
            margin-top: 25px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px dashed #ccc;
            padding-top: 15px;
        }
        
        .signature {
            margin-top: 40px;
            font-size: 13px;
        }
        
        .print-btn {
            margin-bottom: 15px;
        }
        
        .info-section {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .info-section strong {
            color: #333;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-success {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-warning {
            background-color: #fed7aa;
            color: #92400e;
        }
        
        .status-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .gst-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .invoice-box {
                box-shadow: none;
                margin: 0;
                padding: 15px;
            }
            
            .print-btn {
                display: none;
            }
            
            table th {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .status-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="print-btn text-end">
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer"></i> Print Daybook Entry
    </button>
</div>

<div class="invoice-box">
    <div class="invoice-header">
        <div class="row">
            <div class="col-6">
                <?php if ($logo_path): ?>
                    <img src="<?= htmlspecialchars($logo_path) ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                
                <div class="company-name">
                    <?= htmlspecialchars($settings['company_name'] ?? 'Your Company Name') ?>
                </div>
                
                <div class="gst-details">
                    <?php if (!empty($settings['company_address'])): ?>
                        <div><?= nl2br(htmlspecialchars($settings['company_address'])) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['company_city']) || !empty($settings['company_state']) || !empty($settings['company_pincode'])): ?>
                        <div>
                            <?= htmlspecialchars($settings['company_city'] ?? '') ?> 
                            <?= !empty($settings['company_city']) && (!empty($settings['company_state']) || !empty($settings['company_pincode'])) ? ', ' : '' ?>
                            <?= htmlspecialchars($settings['company_state'] ?? '') ?> 
                            <?= !empty($settings['company_pincode']) ? ' - ' . htmlspecialchars($settings['company_pincode']) : '' ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['company_phone'])): ?>
                        <div>Phone: <?= htmlspecialchars($settings['company_phone']) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['company_email'])): ?>
                        <div>Email: <?= htmlspecialchars($settings['company_email']) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['company_gst'])): ?>
                        <div>GST: <?= htmlspecialchars($settings['company_gst']) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['company_pan'])): ?>
                        <div>PAN: <?= htmlspecialchars($settings['company_pan']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-6 text-end">
                <h4 style="color: #333; margin-bottom: 10px;">DAYBOOK ENTRY</h4>
                <table style="width: auto; margin-left: auto; border: none; background: none;">
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Entry No:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= htmlspecialchars($daybook['invoice_number']) ?></td>
                    </tr>
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Date:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= date('d-m-Y', strtotime($daybook['invoice_date'])) ?></td>
                    </tr>
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Status:</strong></td>
                        <td style="border: none; padding: 3px 5px;">
                            <span class="status-badge status-<?= getPaymentStatusClass($daybook['payment_status']) ?>">
                                <?= getPaymentStatusText($daybook['payment_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Created By:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= htmlspecialchars($daybook['created_by_name'] ?? 'System') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Driver & Location Information -->
    <div class="info-section">
        <div class="row">
            <div class="col-md-6">
                <strong>Driver Information:</strong><br>
                <strong>Name:</strong> <?= htmlspecialchars($daybook['driver_name']) ?><br>
                <?php if (!empty($daybook['driver_number'])): ?>
                    <strong>Mobile:</strong> <?= htmlspecialchars($daybook['driver_number']) ?><br>
                <?php endif; ?>
                <strong>Location:</strong> <?= htmlspecialchars($daybook['location']) ?>
            </div>
            <div class="col-md-6">
                <strong>Customer Information:</strong><br>
                <strong>Type:</strong> <?= getCustomerTypeText($daybook['customer_type']) ?><br>
                <strong>Name:</strong> <?= htmlspecialchars($daybook['customer_name']) ?><br>
                <?php if (!empty($daybook['customer_mobile'])): ?>
                    <strong>Mobile:</strong> <?= htmlspecialchars($daybook['customer_mobile']) ?><br>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Products/Services Table -->
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="30%">Product/Service</th>
                <th width="8%">Unit</th>
                <th width="10%">Quantity</th>
                <th width="12%">Unit Price (₹)</th>
                <th width="8%">GST%</th>
                <th width="10%">GST Amt (₹)</th>
                <th width="12%">Discount</th>
                <th width="12%">Total (₹)</th>
            </tr>
        </thead>
        
        <tbody>
            <?php
            $subtotal = 0;
            $total_gst = 0;
            $total_discount = 0;
            
            foreach ($items as $k => $item):
                $quantity = floatval($item['quantity']);
                $unit_price = floatval($item['unit_price']);
                $gst_rate = floatval($item['gst_rate'] ?? 0);
                $gst_amount = floatval($item['gst_amount'] ?? 0);
                $discount_amount = floatval($item['discount_amount'] ?? 0);
                $line_total = floatval($item['total_amount'] ?? 0);
                $discount_type = $item['discount_type'] ?? 'percentage';
                $discount_value = floatval($item['discount_value'] ?? 0);
                
                $subtotal += $quantity * $unit_price;
                $total_gst += $gst_amount;
                $total_discount += $discount_amount;
                
                $discount_display = '';
                if ($discount_amount > 0) {
                    if ($discount_type === 'percentage') {
                        $discount_display = $discount_value . '% (₹' . number_format($discount_amount, 2) . ')';
                    } else {
                        $discount_display = '₹' . number_format($discount_amount, 2);
                    }
                } else {
                    $discount_display = '-';
                }
            ?>
                <tr>
                    <td class="text-center"><?= $k + 1 ?></td>
                    <td>
                        <?= htmlspecialchars($item['product_name']) ?>
                        <?php if (!empty($item['hsn_code'])): ?>
                            <br><small class="text-muted">HSN: <?= htmlspecialchars($item['hsn_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="text-center"><?= number_format($quantity, 2) ?></td>
                    <td class="text-end">₹<?= number_format($unit_price, 2) ?></td>
                    <td class="text-center">
                        <?php if ($gst_rate > 0): ?>
                            <span class="gst-badge"><?= number_format($gst_rate, 2) ?>%</span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="text-end">₹<?= number_format($gst_amount, 2) ?></td>
                    <td class="text-end"><?= $discount_display ?></td>
                    <td class="text-end"><strong>₹<?= number_format($line_total, 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        
        <tfoot>
            <tr>
                <td colspan="8" class="text-end"><strong>Subtotal (Excluding GST):</strong></td>
                <td class="text-end"><strong>₹<?= number_format($subtotal, 2) ?></strong></td>
            </tr>
            <tr>
                <td colspan="8" class="text-end"><strong>Total GST Amount:</strong></td>
                <td class="text-end"><strong>₹<?= number_format($total_gst, 2) ?></strong></td>
            </tr>
            <?php if ($total_discount > 0): ?>
            <tr>
                <td colspan="8" class="text-end"><strong>Total Discount:</strong></td>
                <td class="text-end text-danger"><strong>-₹<?= number_format($total_discount, 2) ?></strong></td>
            </tr>
            <?php endif; ?>
            <?php if (floatval($daybook['discount_total'] ?? 0) > 0): ?>
            <tr>
                <td colspan="8" class="text-end"><strong>Overall Discount:</strong></td>
                <td class="text-end text-danger">
                    <strong>-₹<?= number_format(floatval($daybook['discount_total']), 2) ?></strong>
                    <?php if ($daybook['discount_type'] == 'percentage'): ?>
                        <br><small>(<?= floatval($daybook['discount_value']) ?>%)</small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr style="background-color: #f0f0f0;">
                <td colspan="8" class="text-end"><strong>Grand Total:</strong></td>
                <td class="text-end"><strong>₹<?= number_format(floatval($daybook['grand_total']), 2) ?></strong></td>
            </tr>
            <?php if (floatval($daybook['paid_amount']) > 0): ?>
            <tr>
                <td colspan="8" class="text-end"><strong>Paid Amount:</strong></td>
                <td class="text-end text-success"><strong>₹<?= number_format(floatval($daybook['paid_amount']), 2) ?></strong></td>
            </tr>
            <tr>
                <td colspan="8" class="text-end"><strong>Balance Due:</strong></td>
                <td class="text-end text-danger">
                    <strong>₹<?= number_format(floatval($daybook['grand_total']) - floatval($daybook['paid_amount']), 2) ?></strong>
                </td>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <!-- Amount in Words -->
    <div style="margin-top: 15px; font-size: 13px; font-style: italic;">
        <strong>Amount in Words:</strong> <?= ucwords(numberToWords($daybook['grand_total'])) ?> Rupees Only
    </div>

    <!-- Payment Summary -->
    <div class="info-section" style="margin-top: 15px;">
        <div class="row">
            <div class="col-md-6">
                <strong>Payment Summary:</strong><br>
                <strong>Status:</strong> <?= getPaymentStatusText($daybook['payment_status']) ?><br>
                <?php if ($daybook['payment_status'] == 'partial'): ?>
                    <strong>Paid Amount:</strong> ₹<?= number_format(floatval($daybook['paid_amount']), 2) ?><br>
                    <strong>Balance Due:</strong> ₹<?= number_format(floatval($daybook['grand_total']) - floatval($daybook['paid_amount']), 2) ?>
                <?php elseif ($daybook['payment_status'] == 'paid'): ?>
                    <strong>Paid Amount:</strong> ₹<?= number_format(floatval($daybook['paid_amount']), 2) ?>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <strong>Discount Summary:</strong><br>
                <?php if (floatval($daybook['discount_total']) > 0): ?>
                    <strong>Discount Type:</strong> <?= ucfirst($daybook['discount_type']) ?><br>
                    <strong>Discount Value:</strong> <?= $daybook['discount_value'] ?> <?= $daybook['discount_type'] == 'percentage' ? '%' : '₹' ?><br>
                    <strong>Discount Amount:</strong> ₹<?= number_format(floatval($daybook['discount_total']), 2) ?>
                <?php else: ?>
                    No discount applied
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <?php if (!empty($daybook['notes'])): ?>
    <div style="margin-top: 15px; font-size: 12px; border-top: 1px solid #eee; padding-top: 10px;">
        <strong>Notes:</strong><br>
        <?= nl2br(htmlspecialchars($daybook['notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- Terms & Conditions -->
    <?php if (!empty($daybook['terms'])): ?>
    <div style="margin-top: 10px; font-size: 12px;">
        <strong>Terms & Conditions:</strong><br>
        <?= nl2br(htmlspecialchars($daybook['terms'])) ?>
    </div>
    <?php endif; ?>

    <!-- Bank Details (if available) -->
    <?php if (!empty($settings['bank_name']) || !empty($settings['bank_account_no']) || !empty($settings['bank_ifsc'])): ?>
    <div style="margin-top: 20px; font-size: 12px; border-top: 1px dashed #ccc; padding-top: 10px;">
        <strong>Bank Details:</strong><br>
        <?php if (!empty($settings['bank_name'])): ?>Bank: <?= htmlspecialchars($settings['bank_name']) ?><br><?php endif; ?>
        <?php if (!empty($settings['bank_account_no'])): ?>A/c No: <?= htmlspecialchars($settings['bank_account_no']) ?><br><?php endif; ?>
        <?php if (!empty($settings['bank_ifsc'])): ?>IFSC: <?= htmlspecialchars($settings['bank_ifsc']) ?><br><?php endif; ?>
        <?php if (!empty($settings['bank_branch'])): ?>Branch: <?= htmlspecialchars($settings['bank_branch']) ?><br><?php endif; ?>
        <?php if (!empty($settings['upi_id'])): ?>UPI ID: <?= htmlspecialchars($settings['upi_id']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Signature -->
    <div class="signature row">
        <div class="col-6">
            <div style="margin-top: 30px;">
                _________________________<br>
                Driver Signature
            </div>
        </div>
        <div class="col-6 text-end">
            <div style="margin-top: 30px;">
                _________________________<br>
                Authorized Signatory
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <?php if (!empty($settings['invoice_footer_text'])): ?>
            <?= nl2br(htmlspecialchars($settings['invoice_footer_text'])) ?><br>
        <?php endif; ?>
        This is a computer-generated document and does not require a physical signature.
        <br>
        <small>Generated on: <?= date('d-m-Y H:i:s') ?></small>
    </div>
</div>

<!-- Bootstrap Icons (optional, for print button icon) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

</body>
</html>