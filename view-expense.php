<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get expense ID from URL
$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($expense_id <= 0) {
    header("Location: manage-expenses.php?error=1");
    exit();
}

// Fetch expense details
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               u1.full_name as created_by_name,
               u2.full_name as updated_by_name,
               s.name as supplier_name,
               s.company_name as supplier_company,
               s.gst_number as supplier_gst,
               s.phone as supplier_phone,
               s.email as supplier_email,
               v.vehicle_number,
               v.owner_name as vehicle_owner,
               g.gst_rate,
               g.hsn_code
        FROM expenses e
        LEFT JOIN users u1 ON e.created_by = u1.id
        LEFT JOIN users u2 ON e.updated_by = u2.id
        LEFT JOIN suppliers s ON e.supplier_id = s.id
        LEFT JOIN vehicles v ON e.vehicle_id = v.id
        LEFT JOIN gst_details g ON e.gst_id = g.id
        WHERE e.id = :id
    ");
    $stmt->execute([':id' => $expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        header("Location: manage-expenses.php?error=2");
        exit();
    }

} catch (Exception $e) {
    error_log("Error fetching expense: " . $e->getMessage());
    header("Location: manage-expenses.php?error=3");
    exit();
}

// Get expense categories mapping
$expense_categories = [
    'Transportation' => 'Transportation & Freight',
    'Fuel' => 'Fuel & Diesel',
    'Maintenance' => 'Vehicle Maintenance',
    'Salary' => 'Salaries & Wages',
    'Rent' => 'Rent & Lease',
    'Electricity' => 'Electricity & Utilities',
    'Office' => 'Office Expenses',
    'Marketing' => 'Marketing & Advertising',
    'Insurance' => 'Insurance',
    'Tax' => 'Taxes & Fees',
    'Legal' => 'Legal & Professional',
    'Repair' => 'Repairs & Maintenance',
    'Equipment' => 'Equipment Purchase',
    'Miscellaneous' => 'Miscellaneous'
];

// Handle delete request
if (isset($_GET['delete']) && $_GET['delete'] == 'confirm') {
    try {
        $pdo->beginTransaction();
        
        // Check if expense can be deleted (you might want to add conditions)
        $deleteStmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
        $deleteStmt->execute([':id' => $expense_id]);
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (:user_id, 4, :description, :activity_data, :created_by)
        ");
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':description' => "Expense deleted: " . $expense['expense_number'],
            ':activity_data' => json_encode([
                'expense_id' => $expense_id,
                'expense_number' => $expense['expense_number'],
                'amount' => $expense['amount']
            ]),
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        
        $pdo->commit();
        
        header("Location: manage-expenses.php?deleted=1");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $delete_error = "Failed to delete expense: " . $e->getMessage();
        error_log("Expense deletion error: " . $e->getMessage());
    }
}

// Get payment method display name
function getPaymentMethodName($method) {
    $methods = [
        'cash' => 'Cash',
        'bank' => 'Bank Transfer',
        'cheque' => 'Cheque',
        'online' => 'Online Payment'
    ];
    return $methods[$method] ?? ucfirst($method);
}

// Get payment method icon
function getPaymentMethodIcon($method) {
    $icons = [
        'cash' => 'mdi-cash',
        'bank' => 'mdi-bank',
        'cheque' => 'mdi-file-document',
        'online' => 'mdi-credit-card'
    ];
    return $icons[$method] ?? 'mdi-cash';
}

