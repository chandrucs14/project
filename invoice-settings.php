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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_invoice_settings'])) {
    try {
        $pdo->beginTransaction();
        
        // Array of settings to update
        $settings = [
            // Invoice Numbering
            'invoice_prefix' => trim($_POST['invoice_prefix'] ?? 'INV-'),
            'invoice_start_number' => intval($_POST['invoice_start_number'] ?? 1001),
            'invoice_suffix' => trim($_POST['invoice_suffix'] ?? ''),
            'reset_invoice_number_yearly' => isset($_POST['reset_invoice_number_yearly']) ? '1' : '0',
            
            // Company Details (from invoice_settings)
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'company_city' => trim($_POST['company_city'] ?? ''),
            'company_state' => trim($_POST['company_state'] ?? ''),
            'company_pincode' => trim($_POST['company_pincode'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'company_website' => trim($_POST['company_website'] ?? ''),
            'company_gst' => strtoupper(trim($_POST['company_gst'] ?? '')),
            'company_pan' => strtoupper(trim($_POST['company_pan'] ?? '')),
            'company_state_code' => trim($_POST['company_state_code'] ?? ''),
            
            // Invoice Display Settings
            'invoice_footer_text' => trim($_POST['invoice_footer_text'] ?? ''),
            'invoice_terms_conditions' => trim($_POST['invoice_terms_conditions'] ?? ''),
            'show_gst' => isset($_POST['show_gst']) ? '1' : '0',
            'show_hsn' => isset($_POST['show_hsn']) ? '1' : '0',
            'show_cgst_sgst' => isset($_POST['show_cgst_sgst']) ? '1' : '0',
            'show_discount' => isset($_POST['show_discount']) ? '1' : '0',
            'show_tax_summary' => isset($_POST['show_tax_summary']) ? '1' : '0',
            'show_amount_in_words' => isset($_POST['show_amount_in_words']) ? '1' : '0',
            'show_bank_details' => isset($_POST['show_bank_details']) ? '1' : '0',
            
            // Payment & Tax Settings
            'default_payment_terms' => intval($_POST['default_payment_terms'] ?? 30),
            'default_payment_type' => $_POST['default_payment_type'] ?? 'credit',
            'tax_calculation_method' => $_POST['tax_calculation_method'] ?? 'exclusive',
            'rounding_method' => $_POST['rounding_method'] ?? 'normal',
            'rounding_precision' => intval($_POST['rounding_precision'] ?? 2),
            
            // Bank Details
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_account_no' => trim($_POST['bank_account_no'] ?? ''),
            'bank_ifsc' => trim($_POST['bank_ifsc'] ?? ''),
            'bank_branch' => trim($_POST['bank_branch'] ?? ''),
            'bank_account_holder' => trim($_POST['bank_account_holder'] ?? ''),
            'upi_id' => trim($_POST['upi_id'] ?? ''),
            
            // Email Settings
            'invoice_email_subject' => trim($_POST['invoice_email_subject'] ?? 'Invoice #{invoice_number} from {company_name}'),
            'invoice_email_body' => trim($_POST['invoice_email_body'] ?? 'Dear {customer_name},\n\nPlease find attached invoice #{invoice_number} for your reference.\n\nTotal Amount: ₹{total_amount}\nDue Date: {due_date}\n\nThank you for your business!\n\nRegards,\n{company_name}'),
            
            // Other Settings
            'enable_auto_invoice_number' => isset($_POST['enable_auto_invoice_number']) ? '1' : '0',
            'enable_due_date_reminder' => isset($_POST['enable_due_date_reminder']) ? '1' : '0',
            'due_date_reminder_days' => intval($_POST['due_date_reminder_days'] ?? 3),
            'enable_stock_control' => isset($_POST['enable_stock_control']) ? '1' : '0',
            'low_stock_threshold' => intval($_POST['low_stock_threshold'] ?? 10)
        ];
        
        // Update each setting in invoice_settings table
        foreach ($settings as $key => $value) {
            // Check if setting exists
            $checkStmt = $pdo->prepare("SELECT id FROM invoice_settings WHERE setting_key = ?");
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetch()) {
                // Update existing setting
                $stmt = $pdo->prepare("
                    UPDATE invoice_settings 
                    SET setting_value = ?, updated_by = ?, updated_at = NOW() 
                    WHERE setting_key = ?
                ");
                $stmt->execute([$value, $_SESSION['user_id'], $key]);
            } else {
                // Insert new setting
                $descriptions = [
                    'invoice_prefix' => 'Invoice Number Prefix',
                    'invoice_start_number' => 'Invoice Starting Number',
                    'invoice_suffix' => 'Invoice Number Suffix',
                    'reset_invoice_number_yearly' => 'Reset Invoice Number Yearly',
                    'company_name' => 'Company Name',
                    'company_address' => 'Company Address',
                    'company_city' => 'Company City',
                    'company_state' => 'Company State',
                    'company_pincode' => 'Company Pincode',
                    'company_phone' => 'Company Phone',
                    'company_email' => 'Company Email',
                    'company_website' => 'Company Website',
                    'company_gst' => 'Company GST Number',
                    'company_pan' => 'Company PAN Number',
                    'company_state_code' => 'Company State Code',
                    'invoice_footer_text' => 'Invoice Footer Text',
                    'invoice_terms_conditions' => 'Invoice Terms & Conditions',
                    'show_gst' => 'Show GST on Invoice',
                    'show_hsn' => 'Show HSN Code on Invoice',
                    'show_cgst_sgst' => 'Show CGST/SGST Breakup',
                    'show_discount' => 'Show Discount on Invoice',
                    'show_tax_summary' => 'Show Tax Summary',
                    'show_amount_in_words' => 'Show Amount in Words',
                    'show_bank_details' => 'Show Bank Details',
                    'default_payment_terms' => 'Default Payment Terms (Days)',
                    'default_payment_type' => 'Default Payment Type',
                    'tax_calculation_method' => 'Tax Calculation Method',
                    'rounding_method' => 'Rounding Method',
                    'rounding_precision' => 'Rounding Precision',
                    'bank_name' => 'Bank Name',
                    'bank_account_no' => 'Bank Account Number',
                    'bank_ifsc' => 'Bank IFSC Code',
                    'bank_branch' => 'Bank Branch',
                    'bank_account_holder' => 'Bank Account Holder',
                    'upi_id' => 'UPI ID',
                    'invoice_email_subject' => 'Invoice Email Subject',
                    'invoice_email_body' => 'Invoice Email Body',
                    'enable_auto_invoice_number' => 'Enable Auto Invoice Number',
                    'enable_due_date_reminder' => 'Enable Due Date Reminder',
                    'due_date_reminder_days' => 'Due Date Reminder Days',
                    'enable_stock_control' => 'Enable Stock Control',
                    'low_stock_threshold' => 'Low Stock Threshold'
                ];
                
                $description = $descriptions[$key] ?? $key;
                $stmt = $pdo->prepare("
                    INSERT INTO invoice_settings (setting_key, setting_value, description, created_by, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$key, $value, $description, $_SESSION['user_id']]);
            }
        }
        
        // Log activity
        $activity_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
            VALUES (?, 4, ?, ?, NOW())
        ");
        
        $activity_data = json_encode([
            'action' => 'update_invoice_settings',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $activity_stmt->execute([
            $_SESSION['user_id'],
            'Invoice settings updated',
            $activity_data
        ]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Invoice settings updated successfully.";
        header("Location: invoice-settings.php");
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to update invoice settings: " . $e->getMessage();
        error_log("Invoice settings update error: " . $e->getMessage());
    }
}

// Fetch current invoice settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM invoice_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Set default values if not found
$default_settings = [
    // Invoice Numbering
    'invoice_prefix' => 'INV-',
    'invoice_start_number' => '1001',
    'invoice_suffix' => '',
    'reset_invoice_number_yearly' => '0',
    
    // Company Details
    'company_name' => '',
    'company_address' => '',
    'company_city' => '',
    'company_state' => '',
    'company_pincode' => '',
    'company_phone' => '',
    'company_email' => '',
    'company_website' => '',
    'company_gst' => '',
    'company_pan' => '',
    'company_state_code' => '',
    
    // Invoice Display
    'invoice_footer_text' => 'Thank you for your business!',
    'invoice_terms_conditions' => '1. Goods once sold will not be taken back.\n2. Payment is due within 30 days.\n3. Interest @ 24% p.a. will be charged on delayed payments.',
    'show_gst' => '1',
    'show_hsn' => '1',
    'show_cgst_sgst' => '1',
    'show_discount' => '1',
    'show_tax_summary' => '1',
    'show_amount_in_words' => '1',
    'show_bank_details' => '1',
    
    // Payment & Tax
    'default_payment_terms' => '30',
    'default_payment_type' => 'credit',
    'tax_calculation_method' => 'exclusive',
    'rounding_method' => 'normal',
    'rounding_precision' => '2',
    
    // Bank Details
    'bank_name' => '',
    'bank_account_no' => '',
    'bank_ifsc' => '',
    'bank_branch' => '',
    'bank_account_holder' => '',
    'upi_id' => '',
    
    // Email Settings
    'invoice_email_subject' => 'Invoice #{invoice_number} from {company_name}',
    'invoice_email_body' => 'Dear {customer_name},\n\nPlease find attached invoice #{invoice_number} for your reference.\n\nTotal Amount: ₹{total_amount}\nDue Date: {due_date}\n\nThank you for your business!\n\nRegards,\n{company_name}',
    
    // Other Settings
    'enable_auto_invoice_number' => '1',
    'enable_due_date_reminder' => '0',
    'due_date_reminder_days' => '3',
    'enable_stock_control' => '1',
    'low_stock_threshold' => '10'
];

foreach ($default_settings as $key => $default) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default;
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get active tab from URL parameter
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'invoice_numbering';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .nav-tabs-custom .nav-link {
            color: #495057;
            padding: 12px 20px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background: none;
        }
        .nav-tabs-custom .nav-link.active {
            color: #556ee6;
            background-color: #fff;
            border-bottom: 2px solid #556ee6;
        }
        .nav-tabs-custom .nav-link i {
            margin-right: 8px;
        }
        .tab-pane {
            display: none;
            padding: 20px 0;
        }
        .tab-pane.active {
            display: block;
        }
        .settings-card {
            border-left: 4px solid #556ee6;
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .settings-card h6 {
            color: #556ee6;
            margin-bottom: 10px;
        }
        .save-button-container {
            position: sticky;
            bottom: 20px;
            background: white;
            padding: 15px 0;
            border-top: 1px solid #e9ecef;
            margin-top: 20px;
            z-index: 100;
        }
        .variable-tag {
            background-color: #e9ecef;
            border-radius: 3px;
            padding: 2px 6px;
            font-size: 11px;
            color: #495057;
            display: inline-block;
            margin: 2px;
            cursor: pointer;
        }
        .variable-tag:hover {
            background-color: #556ee6;
            color: white;
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
                            <h4 class="mb-0 font-size-18">Invoice Settings</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Invoice Settings</li>
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

                <!-- Settings Tabs -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <!-- Tab Navigation -->
                                <ul class="nav nav-tabs nav-tabs-custom mb-4" id="settingsTabs" role="tablist">
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'invoice_numbering' ? 'active' : '' ?>" data-tab="invoice_numbering">
                                            <i class="mdi mdi-counter"></i> Invoice Numbering
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'company_details' ? 'active' : '' ?>" data-tab="company_details">
                                            <i class="mdi mdi-domain"></i> Company Details
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'invoice_display' ? 'active' : '' ?>" data-tab="invoice_display">
                                            <i class="mdi mdi-file-document"></i> Invoice Display
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'payment_tax' ? 'active' : '' ?>" data-tab="payment_tax">
                                            <i class="mdi mdi-currency-inr"></i> Payment & Tax
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'bank_details' ? 'active' : '' ?>" data-tab="bank_details">
                                            <i class="mdi mdi-bank"></i> Bank Details
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'email_settings' ? 'active' : '' ?>" data-tab="email_settings">
                                            <i class="mdi mdi-email"></i> Email Settings
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $active_tab == 'other_settings' ? 'active' : '' ?>" data-tab="other_settings">
                                            <i class="mdi mdi-cog"></i> Other Settings
                                        </button>
                                    </li>
                                </ul>

                                <!-- Tab Content -->
                                <div class="tab-content">
                                    <form method="POST" action="" id="invoiceSettingsForm">
                                        
                                        <!-- Invoice Numbering Tab -->
                                        <?php if ($active_tab == 'invoice_numbering'): ?>
                                        <div class="tab-pane active" id="invoice_numbering">
                                            <div class="settings-card">
                                                <h6><i class="mdi mdi-information me-2"></i>Invoice Number Format</h6>
                                                <p class="text-muted small">Configure how your invoice numbers are generated. The final invoice number will be: <strong><?= $settings['invoice_prefix'] ?>20240001<?= $settings['invoice_suffix'] ?></strong></p>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Invoice Prefix</label>
                                                        <input type="text" name="invoice_prefix" class="form-control" value="<?= htmlspecialchars($settings['invoice_prefix']) ?>" placeholder="e.g., INV-">
                                                        <small class="text-muted">Text before the number</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Invoice Start Number</label>
                                                        <input type="number" name="invoice_start_number" class="form-control" value="<?= htmlspecialchars($settings['invoice_start_number']) ?>" min="1">
                                                        <small class="text-muted">Starting invoice number</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Invoice Suffix</label>
                                                        <input type="text" name="invoice_suffix" class="form-control" value="<?= htmlspecialchars($settings['invoice_suffix']) ?>" placeholder="e.g., /23-24">
                                                        <small class="text-muted">Text after the number</small>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="reset_invoice_number_yearly" 
                                                                   id="reset_invoice_number_yearly"
                                                                   <?= $settings['reset_invoice_number_yearly'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="reset_invoice_number_yearly">Reset Invoice Number Yearly</label>
                                                        </div>
                                                        <small class="text-muted">Reset invoice number to 1 at the start of each financial year</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="enable_auto_invoice_number" 
                                                                   id="enable_auto_invoice_number"
                                                                   <?= $settings['enable_auto_invoice_number'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="enable_auto_invoice_number">Enable Auto Invoice Number</label>
                                                        </div>
                                                        <small class="text-muted">Automatically generate invoice numbers</small>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-info mt-3">
                                                <i class="mdi mdi-information me-2"></i>
                                                <strong>Preview:</strong> Your next invoice number will be: 
                                                <span class="badge bg-primary"><?= $settings['invoice_prefix'] . date('Y') . str_pad((intval($settings['invoice_start_number']) + 1), 4, '0', STR_PAD_LEFT) . $settings['invoice_suffix'] ?></span>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Company Details Tab -->
                                        <?php if ($active_tab == 'company_details'): ?>
                                        <div class="tab-pane active" id="company_details">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Company Name</label>
                                                        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Company Website</label>
                                                        <input type="url" name="company_website" class="form-control" value="<?= htmlspecialchars($settings['company_website']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Company Address</label>
                                                <textarea name="company_address" class="form-control" rows="2"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">City</label>
                                                        <input type="text" name="company_city" class="form-control" value="<?= htmlspecialchars($settings['company_city']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">State</label>
                                                        <input type="text" name="company_state" class="form-control" value="<?= htmlspecialchars($settings['company_state']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Pincode</label>
                                                        <input type="text" name="company_pincode" class="form-control" value="<?= htmlspecialchars($settings['company_pincode']) ?>" maxlength="6">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Phone Number</label>
                                                        <input type="tel" name="company_phone" class="form-control" value="<?= htmlspecialchars($settings['company_phone']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Email Address</label>
                                                        <input type="email" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">GST Number</label>
                                                        <input type="text" name="company_gst" class="form-control" value="<?= htmlspecialchars($settings['company_gst']) ?>" maxlength="15">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">PAN Number</label>
                                                        <input type="text" name="company_pan" class="form-control" value="<?= htmlspecialchars($settings['company_pan']) ?>" maxlength="10">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">State Code</label>
                                                        <input type="text" name="company_state_code" class="form-control" value="<?= htmlspecialchars($settings['company_state_code']) ?>" maxlength="2">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Invoice Display Tab -->
                                        <?php if ($active_tab == 'invoice_display'): ?>
                                        <div class="tab-pane active" id="invoice_display">
                                            <div class="settings-card">
                                                <h6><i class="mdi mdi-eye me-2"></i>Display Options</h6>
                                                <p class="text-muted small">Choose what information to show on your invoices</p>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_gst" 
                                                                   id="show_gst"
                                                                   <?= $settings['show_gst'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_gst">Show GST Details</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_hsn" 
                                                                   id="show_hsn"
                                                                   <?= $settings['show_hsn'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_hsn">Show HSN/SAC Codes</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_cgst_sgst" 
                                                                   id="show_cgst_sgst"
                                                                   <?= $settings['show_cgst_sgst'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_cgst_sgst">Show CGST/SGST Breakup</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_discount" 
                                                                   id="show_discount"
                                                                   <?= $settings['show_discount'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_discount">Show Discount</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_tax_summary" 
                                                                   id="show_tax_summary"
                                                                   <?= $settings['show_tax_summary'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_tax_summary">Show Tax Summary</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_amount_in_words" 
                                                                   id="show_amount_in_words"
                                                                   <?= $settings['show_amount_in_words'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_amount_in_words">Show Amount in Words</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="show_bank_details" 
                                                                   id="show_bank_details"
                                                                   <?= $settings['show_bank_details'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="show_bank_details">Show Bank Details</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Invoice Footer Text</label>
                                                <textarea name="invoice_footer_text" class="form-control" rows="2"><?= htmlspecialchars($settings['invoice_footer_text']) ?></textarea>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Terms & Conditions</label>
                                                <textarea name="invoice_terms_conditions" class="form-control" rows="4"><?= htmlspecialchars($settings['invoice_terms_conditions']) ?></textarea>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Payment & Tax Tab -->
                                        <?php if ($active_tab == 'payment_tax'): ?>
                                        <div class="tab-pane active" id="payment_tax">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Default Payment Terms (Days)</label>
                                                        <input type="number" name="default_payment_terms" class="form-control" value="<?= htmlspecialchars($settings['default_payment_terms']) ?>" min="0" max="365">
                                                        <small class="text-muted">Number of days for payment (0 for immediate)</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Default Payment Type</label>
                                                        <select name="default_payment_type" class="form-control">
                                                            <option value="cash" <?= $settings['default_payment_type'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                            <option value="credit" <?= $settings['default_payment_type'] == 'credit' ? 'selected' : '' ?>>Credit</option>
                                                            <option value="bank_transfer" <?= $settings['default_payment_type'] == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                                            <option value="cheque" <?= $settings['default_payment_type'] == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                            <option value="online" <?= $settings['default_payment_type'] == 'online' ? 'selected' : '' ?>>Online</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Tax Calculation Method</label>
                                                        <select name="tax_calculation_method" class="form-control">
                                                            <option value="exclusive" <?= $settings['tax_calculation_method'] == 'exclusive' ? 'selected' : '' ?>>Exclusive (Add tax to price)</option>
                                                            <option value="inclusive" <?= $settings['tax_calculation_method'] == 'inclusive' ? 'selected' : '' ?>>Inclusive (Tax included in price)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Rounding Method</label>
                                                        <select name="rounding_method" class="form-control">
                                                            <option value="normal" <?= $settings['rounding_method'] == 'normal' ? 'selected' : '' ?>>Normal Rounding</option>
                                                            <option value="floor" <?= $settings['rounding_method'] == 'floor' ? 'selected' : '' ?>>Round Down (Floor)</option>
                                                            <option value="ceil" <?= $settings['rounding_method'] == 'ceil' ? 'selected' : '' ?>>Round Up (Ceil)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Rounding Precision</label>
                                                        <select name="rounding_precision" class="form-control">
                                                            <option value="0" <?= $settings['rounding_precision'] == '0' ? 'selected' : '' ?>>0 (Nearest Rupee)</option>
                                                            <option value="1" <?= $settings['rounding_precision'] == '1' ? 'selected' : '' ?>>1 (Nearest 10 Paise)</option>
                                                            <option value="2" <?= $settings['rounding_precision'] == '2' ? 'selected' : '' ?>>2 (Nearest Paisa)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Bank Details Tab -->
                                        <?php if ($active_tab == 'bank_details'): ?>
                                        <div class="tab-pane active" id="bank_details">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Bank Name</label>
                                                        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($settings['bank_name']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Account Holder Name</label>
                                                        <input type="text" name="bank_account_holder" class="form-control" value="<?= htmlspecialchars($settings['bank_account_holder']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Account Number</label>
                                                        <input type="text" name="bank_account_no" class="form-control" value="<?= htmlspecialchars($settings['bank_account_no']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">IFSC Code</label>
                                                        <input type="text" name="bank_ifsc" class="form-control" value="<?= htmlspecialchars($settings['bank_ifsc']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Branch</label>
                                                        <input type="text" name="bank_branch" class="form-control" value="<?= htmlspecialchars($settings['bank_branch']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">UPI ID</label>
                                                        <input type="text" name="upi_id" class="form-control" value="<?= htmlspecialchars($settings['upi_id']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <i class="mdi mdi-information me-2"></i>
                                                Bank details will appear on invoices if "Show Bank Details" is enabled.
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Email Settings Tab -->
                                        <?php if ($active_tab == 'email_settings'): ?>
                                        <div class="tab-pane active" id="email_settings">
                                            <div class="settings-card">
                                                <h6><i class="mdi mdi-tag-multiple me-2"></i>Available Variables</h6>
                                                <p class="text-muted small">Click on a variable to insert it into the email template</p>
                                                <div class="mb-2">
                                                    <span class="variable-tag" onclick="insertVariable('invoice_number')">{invoice_number}</span>
                                                    <span class="variable-tag" onclick="insertVariable('customer_name')">{customer_name}</span>
                                                    <span class="variable-tag" onclick="insertVariable('total_amount')">{total_amount}</span>
                                                    <span class="variable-tag" onclick="insertVariable('due_date')">{due_date}</span>
                                                    <span class="variable-tag" onclick="insertVariable('company_name')">{company_name}</span>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Email Subject</label>
                                                <input type="text" name="invoice_email_subject" class="form-control" value="<?= htmlspecialchars($settings['invoice_email_subject']) ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Email Body</label>
                                                <textarea name="invoice_email_body" class="form-control" rows="6"><?= htmlspecialchars($settings['invoice_email_body']) ?></textarea>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Other Settings Tab -->
                                        <?php if ($active_tab == 'other_settings'): ?>
                                        <div class="tab-pane active" id="other_settings">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="enable_due_date_reminder" 
                                                                   id="enable_due_date_reminder"
                                                                   <?= $settings['enable_due_date_reminder'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="enable_due_date_reminder">Enable Due Date Reminder</label>
                                                        </div>
                                                        <small class="text-muted">Send reminders for overdue invoices</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Reminder Days Before Due</label>
                                                        <input type="number" name="due_date_reminder_days" class="form-control" value="<?= htmlspecialchars($settings['due_date_reminder_days']) ?>" min="1" max="30">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="enable_stock_control" 
                                                                   id="enable_stock_control"
                                                                   <?= $settings['enable_stock_control'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="enable_stock_control">Enable Stock Control</label>
                                                        </div>
                                                        <small class="text-muted">Track inventory and prevent overselling</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Low Stock Threshold</label>
                                                        <input type="number" name="low_stock_threshold" class="form-control" value="<?= htmlspecialchars($settings['low_stock_threshold']) ?>" min="1">
                                                        <small class="text-muted">Alert when stock falls below this level</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Save Button (visible in all tabs) -->
                                        <div class="save-button-container">
                                            <div class="text-end">
                                                <button type="submit" name="update_invoice_settings" class="btn btn-primary">
                                                    <i class="mdi mdi-content-save me-1"></i> Save All Settings
                                                </button>
                                            </div>
                                        </div>
                                    </form>
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
    // Tab switching functionality without page refresh
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('[data-tab]');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('data-tab');
                window.location.href = 'invoice-settings.php?tab=' + tabId;
            });
        });
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 5000);

    // Form validation before submit
    document.getElementById('invoiceSettingsForm')?.addEventListener('submit', function(e) {
        const invoiceStart = document.querySelector('input[name="invoice_start_number"]')?.value;
        if (invoiceStart && parseInt(invoiceStart) < 1) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Invoice start number must be at least 1',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });

    // Insert variable into email body
    function insertVariable(variable) {
        const emailBody = document.querySelector('textarea[name="invoice_email_body"]');
        if (emailBody) {
            const start = emailBody.selectionStart;
            const end = emailBody.selectionEnd;
            const text = emailBody.value;
            const variableText = '{' + variable + '}';
            
            emailBody.value = text.substring(0, start) + variableText + text.substring(end);
            emailBody.focus();
            emailBody.setSelectionRange(start + variableText.length, start + variableText.length);
        }
    }

    // GST number formatting
    const gstInput = document.querySelector('input[name="company_gst"]');
    if (gstInput) {
        gstInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
        });
    }

    // PAN number formatting
    const panInput = document.querySelector('input[name="company_pan"]');
    if (panInput) {
        panInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
        });
    }

    // State code formatting
    const stateCodeInput = document.querySelector('input[name="company_state_code"]');
    if (stateCodeInput) {
        stateCodeInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 2);
        });
    }

    // Pincode formatting
    const pincodeInput = document.querySelector('input[name="company_pincode"]');
    if (pincodeInput) {
        pincodeInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
        });
    }

    // Phone number formatting
    const phoneInput = document.querySelector('input[name="company_phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
        });
    }

    // IFSC code formatting
    const ifscInput = document.querySelector('input[name="bank_ifsc"]');
    if (ifscInput) {
        ifscInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11);
        });
    }

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('invoiceSettingsForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'checkbox') {
                input.addEventListener('change', () => { formDirty = true; });
                input.addEventListener('input', () => { formDirty = true; });
            } else if (input.type === 'checkbox') {
                input.addEventListener('change', () => { formDirty = true; });
            }
        });
        
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
            document.getElementById('invoiceSettingsForm').submit();
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