<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


$error = '';
$success = '';

// Fetch suppliers for dropdown
$suppStmt = $pdo->query("SELECT id, name, supplier_code, gst_number, pan_number FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppStmt->fetchAll();

// Fetch invoices that have GST (for reference)
$invoiceStmt = $pdo->query("
    SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.gst_total, 
           s.id as supplier_id, s.name as supplier_name
    FROM invoices i
    JOIN customers s ON i.customer_id = s.id
    WHERE i.gst_total > 0 AND i.status != 'cancelled'
    ORDER BY i.invoice_date DESC
    LIMIT 50
");
$invoices = $invoiceStmt->fetchAll();

// Fetch purchase orders that have GST (for reference)
$poStmt = $pdo->query("
    SELECT po.id, po.po_number, po.order_date, po.total_amount, po.gst_total, 
           s.id as supplier_id, s.name as supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.gst_total > 0 AND po.status NOT IN ('cancelled', 'draft')
    ORDER BY po.order_date DESC
    LIMIT 50
");
$pos = $poStmt->fetchAll();

// Generate financial year options
$current_year = date('Y');
$current_month = date('m');
$fy_options = [];

// Indian financial year runs from April to March
if ($current_month >= 4) {
    $default_fy = $current_year . '-' . ($current_year + 1);
} else {
    $default_fy = ($current_year - 1) . '-' . $current_year;
}

for ($i = -2; $i <= 2; $i++) {
    $year = $current_year + $i;
    $fy_options[] = $year . '-' . ($year + 1);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_gst_credit'])) {
    
    $supplier_id = (int)$_POST['supplier_id'];
    $reference_type = $_POST['reference_type'] ?? 'manual';
    $invoice_id = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
    $purchase_order_id = !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null;
    $gst_amount = floatval($_POST['gst_amount'] ?? 0);
    $input_credit_date = $_POST['input_credit_date'] ?? date('Y-m-d');
    $financial_year = $_POST['financial_year'] ?? $default_fy;
    $is_claimed = isset($_POST['is_claimed']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if ($supplier_id <= 0) {
        $error = "Please select a supplier.";
    } elseif ($gst_amount <= 0) {
        $error = "GST amount must be greater than 0.";
    } elseif (empty($input_credit_date)) {
        $error = "Input credit date is required.";
    } elseif (empty($financial_year)) {
        $error = "Financial year is required.";
    } else {
        
        // If reference type is invoice, validate invoice
        if ($reference_type === 'invoice' && !$invoice_id) {
            $error = "Please select an invoice.";
        }
        
        // If reference type is purchase_order, validate PO
        if ($reference_type === 'purchase_order' && !$purchase_order_id) {
            $error = "Please select a purchase order.";
        }
    }
    
    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            
            // Get supplier details for logging
            $suppStmt = $pdo->prepare("SELECT name, supplier_code FROM suppliers WHERE id = ?");
            $suppStmt->execute([$supplier_id]);
            $supplier = $suppStmt->fetch();
            
            if (!$supplier) {
                throw new Exception("Supplier not found.");
            }
            
            // Check if this GST credit already exists (prevent duplicates)
            if ($invoice_id) {
                $checkStmt = $pdo->prepare("SELECT id FROM gst_input_credit WHERE invoice_id = ?");
                $checkStmt->execute([$invoice_id]);
                if ($checkStmt->fetch()) {
                    throw new Exception("GST credit for this invoice already exists.");
                }
            }
            
            if ($purchase_order_id) {
                $checkStmt = $pdo->prepare("SELECT id FROM gst_input_credit WHERE purchase_order_id = ?");
                $checkStmt->execute([$purchase_order_id]);
                if ($checkStmt->fetch()) {
                    throw new Exception("GST credit for this purchase order already exists.");
                }
            }
            
            // Insert GST input credit
            $stmt = $pdo->prepare("
                INSERT INTO gst_input_credit (
                    supplier_id, invoice_id, purchase_order_id, gst_amount,
                    input_credit_date, is_claimed, financial_year, notes,
                    created_by, created_at
                ) VALUES (
                    :supplier_id, :invoice_id, :purchase_order_id, :gst_amount,
                    :input_credit_date, :is_claimed, :financial_year, :notes,
                    :created_by, NOW()
                )
            ");
            
            $params = [
                ':supplier_id' => $supplier_id,
                ':invoice_id' => $invoice_id,
                ':purchase_order_id' => $purchase_order_id,
                ':gst_amount' => $gst_amount,
                ':input_credit_date' => $input_credit_date,
                ':is_claimed' => $is_claimed,
                ':financial_year' => $financial_year,
                ':notes' => $notes ?: null,
                ':created_by' => $_SESSION['user_id']
            ];
            
            $result = $stmt->execute($params);
            
            if ($result) {
                $credit_id = $pdo->lastInsertId();
                
                // If claimed, set claimed date
                if ($is_claimed) {
                    $updateStmt = $pdo->prepare("
                        UPDATE gst_input_credit 
                        SET claimed_date = CURDATE() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$credit_id]);
                }
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'credit_id' => $credit_id,
                    'supplier_name' => $supplier['name'],
                    'supplier_code' => $supplier['supplier_code'],
                    'gst_amount' => $gst_amount,
                    'invoice_id' => $invoice_id,
                    'purchase_order_id' => $purchase_order_id,
                    'financial_year' => $financial_year,
                    'is_claimed' => $is_claimed
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "New GST input credit of ₹$gst_amount added for supplier: " . $supplier['name'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "GST input credit added successfully.";
                header("Location: gst-input-credit.php");
                exit();
            } else {
                $pdo->rollBack();
                $error = "Failed to add GST input credit. Please try again.";
            }
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
            error_log("PDO Error: " . $e->getMessage());
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
            error_log("GST credit creation error: " . $e->getMessage());
        }
    }
}

// Helper function for safe output
function safe_echo($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .reference-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #556ee6;
        }
        .info-card {
            background-color: #e9ecef;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
        .gst-amount-display {
            font-size: 24px;
            font-weight: bold;
            color: #556ee6;
        }
        .financial-year-badge {
            background-color: #34c38f;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 14px;
        }
    </style>
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
                            <h4 class="mb-0 font-size-18">Add GST Input Credit</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="gst-input-credit.php">GST Input Credit</a></li>
                                    <li class="breadcrumb-item active">Add Credit</li>
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

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">GST Input Credit Information</h4>
                                <p class="card-title-desc">Add GST input credit from purchases or expenses</p>

                                <form method="POST" action="" id="gstCreditForm">
                                    
                                    <!-- Supplier Selection -->
                                    <div class="mb-3">
                                        <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                        <select name="supplier_id" id="supplier_id" class="form-control" required onchange="loadSupplierDetails(this.value)">
                                            <option value="">Choose supplier...</option>
                                            <?php foreach ($suppliers as $supp): ?>
                                                <option value="<?= $supp['id'] ?>" 
                                                        data-code="<?= $supp['supplier_code'] ?>"
                                                        data-gst="<?= $supp['gst_number'] ?>"
                                                        data-pan="<?= $supp['pan_number'] ?>"
                                                        <?= (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supp['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($supp['name']) ?> 
                                                    <?php if ($supp['supplier_code']): ?>
                                                        (<?= htmlspecialchars($supp['supplier_code']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Supplier Details Display -->
                                    <div id="supplier_details" class="info-card" style="display: none;">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Code:</strong> <span id="supp_code"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>GST:</strong> <span id="supp_gst"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>PAN:</strong> <span id="supp_pan"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Reference Type Selection -->
                                    <div class="mb-3 mt-3">
                                        <label class="form-label">Reference Type</label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input" name="reference_type" id="ref_manual" value="manual" checked onchange="toggleReferenceType()">
                                                    <label class="form-check-label" for="ref_manual">Manual Entry</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input" name="reference_type" id="ref_invoice" value="invoice" onchange="toggleReferenceType()">
                                                    <label class="form-check-label" for="ref_invoice">From Invoice</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input" name="reference_type" id="ref_po" value="purchase_order" onchange="toggleReferenceType()">
                                                    <label class="form-check-label" for="ref_po">From Purchase Order</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Invoice Selection (hidden by default) -->
                                    <div id="invoice_section" style="display: none;">
                                        <div class="reference-card">
                                            <h6><i class="mdi mdi-file-document me-2"></i>Select Invoice</h6>
                                            <select name="invoice_id" id="invoice_id" class="form-control" onchange="loadInvoiceDetails(this.value)">
                                                <option value="">Choose invoice...</option>
                                                <?php foreach ($invoices as $inv): ?>
                                                    <option value="<?= $inv['id'] ?>" 
                                                            data-amount="<?= $inv['gst_total'] ?>"
                                                            data-date="<?= $inv['invoice_date'] ?>"
                                                            data-supplier="<?= $inv['supplier_id'] ?>"
                                                            data-supplier-name="<?= htmlspecialchars($inv['supplier_name']) ?>">
                                                        <?= htmlspecialchars($inv['invoice_number']) ?> - 
                                                        <?= date('d-m-Y', strtotime($inv['invoice_date'])) ?> - 
                                                        ₹<?= number_format($inv['gst_total'], 2) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="invoice_details" class="info-card mt-2" style="display: none;">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <strong>Invoice Date:</strong> <span id="inv_date"></span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>GST Amount:</strong> <span id="inv_gst"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Purchase Order Selection (hidden by default) -->
                                    <div id="po_section" style="display: none;">
                                        <div class="reference-card">
                                            <h6><i class="mdi mdi-cart me-2"></i>Select Purchase Order</h6>
                                            <select name="purchase_order_id" id="purchase_order_id" class="form-control" onchange="loadPODetails(this.value)">
                                                <option value="">Choose purchase order...</option>
                                                <?php foreach ($pos as $po): ?>
                                                    <option value="<?= $po['id'] ?>" 
                                                            data-amount="<?= $po['gst_total'] ?>"
                                                            data-date="<?= $po['order_date'] ?>"
                                                            data-supplier="<?= $po['supplier_id'] ?>"
                                                            data-supplier-name="<?= htmlspecialchars($po['supplier_name']) ?>">
                                                        <?= htmlspecialchars($po['po_number']) ?> - 
                                                        <?= date('d-m-Y', strtotime($po['order_date'])) ?> - 
                                                        ₹<?= number_format($po['gst_total'], 2) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="po_details" class="info-card mt-2" style="display: none;">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <strong>PO Date:</strong> <span id="po_date"></span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>GST Amount:</strong> <span id="po_gst"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- GST Amount -->
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">GST Amount (₹) <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                                    <input type="number" 
                                                           name="gst_amount" 
                                                           id="gst_amount" 
                                                           class="form-control" 
                                                           placeholder="Enter GST amount" 
                                                           min="0.01" 
                                                           step="0.01" 
                                                           required
                                                           value="<?= safe_echo($_POST['gst_amount'] ?? '') ?>"
                                                           onchange="updateGSTDisplay()">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Input Credit Date <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" 
                                                           name="input_credit_date" 
                                                           class="form-control" 
                                                           value="<?= safe_echo($_POST['input_credit_date'] ?? date('Y-m-d')) ?>" 
                                                           required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Financial Year -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Financial Year <span class="text-danger">*</span></label>
                                                <select name="financial_year" class="form-control" required>
                                                    <option value="">Select Financial Year</option>
                                                    <?php foreach ($fy_options as $fy): ?>
                                                        <option value="<?= $fy ?>" <?= (isset($_POST['financial_year']) && $_POST['financial_year'] == $fy) || $fy == $default_fy ? 'selected' : '' ?>>
                                                            <?= $fy ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Indian financial year (April-March)</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Claim Status</label>
                                                <div class="form-check form-switch form-switch-md mt-2">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           name="is_claimed" 
                                                           id="is_claimed"
                                                           <?= (isset($_POST['is_claimed']) && $_POST['is_claimed'] == 'on') ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_claimed">Mark as claimed immediately</label>
                                                </div>
                                                <small class="text-muted d-block">If checked, claimed date will be set to today</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="mb-3">
                                        <label class="form-label">Notes / Remarks</label>
                                        <textarea name="notes" class="form-control" rows="3" placeholder="Any additional information..."><?= safe_echo($_POST['notes'] ?? '') ?></textarea>
                                    </div>

                                    <!-- GST Amount Display -->
                                    <div class="alert alert-info text-center">
                                        <div class="gst-amount-display" id="gst_display">
                                            ₹<?= number_format($_POST['gst_amount'] ?? 0, 2) ?>
                                        </div>
                                        <span>GST Input Credit Amount</span>
                                    </div>

                                    <!-- Info Alert -->
                                    <div class="alert alert-warning">
                                        <i class="mdi mdi-information me-2"></i>
                                        <strong>Important:</strong> Ensure that the GST amount matches the invoice/PO. 
                                        Input credit can only be claimed for the current financial year or immediate past year.
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <hr>
                                            <div class="text-end">
                                                <a href="gst-input-credit.php" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="reset" class="btn btn-warning me-2" id="resetBtn">
                                                    <i class="mdi mdi-undo me-1"></i> Reset
                                                </button>
                                                <button type="submit" name="add_gst_credit" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Add GST Credit</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Saving...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Information Sidebar -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">GST Input Credit Guide</h5>
                                
                                <div class="mb-3">
                                    <h6><i class="mdi mdi-information text-info me-2"></i>What is Input Credit?</h6>
                                    <p class="text-muted small">GST paid on purchases can be claimed as input tax credit against output GST liability.</p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6><i class="mdi mdi-calendar text-success me-2"></i>Financial Year</h6>
                                    <p class="text-muted small">Indian financial year runs from April 1 to March 31. Input credit must be claimed in the same financial year.</p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6><i class="mdi mdi-file-document text-primary me-2"></i>Required Documents</h6>
                                    <ul class="text-muted small">
                                        <li>Valid GST invoice from supplier</li>
                                        <li>Proof of payment</li>
                                        <li>Supplier should have filed GSTR-1</li>
                                        <li>Goods/services should be received</li>
                                    </ul>
                                </div>
                                
                                <div class="mb-3">
                                    <h6><i class="mdi mdi-alert text-warning me-2"></i>Restrictions</h6>
                                    <ul class="text-muted small">
                                        <li>Cannot claim on personal expenses</li>
                                        <li>Time limit of 1 year from invoice date</li>
                                        <li>Supplier must be GST registered</li>
                                        <li>Tax should be paid to supplier</li>
                                    </ul>
                                </div>
                                
                                <div class="alert alert-success mt-3">
                                    <i class="mdi mdi-percent me-2"></i>
                                    <strong>Current FY:</strong> <span class="financial-year-badge"><?= $default_fy ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Recent GST Rates</h5>
                                <?php
                                $rateStmt = $pdo->query("SELECT gst_rate, hsn_code FROM gst_details WHERE is_active = 1 ORDER BY gst_rate LIMIT 5");
                                $rates = $rateStmt->fetchAll();
                                ?>
                                <div class="list-group">
                                    <?php foreach ($rates as $rate): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= $rate['gst_rate'] ?>% GST</span>
                                        <span class="badge bg-info"><?= htmlspecialchars($rate['hsn_code']) ?></span>
                                    </div>
                                    <?php endforeach; ?>
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
    // Form submission loading state
    document.getElementById('gstCreditForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        btn.disabled = true;
        btnText.style.display = 'none';
        loading.style.display = 'inline-block';
    });
    
    // Reset button confirmation
    document.getElementById('resetBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Reset Form?',
            text: 'Are you sure you want to reset all fields? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, reset it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('gstCreditForm').reset();
                document.getElementById('supplier_details').style.display = 'none';
                document.getElementById('invoice_section').style.display = 'none';
                document.getElementById('po_section').style.display = 'none';
                document.getElementById('invoice_details').style.display = 'none';
                document.getElementById('po_details').style.display = 'none';
                updateGSTDisplay();
                
                Swal.fire({
                    title: 'Reset!',
                    text: 'Form has been reset.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (!alert.classList.contains('alert-info') && !alert.classList.contains('alert-warning')) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 500);
            }
        });
    }, 5000);
    
    // Toggle reference type sections
    function toggleReferenceType() {
        const manual = document.getElementById('ref_manual').checked;
        const invoice = document.getElementById('ref_invoice').checked;
        const po = document.getElementById('ref_po').checked;
        
        document.getElementById('invoice_section').style.display = invoice ? 'block' : 'none';
        document.getElementById('po_section').style.display = po ? 'block' : 'none';
        
        // Clear values when switching
        if (manual) {
            document.getElementById('invoice_id').value = '';
            document.getElementById('purchase_order_id').value = '';
            document.getElementById('invoice_details').style.display = 'none';
            document.getElementById('po_details').style.display = 'none';
        }
    }
    
    // Load supplier details
    function loadSupplierDetails(supplierId) {
        const select = document.getElementById('supplier_id');
        const selected = select.options[select.selectedIndex];
        
        if (supplierId) {
            const code = selected.dataset.code || 'N/A';
            const gst = selected.dataset.gst || 'N/A';
            const pan = selected.dataset.pan || 'N/A';
            
            document.getElementById('supp_code').textContent = code;
            document.getElementById('supp_gst').textContent = gst;
            document.getElementById('supp_pan').textContent = pan;
            
            document.getElementById('supplier_details').style.display = 'block';
        } else {
            document.getElementById('supplier_details').style.display = 'none';
        }
    }
    
    // Load invoice details
    function loadInvoiceDetails(invoiceId) {
        const select = document.getElementById('invoice_id');
        const selected = select.options[select.selectedIndex];
        
        if (invoiceId) {
            const amount = selected.dataset.amount || '0';
            const date = selected.dataset.date || '';
            
            document.getElementById('inv_gst').textContent = '₹' + parseFloat(amount).toFixed(2);
            document.getElementById('inv_date').textContent = date ? new Date(date).toLocaleDateString('en-IN') : 'N/A';
            
            // Auto-fill GST amount
            document.getElementById('gst_amount').value = amount;
            updateGSTDisplay();
            
            // Auto-select supplier if not already selected
            const supplierId = selected.dataset.supplier;
            const supplierName = selected.dataset.supplierName;
            
            const supplierSelect = document.getElementById('supplier_id');
            for (let i = 0; i < supplierSelect.options.length; i++) {
                if (supplierSelect.options[i].value == supplierId) {
                    supplierSelect.value = supplierId;
                    loadSupplierDetails(supplierId);
                    break;
                }
            }
            
            document.getElementById('invoice_details').style.display = 'block';
        } else {
            document.getElementById('invoice_details').style.display = 'none';
        }
    }
    
    // Load PO details
    function loadPODetails(poId) {
        const select = document.getElementById('purchase_order_id');
        const selected = select.options[select.selectedIndex];
        
        if (poId) {
            const amount = selected.dataset.amount || '0';
            const date = selected.dataset.date || '';
            
            document.getElementById('po_gst').textContent = '₹' + parseFloat(amount).toFixed(2);
            document.getElementById('po_date').textContent = date ? new Date(date).toLocaleDateString('en-IN') : 'N/A';
            
            // Auto-fill GST amount
            document.getElementById('gst_amount').value = amount;
            updateGSTDisplay();
            
            // Auto-select supplier if not already selected
            const supplierId = selected.dataset.supplier;
            const supplierName = selected.dataset.supplierName;
            
            const supplierSelect = document.getElementById('supplier_id');
            for (let i = 0; i < supplierSelect.options.length; i++) {
                if (supplierSelect.options[i].value == supplierId) {
                    supplierSelect.value = supplierId;
                    loadSupplierDetails(supplierId);
                    break;
                }
            }
            
            document.getElementById('po_details').style.display = 'block';
        } else {
            document.getElementById('po_details').style.display = 'none';
        }
    }
    
    // Update GST amount display
    function updateGSTDisplay() {
        const amount = parseFloat(document.getElementById('gst_amount').value) || 0;
        document.getElementById('gst_display').textContent = '₹' + amount.toFixed(2);
    }
    
    // Form validation before submit
    document.getElementById('gstCreditForm')?.addEventListener('submit', function(e) {
        const supplierId = document.getElementById('supplier_id').value;
        const gstAmount = parseFloat(document.getElementById('gst_amount').value) || 0;
        
        if (!supplierId) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please select a supplier',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (gstAmount <= 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'GST amount must be greater than 0',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        const invoiceChecked = document.getElementById('ref_invoice').checked;
        const poChecked = document.getElementById('ref_po').checked;
        
        if (invoiceChecked && !document.getElementById('invoice_id').value) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please select an invoice',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (poChecked && !document.getElementById('purchase_order_id').value) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please select a purchase order',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });
    
    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('gstCreditForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        const originalValues = {};
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox') {
                originalValues[input.name] = input.value;
            } else {
                originalValues[input.name] = input.checked;
            }
            
            input.addEventListener('change', () => {
                checkFormDirty();
            });
            input.addEventListener('input', () => {
                checkFormDirty();
            });
        });
        
        function checkFormDirty() {
            formDirty = false;
            formInputs.forEach(input => {
                if (input.type !== 'checkbox') {
                    if (input.value !== originalValues[input.name]) {
                        formDirty = true;
                    }
                } else {
                    if (input.checked !== originalValues[input.name]) {
                        formDirty = true;
                    }
                }
            });
        }
        
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        form.addEventListener('submit', () => {
            formDirty = false;
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('gstCreditForm').submit();
        }
        
        // Escape to reset
        if (e.key === 'Escape') {
            e.preventDefault();
            document.getElementById('resetBtn')?.click();
        }
    });
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>

</body>
</html>