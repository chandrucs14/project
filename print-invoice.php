<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $pdo->prepare("
    SELECT i.*, c.name as customer_name, c.phone, c.email, c.address, c.city, c.state, c.pincode, c.gst_number
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.id = ?
");

$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header("Location: invoices.php");
    exit();
}

$itemStmt = $pdo->prepare("
    SELECT ii.*, p.name as product_name, p.unit, g.gst_rate, g.hsn_code
    FROM invoice_items ii
    LEFT JOIN products p ON ii.product_id = p.id
    LEFT JOIN gst_details g ON ii.gst_id = g.id
    WHERE ii.invoice_id = ?
");

$itemStmt->execute([$invoice_id]);
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f6fa;
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        
        .invoice-box {
            max-width: 900px;
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
        
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin-top: 30px;
        }
        
        .print-btn {
            margin-bottom: 15px;
        }
        
        .customer-details {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .customer-details strong {
            color: #333;
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
        }
    </style>
</head>
<body>

<div class="print-btn text-end">
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer"></i> Print Invoice
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
                <h4 style="color: #333; margin-bottom: 10px;">TAX INVOICE</h4>
                <table style="width: auto; margin-left: auto; border: none; background: none;">
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Invoice No:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                    </tr>
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Date:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?></td>
                    </tr>
                    <?php if (!empty($invoice['due_date'])): ?>
                    <tr style="background: none;">
                        <td style="border: none; padding: 3px 5px; text-align: right;"><strong>Due Date:</strong></td>
                        <td style="border: none; padding: 3px 5px;"><?= date('d-m-Y', strtotime($invoice['due_date'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Customer Details -->
    <div class="customer-details">
        <div class="row">
            <div class="col-12">
                <strong>Bill To:</strong><br>
                <?= htmlspecialchars($invoice['customer_name']) ?><br>
                <?php if (!empty($invoice['address'])): ?>
                    <?= htmlspecialchars($invoice['address']) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['city']) || !empty($invoice['state']) || !empty($invoice['pincode'])): ?>
                    <?= htmlspecialchars($invoice['city'] ?? '') ?> 
                    <?= !empty($invoice['city']) && (!empty($invoice['state']) || !empty($invoice['pincode'])) ? ', ' : '' ?>
                    <?= htmlspecialchars($invoice['state'] ?? '') ?> 
                    <?= !empty($invoice['pincode']) ? ' - ' . htmlspecialchars($invoice['pincode']) : '' ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['phone'])): ?>
                    Phone: <?= htmlspecialchars($invoice['phone']) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['email'])): ?>
                    Email: <?= htmlspecialchars($invoice['email']) ?><br>
                <?php endif; ?>
                <?php if (!empty($invoice['gst_number'])): ?>
                    GST: <?= htmlspecialchars($invoice['gst_number']) ?><br>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Invoice Items Table -->
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="25%">Description</th>
                <th width="8%">HSN</th>
                <th width="7%">Qty</th>
                <th width="7%">Unit</th>
                <th width="10%">Unit Price</th>
                <th width="7%">GST%</th>
                <th width="8%">CGST</th>
                <th width="8%">SGST</th>
                <th width="10%">Total</th>
            </tr>
        </thead>
        
        <tbody>
            <?php
            $subtotal = 0;
            
            
            foreach ($items as $k => $item):
                $qty = floatval($item['quantity']);
                $price = floatval($item['unit_price']);
                $gst_rate = floatval($item['gst_rate'] ?? 0);
                
                $amount = $qty * $price;
                $gst_amount = $amount * ($gst_rate / 100);
                $cgst = $gst_amount / 2;
                $sgst = $gst_amount / 2;
                $total = $amount + $gst_amount;
                
                $subtotal += $amount;
                $total_cgst += $cgst;
                $total_sgst += $sgst;
            ?>
                <tr>
                    <td class="text-center"><?= $k + 1 ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['hsn_code'] ?? 'N/A') ?></td>
                    <td class="text-center"><?= number_format($qty, 2) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit'] ?? 'Nos') ?></td>
                    <td class="text-end">₹<?= number_format($price, 2) ?></td>
                    <td class="text-center"><?= number_format($gst_rate, 2) ?>%</td>
                    <td class="text-end">₹<?= number_format($cgst, 2) ?></td>
                    <td class="text-end">₹<?= number_format($sgst, 2) ?></td>
                    <td class="text-end">₹<?= number_format($total, 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        
        <tfoot>
            <tr>
                <td colspan="9" class="text-end"><strong>Subtotal:</strong></td>
                <td class="text-end"><strong>₹<?= number_format($subtotal, 2) ?></strong></td>
            </tr>
            <tr>
                <td colspan="9" class="text-end"><strong>CGST:</strong></td>
                <td class="text-end"><strong>₹<?= number_format($total_cgst, 2) ?></strong></td>
            </tr>
            <tr>
                <td colspan="9" class="text-end"><strong>SGST:</strong></td>
                <td class="text-end"><strong>₹<?= number_format($total_sgst, 2) ?></strong></td>
            </tr>
            <?php if (floatval($invoice['discount_amount'] ?? 0) > 0): ?>
            <tr>
                <td colspan="9" class="text-end"><strong>Discount:</strong></td>
                <td class="text-end text-danger"><strong>-₹<?= number_format(floatval($invoice['discount_amount']), 2) ?></strong></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td colspan="9" class="text-end"><strong>Total Amount:</strong></td>
                <td class="text-end"><strong>₹<?= number_format(floatval($invoice['total_amount']), 2) ?></strong></td>
            </tr>
        </tfoot>
    </table>

    <!-- Amount in Words -->
    <?php
    // Simple number to words function for the amount (you can enhance this)
    function numberToWords($number) {
        $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
        return $f->format($number);
    }
    ?>
    <div style="margin-top: 15px; font-size: 13px; font-style: italic;">
        <strong>Amount in Words:</strong> <?= ucwords(numberToWords($invoice['total_amount'])) ?> Rupees Only
    </div>

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

    <!-- Notes -->
    <?php if (!empty($invoice['notes'])): ?>
    <div style="margin-top: 15px; font-size: 12px;">
        <strong>Notes:</strong><br>
        <?= nl2br(htmlspecialchars($invoice['notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- Signature -->
    <div class="signature row">
        <div class="col-6">
            <div style="margin-top: 30px;">
                _________________________<br>
                Customer Signature
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
        <?php else: ?>
            
        <?php endif; ?>
        
    </div>
</div>

<!-- Bootstrap Icons (optional, for print button icon) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

</body>
</html>