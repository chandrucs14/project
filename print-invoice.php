<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
header("Location: login.php");
exit();
}

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $pdo->prepare("
SELECT i.*,c.name customer_name,c.phone,c.email,c.address,c.city,c.state,c.pincode
FROM invoices i
LEFT JOIN customers c ON i.customer_id=c.id
WHERE i.id=?
");

$stmt->execute([$invoice_id]);
$invoice=$stmt->fetch(PDO::FETCH_ASSOC);

$itemStmt=$pdo->prepare("
SELECT ii.*,p.name product_name,p.unit,g.gst_rate,g.hsn_code
FROM invoice_items ii
LEFT JOIN products p ON ii.product_id=p.id
LEFT JOIN gst_details g ON ii.gst_id=g.id
WHERE ii.invoice_id=?
");

$itemStmt->execute([$invoice_id]);
$items=$itemStmt->fetchAll(PDO::FETCH_ASSOC);

$settingsStmt=$pdo->query("SELECT setting_key,setting_value FROM gst_settings");

$settings=[];
while($row=$settingsStmt->fetch(PDO::FETCH_ASSOC)){
$settings[$row['setting_key']]=$row['setting_value'];
}

?>
<!DOCTYPE html>
<html>
<head>

<title>Invoice</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
background:#f5f6fa;
font-family:Arial;
}

.invoice-box{
max-width:900px;
margin:auto;
background:white;
padding:25px;
box-shadow:0 0 10px rgba(0,0,0,.15);
}

.invoice-header{
border-bottom:2px solid #000;
margin-bottom:20px;
padding-bottom:10px;
}

.company-name{
font-size:22px;
font-weight:bold;
}

table{
width:100%;
border-collapse:collapse;
}

table th{
background:#eee;
text-align:center;
padding:8px;
font-size:14px;
border:1px solid #ccc;
}

table td{
padding:6px;
border:1px solid #ccc;
font-size:14px;
}

.text-end{
text-align:right;
}

.text-center{
text-align:center;
}

tfoot td{
font-weight:bold;
}

.footer{
margin-top:20px;
text-align:center;
font-size:13px;
}

.signature{
margin-top:40px;
}

@media print{

body{
background:white;
zoom:90%;
}

.invoice-box{
box-shadow:none;
margin:0;
}

.print-btn{
display:none;
}

}

</style>

</head>

<body>

<div class="print-btn text-end p-3">

<button onclick="window.print()" class="btn btn-primary">
Print Invoice
</button>

</div>

<div class="invoice-box">

<div class="invoice-header">

<div class="row">

<div class="col-6">

<div class="company-name">
<?= $settings['company_name'] ?? '' ?>
</div>

<div>
<?= $settings['company_address'] ?? '' ?>
</div>

<div>
GST : <?= $settings['company_gst'] ?? '' ?>
</div>

</div>

<div class="col-6 text-end">

<h4>TAX INVOICE</h4>

Invoice No : <?= $invoice['invoice_number'] ?><br>

Date : <?= date('d-m-Y',strtotime($invoice['invoice_date'])) ?>

</div>

</div>

</div>


<div class="row mb-3">

<div class="col-6">

<b>Bill To</b><br>

<?= $invoice['customer_name'] ?><br>

<?= $invoice['address'] ?><br>

<?= $invoice['city'] ?> <?= $invoice['pincode'] ?><br>

Phone : <?= $invoice['phone'] ?>

</div>

</div>


<table>

<thead>

<tr>

<th width="5%">#</th>
<th width="30%">Description</th>
<th width="10%">HSN</th>
<th width="8%">Qty</th>
<th width="8%">Unit</th>
<th width="12%">Unit Price</th>
<th width="7%">GST%</th>
<th width="10%">CGST</th>
<th width="10%">SGST</th>
<th width="10%">Total</th>

</tr>

</thead>

<tbody>

<?php

$subtotal=0;

foreach($items as $k=>$item){

$qty=$item['quantity'];
$price=$item['unit_price'];
$gst=$item['gst_rate'];

$amount=$qty*$price;

$gst_amount=$amount*($gst/100);

$cgst=$gst_amount/2;
$sgst=$gst_amount/2;

$total=$amount+$gst_amount;

$subtotal+=$amount;

?>

<tr>

<td class="text-center"><?= $k+1 ?></td>

<td><?= $item['product_name'] ?></td>

<td class="text-center"><?= $item['hsn_code'] ?></td>

<td class="text-center"><?= number_format($qty,2) ?></td>

<td class="text-center"><?= $item['unit'] ?></td>

<td class="text-end">₹<?= number_format($price,2) ?></td>

<td class="text-center"><?= $gst ?>%</td>

<td class="text-end">₹<?= number_format($cgst,2) ?></td>

<td class="text-end">₹<?= number_format($sgst,2) ?></td>

<td class="text-end">₹<?= number_format($total,2) ?></td>

</tr>

<?php } ?>

</tbody>

<tfoot>

<tr>

<td colspan="9" class="text-end">Subtotal</td>

<td class="text-end">
₹<?= number_format($subtotal,2) ?>
</td>

</tr>

<tr>

<td colspan="9" class="text-end">Total Amount</td>

<td class="text-end">

₹<?= number_format($invoice['total_amount'],2) ?>

</td>

</tr>

</tfoot>

</table>


<div class="signature row">

<div class="col-6">

Customer Signature

</div>

<div class="col-6 text-end">

Authorized Signatory

</div>

</div>


<div class="footer">

This is a computer generated invoice.<br>

<b>Thank you for your business!</b>

</div>

</div>

</body>
</html>