// Get payment method color
function getPaymentMethodColor($method) {
    $colors = [
        'cash' => 'success',
        'bank' => 'primary',
        'cheque' => 'warning',
        'online' => 'info'
    ];
    return $colors[$method] ?? 'secondary';
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
                            <h4 class="mb-0 font-size-18">Expense Details</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-expenses.php">Expenses</a></li>
                                    <li class="breadcrumb-item active">View Expense</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Delete Error Message -->
                <?php if (isset($delete_error)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($delete_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- Main Expense Card -->
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
                                    <h4 class="card-title mb-0">Expense #<?= htmlspecialchars($expense['expense_number']) ?></h4>
                                    <div class="d-flex gap-2">
                                        <a href="edit-expense.php?id=<?= $expense_id ?>" class="btn btn-primary btn-sm">
                                            <i class="mdi mdi-pencil"></i> Edit
                                        </a>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete()">
                                            <i class="mdi mdi-delete"></i> Delete
                                        </button>
                                        <a href="print-expense.php?id=<?= $expense_id ?>" class="btn btn-info btn-sm" target="_blank">
                                            <i class="mdi mdi-printer"></i> Print
                                        </a>
                                        <a href="manage-expenses.php" class="btn btn-secondary btn-sm">
                                            <i class="mdi mdi-arrow-left"></i> Back
                                        </a>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Expense Date</label>
                                            <p class="fw-bold mb-0">
                                                <i class="mdi mdi-calendar me-1"></i>
                                                <?= date('d F Y', strtotime($expense['expense_date'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Category</label>
                                            <p class="fw-bold mb-0">
                                                <span class="badge bg-soft-info text-info p-2">
                                                    <?= htmlspecialchars($expense_categories[$expense['category']] ?? $expense['category']) ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Description</label>
                                            <p class="fw-bold mb-0"><?= htmlspecialchars($expense['description']) ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Base Amount</label>
                                            <h3 class="mb-0">₹<?= number_format($expense['amount'], 2) ?></h3>
                                        </div>
                                    </div>
                                    <?php if ($expense['gst_amount']): ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">GST Amount (<?= $expense['gst_rate'] ?>%)</label>
                                            <h4 class="mb-0 text-info">₹<?= number_format($expense['gst_amount'], 2) ?></h4>
                                            <small class="text-muted">HSN: <?= htmlspecialchars($expense['hsn_code'] ?? 'N/A') ?></small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Total Amount</label>
                                            <h2 class="mb-0 text-success">₹<?= number_format($expense['total_amount'], 2) ?></h2>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Payment Method</label>
                                            <p class="mb-0">
                                                <span class="badge bg-soft-<?= getPaymentMethodColor($expense['payment_method']) ?> p-2">
                                                    <i class="mdi <?= getPaymentMethodIcon($expense['payment_method']) ?> me-1"></i>
                                                    <?= getPaymentMethodName($expense['payment_method']) ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <?php if ($expense['reference_number']): ?>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Reference Number</label>
                                            <p class="fw-bold mb-0">
                                                <i class="mdi mdi-file-document-outline me-1"></i>
                                                <?= htmlspecialchars($expense['reference_number']) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($expense['notes']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Additional Notes</label>
                                            <p class="mb-0 p-3 bg-light rounded"><?= nl2br(htmlspecialchars($expense['notes'])) ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Audit Information Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Audit Information</h4>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Created By</label>
                                            <p class="fw-bold mb-0">
                                                <i class="mdi mdi-account me-1"></i>
                                                <?= htmlspecialchars($expense['created_by_name'] ?? 'System') ?>
                                            </p>
                                            <small class="text-muted">
                                                <?= date('d M Y h:i A', strtotime($expense['created_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php if ($expense['updated_by_name']): ?>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="text-muted mb-1">Last Updated By</label>
                                            <p class="fw-bold mb-0">
                                                <i class="mdi mdi-account-edit me-1"></i>
                                                <?= htmlspecialchars($expense['updated_by_name']) ?>
                                            </p>
                                            <small class="text-muted">
                                                <?= date('d M Y h:i A', strtotime($expense['updated_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Supplier Information Card -->
                        <?php if ($expense['supplier_id']): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-truck me-2"></i>
                                    Supplier Information
                                </h4>
                                
                                <div class="mb-3">
                                    <h5 class="font-size-15 mb-1"><?= htmlspecialchars($expense['supplier_name']) ?></h5>
                                    <?php if ($expense['supplier_company']): ?>
                                    <p class="text-muted"><?= htmlspecialchars($expense['supplier_company']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($expense['supplier_gst']): ?>
                                <div class="d-flex mb-2">
                                    <i class="mdi mdi-certificate text-primary me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">GST Number</small>
                                        <span><?= htmlspecialchars($expense['supplier_gst']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($expense['supplier_phone']): ?>
                                <div class="d-flex mb-2">
                                    <i class="mdi mdi-phone text-success me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">Phone</small>
                                        <span><?= htmlspecialchars($expense['supplier_phone']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($expense['supplier_email']): ?>
                                <div class="d-flex">
                                    <i class="mdi mdi-email text-info me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">Email</small>
                                        <span><?= htmlspecialchars($expense['supplier_email']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Vehicle Information Card -->
                        <?php if ($expense['vehicle_id']): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-car me-2"></i>
                                    Vehicle Information
                                </h4>
                                
                                <div class="mb-3">
                                    <h3 class="mb-1"><?= htmlspecialchars($expense['vehicle_number']) ?></h3>
                                    <?php if ($expense['vehicle_owner']): ?>
                                    <p class="text-muted">Owner: <?= htmlspecialchars($expense['vehicle_owner']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="alert alert-info mb-0">
                                    <i class="mdi mdi-information me-2"></i>
                                    This expense is linked to vehicle <?= htmlspecialchars($expense['vehicle_number']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Summary Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Summary</h4>
                                
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Base Amount:</span>
                                    <span class="fw-bold">₹<?= number_format($expense['amount'], 2) ?></span>
                                </div>
                                
                                <?php if ($expense['gst_amount']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">GST (<?= $expense['gst_rate'] ?>%):</span>
                                    <span class="fw-bold text-info">₹<?= number_format($expense['gst_amount'], 2) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <hr>
                                
                                <div class="d-flex justify-content-between mb-3">
                                    <span class="h5 mb-0">Total Amount:</span>
                                    <span class="h4 mb-0 text-success">₹<?= number_format($expense['total_amount'], 2) ?></span>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Payment Method:</span>
                                    <span class="badge bg-soft-<?= getPaymentMethodColor($expense['payment_method']) ?> p-2">
                                        <?= getPaymentMethodName($expense['payment_method']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons Card (Mobile) -->
                        <div class="card d-block d-lg-none">
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="edit-expense.php?id=<?= $expense_id ?>" class="btn btn-primary">
                                        <i class="mdi mdi-pencil"></i> Edit Expense
                                    </a>
                                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                                        <i class="mdi mdi-delete"></i> Delete Expense
                                    </button>
                                    <a href="print-expense.php?id=<?= $expense_id ?>" class="btn btn-info" target="_blank">
                                        <i class="mdi mdi-printer"></i> Print Expense
                                    </a>
                                    <a href="manage-expenses.php" class="btn btn-secondary">
                                        <i class="mdi mdi-arrow-left"></i> Back to List
                                    </a>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Confirm delete function
    function confirmDelete() {
        Swal.fire({
            title: 'Delete Expense',
            text: 'Are you sure you want to delete this expense? This action cannot be undone!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'view-expense.php?id=<?= $expense_id ?>&delete=confirm';
            }
        });
    }

    // Print function
    function printExpense() {
        window.open('print-expense.php?id=<?= $expense_id ?>', '_blank');
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

    // Show success message if coming from edit
    <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Expense updated successfully!',
        timer: 2000,
        showConfirmButton: false
    });
    <?php endif; ?>
</script>

<style>
    /* Print styles */
    @media print {
        .vertical-menu, .topbar, .footer, .btn, .modal, 
        .page-title-right, .card-title .btn, .no-print,
        .d-lg-none, .action-buttons {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
            break-inside: avoid;
        }
        .container-fluid {
            padding: 0 !important;
        }
        body {
            background: white;
        }
    }

    /* Custom styles */
    .card {
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.3s;
    }
    
    .card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .badge {
        font-weight: 500;
        padding: 0.5rem 1rem;
    }
    
    .bg-soft-success {
        background-color: rgba(52, 195, 143, 0.1) !important;
    }
    
    .bg-soft-info {
        background-color: rgba(85, 110, 230, 0.1) !important;
    }
    
    .bg-soft-warning {
        background-color: rgba(241, 180, 76, 0.1) !important;
    }
    
    .bg-soft-danger {
        background-color: rgba(244, 106, 106, 0.1) !important;
    }
    
    .bg-soft-primary {
        background-color: rgba(85, 110, 230, 0.1) !important;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .card-title {
            font-size: 1.1rem;
        }
        
        h2 {
            font-size: 1.5rem;
        }
        
        h3 {
            font-size: 1.3rem;
        }
        
        h4 {
            font-size: 1.1rem;
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
        background-color: #f46a6a !important;
    }
    
    .swal2-cancel {
        background-color: #556ee6 !important;
    }
    
    /* Divider */
    hr {
        opacity: 0.1;
        margin: 1rem 0;
    }
</style>

</body>
</html>