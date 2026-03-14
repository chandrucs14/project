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

// Define upload directory for company logo
$upload_dir = 'assets/uploads/company/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update GST settings
    if (isset($_POST['update_gst_settings'])) {
        try {
            $pdo->beginTransaction();
            
            // Handle company logo upload
            $company_logo = '';
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $_FILES['company_logo']['tmp_name']);
                finfo_close($file_info);
                
                if (!in_array($mime_type, $allowed_types)) {
                    throw new Exception("Only JPG, PNG, GIF, and WEBP images are allowed.");
                }
                
                if ($_FILES['company_logo']['size'] > 2 * 1024 * 1024) { // 2MB limit
                    throw new Exception("Logo size must be less than 2MB.");
                }
                
                $extension = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
                $filename = 'company_logo_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $filepath)) {
                    $company_logo = $filepath;
                    
                    // Delete old logo if exists
                    $oldLogoStmt = $pdo->prepare("SELECT setting_value FROM gst_settings WHERE setting_key = 'company_logo'");
                    $oldLogoStmt->execute();
                    $oldLogo = $oldLogoStmt->fetchColumn();
                    
                    if ($oldLogo && file_exists($oldLogo) && $oldLogo != $company_logo) {
                        unlink($oldLogo);
                    }
                }
            }
            
            // Array of settings to update
            $settings = [
                // Company Details
                'company_name' => trim($_POST['company_name'] ?? ''),
                'company_address' => trim($_POST['company_address'] ?? ''),
                'company_city' => trim($_POST['company_city'] ?? ''),
                'company_state' => trim($_POST['company_state'] ?? ''),
                'company_pincode' => trim($_POST['company_pincode'] ?? ''),
                'company_phone' => trim($_POST['company_phone'] ?? ''),
                'company_email' => trim($_POST['company_email'] ?? ''),
                'company_website' => trim($_POST['company_website'] ?? ''),
                
                // GST Details
                'company_gst' => strtoupper(trim($_POST['company_gst'] ?? '')),
                'company_pan' => strtoupper(trim($_POST['company_pan'] ?? '')),
                'company_state_code' => trim($_POST['company_state_code'] ?? ''),
                'gst_registration_type' => $_POST['gst_registration_type'] ?? 'regular',
                'gst_applicable' => isset($_POST['gst_applicable']) ? '1' : '0',
                'composition_scheme' => isset($_POST['composition_scheme']) ? '1' : '0',
                'reverse_charge' => isset($_POST['reverse_charge']) ? '1' : '0',
                'ecommerce_operator' => isset($_POST['ecommerce_operator']) ? '1' : '0',
                
                // Invoice Settings
                'invoice_prefix' => trim($_POST['invoice_prefix'] ?? 'INV-'),
                'invoice_start_number' => intval($_POST['invoice_start_number'] ?? 1001),
                'show_gst' => isset($_POST['show_gst']) ? '1' : '0',
                'show_hsn' => isset($_POST['show_hsn']) ? '1' : '0',
                'show_cgst_sgst' => isset($_POST['show_cgst_sgst']) ? '1' : '0',
                'tax_calculation_method' => $_POST['tax_calculation_method'] ?? 'exclusive',
                'rounding_method' => $_POST['rounding_method'] ?? 'normal',
                'default_gst_rate' => floatval($_POST['default_gst_rate'] ?? 18),
                'invoice_footer_text' => trim($_POST['invoice_footer_text'] ?? ''),
                'invoice_terms_conditions' => trim($_POST['invoice_terms_conditions'] ?? ''),
                
                // Bank Details
                'bank_name' => trim($_POST['bank_name'] ?? ''),
                'bank_account_no' => trim($_POST['bank_account_no'] ?? ''),
                'bank_ifsc' => trim($_POST['bank_ifsc'] ?? ''),
                'bank_branch' => trim($_POST['bank_branch'] ?? ''),
                'upi_id' => trim($_POST['upi_id'] ?? '')
            ];
            
            // Add logo to settings if uploaded
            if ($company_logo) {
                $settings['company_logo'] = $company_logo;
            }
            
            // Update each setting
            foreach ($settings as $key => $value) {
                // Determine setting type
                $setting_type = 'text';
                if (strpos($key, 'is_') === 0 || in_array($key, ['gst_applicable', 'composition_scheme', 'reverse_charge', 'ecommerce_operator', 'show_gst', 'show_hsn', 'show_cgst_sgst'])) {
                    $setting_type = 'boolean';
                } elseif (in_array($key, ['invoice_start_number', 'default_gst_rate'])) {
                    $setting_type = 'number';
                } elseif ($key === 'company_logo') {
                    $setting_type = 'image';
                }
                
                // Check if setting exists
                $checkStmt = $pdo->prepare("SELECT id FROM gst_settings WHERE setting_key = ?");
                $checkStmt->execute([$key]);
                
                if ($checkStmt->fetch()) {
                    // Update existing setting
                    $stmt = $pdo->prepare("
                        UPDATE gst_settings 
                        SET setting_value = ?, setting_type = ?, updated_by = ?, updated_at = NOW() 
                        WHERE setting_key = ?
                    ");
                    $stmt->execute([$value, $setting_type, $_SESSION['user_id'], $key]);
                } else {
                    // Insert new setting
                    $descriptions = [
                        'company_name' => 'Company Name',
                        'company_address' => 'Company Address',
                        'company_city' => 'Company City',
                        'company_state' => 'Company State',
                        'company_pincode' => 'Company Pincode',
                        'company_phone' => 'Company Phone',
                        'company_email' => 'Company Email',
                        'company_website' => 'Company Website',
                        'company_logo' => 'Company Logo',
                        'company_gst' => 'Company GST Number',
                        'company_pan' => 'Company PAN Number',
                        'company_state_code' => 'Company State Code',
                        'gst_registration_type' => 'GST Registration Type',
                        'gst_applicable' => 'Is GST Applicable',
                        'composition_scheme' => 'Under Composition Scheme',
                        'reverse_charge' => 'Reverse Charge Applicable',
                        'ecommerce_operator' => 'E-commerce Operator',
                        'invoice_prefix' => 'Invoice Prefix',
                        'invoice_start_number' => 'Invoice Start Number',
                        'show_gst' => 'Show GST on Invoice',
                        'show_hsn' => 'Show HSN Code on Invoice',
                        'show_cgst_sgst' => 'Show CGST/SGST Breakup',
                        'tax_calculation_method' => 'Tax Calculation Method',
                        'rounding_method' => 'Rounding Method',
                        'default_gst_rate' => 'Default GST Rate',
                        'invoice_footer_text' => 'Invoice Footer Text',
                        'invoice_terms_conditions' => 'Invoice Terms & Conditions',
                        'bank_name' => 'Bank Name',
                        'bank_account_no' => 'Bank Account Number',
                        'bank_ifsc' => 'Bank IFSC Code',
                        'bank_branch' => 'Bank Branch',
                        'upi_id' => 'UPI ID'
                    ];
                    
                    $description = $descriptions[$key] ?? $key;
                    $stmt = $pdo->prepare("
                        INSERT INTO gst_settings (setting_key, setting_value, setting_type, description, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$key, $value, $setting_type, $description, $_SESSION['user_id']]);
                }
            }
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'action' => 'update_gst_settings',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                'GST settings updated',
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "GST settings updated successfully.";
            header("Location: gst-settings.php");
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to update GST settings: " . $e->getMessage();
            error_log("GST settings update error: " . $e->getMessage());
        }
    }
    
    // Handle GST rate management
    if (isset($_POST['add_gst_rate'])) {
        $gst_rate = floatval($_POST['gst_rate'] ?? 0);
        $hsn_code = trim($_POST['hsn_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($gst_rate <= 0) {
            $error = "GST rate must be greater than 0.";
        } elseif (empty($hsn_code)) {
            $error = "HSN code is required.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Check if GST rate already exists
                $checkStmt = $pdo->prepare("SELECT id FROM gst_details WHERE gst_rate = ?");
                $checkStmt->execute([$gst_rate]);
                if ($checkStmt->fetch()) {
                    throw new Exception("GST rate $gst_rate% already exists.");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO gst_details (gst_rate, hsn_code, description, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $result = $stmt->execute([$gst_rate, $hsn_code, $description ?: null, $is_active, $_SESSION['user_id']]);
                
                if ($result) {
                    $pdo->commit();
                    $_SESSION['success_message'] = "GST rate added successfully.";
                }
                
                header("Location: gst-settings.php");
                exit();
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch current GST settings from gst_settings table
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM gst_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Set default values if not found
$default_settings = [
    // Company Details
    'company_name' => 'Your Company Name',
    'company_address' => '',
    'company_city' => '',
    'company_state' => '',
    'company_pincode' => '',
    'company_phone' => '',
    'company_email' => '',
    'company_website' => '',
    'company_logo' => '',
    
    // GST Details
    'company_gst' => '',
    'company_pan' => '',
    'company_state_code' => '',
    'gst_registration_type' => 'regular',
    'gst_applicable' => '1',
    'composition_scheme' => '0',
    'reverse_charge' => '0',
    'ecommerce_operator' => '0',
    
    // Invoice Settings
    'invoice_prefix' => 'INV-',
    'invoice_start_number' => '1001',
    'show_gst' => '1',
    'show_hsn' => '1',
    'show_cgst_sgst' => '1',
    'tax_calculation_method' => 'exclusive',
    'rounding_method' => 'normal',
    'default_gst_rate' => '18',
    'invoice_footer_text' => 'Thank you for your business!',
    'invoice_terms_conditions' => '1. Goods once sold will not be taken back.\n2. Interest @ 24% p.a. will be charged on delayed payments.',
    
    // Bank Details
    'bank_name' => '',
    'bank_account_no' => '',
    'bank_ifsc' => '',
    'bank_branch' => '',
    'upi_id' => ''
];

foreach ($default_settings as $key => $default) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default;
    }
}

// Fetch GST rates for display
$gstStmt = $pdo->query("SELECT * FROM gst_details ORDER BY gst_rate");
$gstRates = $gstStmt->fetchAll();

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_rates,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rates,
        MIN(gst_rate) as min_rate,
        MAX(gst_rate) as max_rate
    FROM gst_details
");
$stats = $statsStmt->fetch();

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .logo-preview {
            width: 200px;
            height: 100px;
            object-fit: contain;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            background: #f8f9fa;
            margin-top: 10px;
            display: block;
        }
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
        .file-upload-wrapper {
            position: relative;
            margin-bottom: 10px;
        }
        .file-upload-input {
            position: relative;
            z-index: 2;
            width: 100%;
            height: calc(1.5em + 0.75rem + 2px);
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }
        .file-upload-button {
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1;
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-weight: 400;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            pointer-events: none;
        }
        .file-upload-wrapper:hover .file-upload-button {
            background-color: #e9ecef;
        }
        .btn-file-remove {
            margin-top: 10px;
        }
        .tab-pane {
            display: none;
            padding: 20px 0;
        }
        .tab-pane.active {
            display: block;
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
                            <h4 class="mb-0 font-size-18">GST Settings</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">GST Settings</li>
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

                <!-- GST Statistics -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total_rates'] ?? 0) ?></h3>
                                Total GST Rates
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['active_rates'] ?? 0) ?></h3>
                                Active Rates
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2"><?= $stats['min_rate'] ? $stats['min_rate'] . '%' : 'N/A' ?></h3>
                                Minimum Rate
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= $stats['max_rate'] ? $stats['max_rate'] . '%' : 'N/A' ?></h3>
                                Maximum Rate
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- GST Settings Tabs -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <!-- Tab Navigation -->
                                <ul class="nav nav-tabs nav-tabs-custom mb-4" id="gstTabs" role="tablist">
                                    <li class="nav-item">
                                        <button class="nav-link active" data-tab="company_details" role="tab">
                                            <i class="mdi mdi-domain"></i> Company Details
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" data-tab="gst_details" role="tab">
                                            <i class="mdi mdi-card-account-details"></i> GST Details
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" data-tab="gst_rates" role="tab">
                                            <i class="mdi mdi-percent"></i> GST Rates
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" data-tab="invoice_settings" role="tab">
                                            <i class="mdi mdi-file-document"></i> Invoice Settings
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" data-tab="bank_details" role="tab">
                                            <i class="mdi mdi-bank"></i> Bank Details
                                        </button>
                                    </li>
                                </ul>

                                <!-- Tab Content -->
                                <div class="tab-content">
                                    <form method="POST" action="" id="gstSettingsForm" enctype="multipart/form-data">
                                        
                                        <!-- Company Details Tab -->
                                        <div class="tab-pane active" id="company_details" data-tab-content="company_details">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-domain"></i></span>
                                                            <input type="text" 
                                                                   name="company_name" 
                                                                   class="form-control" 
                                                                   placeholder="Enter company name"
                                                                   value="<?= htmlspecialchars($settings['company_name']) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Company Logo</label>
                                                        <div class="file-upload-wrapper">
                                                            <input type="file" 
                                                                   name="company_logo" 
                                                                   id="company_logo"
                                                                   class="file-upload-input" 
                                                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                                                   onchange="previewLogo(this)">
                                                            <div class="file-upload-button">
                                                                <i class="mdi mdi-upload me-1"></i> Choose File
                                                            </div>
                                                        </div>
                                                        <small class="text-muted">Max size: 2MB. Allowed: JPG, PNG, GIF, WEBP</small>
                                                        
                                                        <div id="logo-preview-container">
                                                            <?php if (!empty($settings['company_logo']) && file_exists($settings['company_logo'])): ?>
                                                                <div class="mt-2">
                                                                    <img src="<?= htmlspecialchars($settings['company_logo']) ?>" alt="Company Logo" class="logo-preview" id="logo-preview">
                                                                    <button type="button" class="btn btn-sm btn-danger mt-2 btn-file-remove" onclick="removeLogo()">
                                                                        <i class="mdi mdi-delete"></i> Remove Logo
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="mt-2" id="logo-preview-placeholder" style="display: none;">
                                                                    <img src="" alt="Logo Preview" class="logo-preview" id="logo-preview">
                                                                    <button type="button" class="btn btn-sm btn-danger mt-2 btn-file-remove" onclick="removeLogoPreview()">
                                                                        <i class="mdi mdi-delete"></i> Remove
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Company Address</label>
                                                <textarea name="company_address" class="form-control" rows="2" placeholder="Enter company address"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">City</label>
                                                        <input type="text" name="company_city" class="form-control" placeholder="City" value="<?= htmlspecialchars($settings['company_city']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">State</label>
                                                        <input type="text" name="company_state" class="form-control" placeholder="State" value="<?= htmlspecialchars($settings['company_state']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Pincode</label>
                                                        <input type="text" name="company_pincode" class="form-control" placeholder="Pincode" maxlength="6" value="<?= htmlspecialchars($settings['company_pincode']) ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Phone Number</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-phone"></i></span>
                                                            <input type="tel" name="company_phone" class="form-control" placeholder="Phone number" value="<?= htmlspecialchars($settings['company_phone']) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Email Address</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-email"></i></span>
                                                            <input type="email" name="company_email" class="form-control" placeholder="Email address" value="<?= htmlspecialchars($settings['company_email']) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Website</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-web"></i></span>
                                                    <input type="url" name="company_website" class="form-control" placeholder="https://www.example.com" value="<?= htmlspecialchars($settings['company_website']) ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- GST Details Tab -->
                                        <div class="tab-pane" id="gst_details" data-tab-content="gst_details">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">GST Number</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-card-account-details"></i></span>
                                                            <input type="text" 
                                                                   name="company_gst" 
                                                                   class="form-control" 
                                                                   placeholder="22AAAAA0000A1Z5"
                                                                   maxlength="15"
                                                                   value="<?= htmlspecialchars($settings['company_gst']) ?>">
                                                        </div>
                                                        <small class="text-muted">Format: 22AAAAA0000A1Z5 (15 characters)</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">PAN Number</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-card-account-details-outline"></i></span>
                                                            <input type="text" 
                                                                   name="company_pan" 
                                                                   class="form-control" 
                                                                   placeholder="AAAAA0000A"
                                                                   maxlength="10"
                                                                   value="<?= htmlspecialchars($settings['company_pan']) ?>">
                                                        </div>
                                                        <small class="text-muted">Format: AAAAA0000A (10 characters)</small>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">State Code</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-map-marker"></i></span>
                                                            <input type="text" 
                                                                   name="company_state_code" 
                                                                   class="form-control" 
                                                                   placeholder="e.g., 22"
                                                                   maxlength="2"
                                                                   value="<?= htmlspecialchars($settings['company_state_code']) ?>">
                                                        </div>
                                                        <small class="text-muted">2-digit state code (e.g., 22 for Tamil Nadu)</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Registration Type</label>
                                                        <select name="gst_registration_type" class="form-control">
                                                            <option value="regular" <?= $settings['gst_registration_type'] == 'regular' ? 'selected' : '' ?>>Regular</option>
                                                            <option value="composition" <?= $settings['gst_registration_type'] == 'composition' ? 'selected' : '' ?>>Composition</option>
                                                            <option value="casual" <?= $settings['gst_registration_type'] == 'casual' ? 'selected' : '' ?>>Casual</option>
                                                            <option value="non_resident" <?= $settings['gst_registration_type'] == 'non_resident' ? 'selected' : '' ?>>Non-Resident</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="gst_applicable" 
                                                                   id="gst_applicable"
                                                                   <?= $settings['gst_applicable'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="gst_applicable">GST Applicable</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="composition_scheme" 
                                                                   id="composition_scheme"
                                                                   <?= $settings['composition_scheme'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="composition_scheme">Composition Scheme</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="reverse_charge" 
                                                                   id="reverse_charge"
                                                                   <?= $settings['reverse_charge'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="reverse_charge">Reverse Charge</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch form-switch-md">
                                                            <input type="checkbox" 
                                                                   class="form-check-input" 
                                                                   name="ecommerce_operator" 
                                                                   id="ecommerce_operator"
                                                                   <?= $settings['ecommerce_operator'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="ecommerce_operator">E-commerce Operator</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- GST Rates Tab -->
                                        <div class="tab-pane" id="gst_rates" data-tab-content="gst_rates">
                                            <div class="row mb-3">
                                                <div class="col-md-8">
                                                    <h5 class="font-size-14">Manage GST Rates</h5>
                                                    <p class="card-title-desc">Add and manage GST rates for your products</p>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addGstRateModal">
                                                        <i class="mdi mdi-plus me-1"></i> Add GST Rate
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="table-responsive">
                                                <table class="table table-centered table-nowrap mb-0">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th>GST Rate</th>
                                                            <th>HSN Code</th>
                                                            <th>Description</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($gstRates)): ?>
                                                            <tr>
                                                                <td colspan="5" class="text-center text-muted">No GST rates added yet</td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($gstRates as $rate): ?>
                                                                <tr>
                                                                    <td><strong><?= $rate['gst_rate'] ?>%</strong></td>
                                                                    <td><span class="badge bg-soft-info text-info"><?= htmlspecialchars($rate['hsn_code']) ?></span></td>
                                                                    <td><?= htmlspecialchars($rate['description'] ?? 'N/A') ?></td>
                                                                    <td>
                                                                        <?php if ($rate['is_active']): ?>
                                                                            <span class="badge bg-soft-success text-success">Active</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <a href="edit-gst.php?id=<?= $rate['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                                            <i class="mdi mdi-pencil"></i>
                                                                        </a>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Invoice Settings Tab -->
                                        <div class="tab-pane" id="invoice_settings" data-tab-content="invoice_settings">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Invoice Prefix</label>
                                                        <input type="text" name="invoice_prefix" class="form-control" value="<?= htmlspecialchars($settings['invoice_prefix']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Invoice Start Number</label>
                                                        <input type="number" name="invoice_start_number" class="form-control" value="<?= htmlspecialchars($settings['invoice_start_number']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Default GST Rate (%)</label>
                                                        <input type="number" name="default_gst_rate" class="form-control" min="0" max="100" step="0.01" value="<?= htmlspecialchars($settings['default_gst_rate']) ?>">
                                                    </div>
                                                </div>
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
                                                            <label class="form-check-label" for="show_gst">Show GST on Invoice</label>
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
                                                            <label class="form-check-label" for="show_hsn">Show HSN Code</label>
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
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Tax Calculation Method</label>
                                                        <select name="tax_calculation_method" class="form-control">
                                                            <option value="exclusive" <?= $settings['tax_calculation_method'] == 'exclusive' ? 'selected' : '' ?>>Exclusive (Add tax to price)</option>
                                                            <option value="inclusive" <?= $settings['tax_calculation_method'] == 'inclusive' ? 'selected' : '' ?>>Inclusive (Tax included in price)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Rounding Method</label>
                                                        <select name="rounding_method" class="form-control">
                                                            <option value="normal" <?= $settings['rounding_method'] == 'normal' ? 'selected' : '' ?>>Normal Rounding</option>
                                                            <option value="floor" <?= $settings['rounding_method'] == 'floor' ? 'selected' : '' ?>>Round Down (Floor)</option>
                                                            <option value="ceil" <?= $settings['rounding_method'] == 'ceil' ? 'selected' : '' ?>>Round Up (Ceil)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Invoice Footer Text</label>
                                                <textarea name="invoice_footer_text" class="form-control" rows="2"><?= htmlspecialchars($settings['invoice_footer_text']) ?></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Terms & Conditions</label>
                                                <textarea name="invoice_terms_conditions" class="form-control" rows="3"><?= htmlspecialchars($settings['invoice_terms_conditions']) ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Bank Details Tab -->
                                        <div class="tab-pane" id="bank_details" data-tab-content="bank_details">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Bank Name</label>
                                                        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($settings['bank_name']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Account Number</label>
                                                        <input type="text" name="bank_account_no" class="form-control" value="<?= htmlspecialchars($settings['bank_account_no']) ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">IFSC Code</label>
                                                        <input type="text" name="bank_ifsc" class="form-control" value="<?= htmlspecialchars($settings['bank_ifsc']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Branch</label>
                                                        <input type="text" name="bank_branch" class="form-control" value="<?= htmlspecialchars($settings['bank_branch']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">UPI ID</label>
                                                        <input type="text" name="upi_id" class="form-control" value="<?= htmlspecialchars($settings['upi_id']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Save Button (visible in all tabs) -->
                                        <div class="save-button-container">
                                            <div class="text-end">
                                                <button type="submit" name="update_gst_settings" class="btn btn-primary">
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

<!-- Add GST Rate Modal -->
<div class="modal fade" id="addGstRateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-percent me-2"></i>Add GST Rate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">GST Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" name="gst_rate" class="form-control" placeholder="e.g., 5, 12, 18, 28" min="0" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">HSN/SAC Code <span class="text-danger">*</span></label>
                        <input type="text" name="hsn_code" class="form-control" placeholder="e.g., 2517, 6810, 7214" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Enter description"></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" name="is_active" id="modal_is_active" checked>
                            <label class="form-check-label" for="modal_is_active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_gst_rate" class="btn btn-success">Add GST Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Auto-hide alerts
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 500);
        });
    }, 5000);

    // Tab switching functionality without page refresh
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('[data-tab]');
        const tabPanes = document.querySelectorAll('[data-tab-content]');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all tabs
                tabs.forEach(t => t.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Get the tab id
                const tabId = this.getAttribute('data-tab');
                
                // Hide all tab panes
                tabPanes.forEach(pane => {
                    pane.classList.remove('active');
                });
                
                // Show the selected tab pane
                const activePane = document.querySelector(`[data-tab-content="${tabId}"]`);
                if (activePane) {
                    activePane.classList.add('active');
                }
            });
        });
    });

    // Logo preview function
    function previewLogo(input) {
        const previewContainer = document.getElementById('logo-preview-placeholder');
        const preview = document.getElementById('logo-preview');
        const fileUploadButton = document.querySelector('.file-upload-button');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.src = e.target.result;
                previewContainer.style.display = 'block';
                fileUploadButton.innerHTML = '<i class="mdi mdi-file me-1"></i> ' + input.files[0].name;
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Remove logo preview
    function removeLogoPreview() {
        const input = document.getElementById('company_logo');
        const previewContainer = document.getElementById('logo-preview-placeholder');
        const fileUploadButton = document.querySelector('.file-upload-button');
        
        input.value = '';
        previewContainer.style.display = 'none';
        fileUploadButton.innerHTML = '<i class="mdi mdi-upload me-1"></i> Choose File';
    }

    // Remove existing logo
    function removeLogo() {
        Swal.fire({
            title: 'Remove Logo?',
            text: 'Are you sure you want to remove the company logo?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, remove it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Here you would typically make an AJAX call to remove the logo
                // For now, we'll just hide it and you can handle it in form submission
                document.querySelector('#logo-preview-container').innerHTML = `
                    <div class="mt-2" id="logo-preview-placeholder" style="display: none;">
                        <img src="" alt="Logo Preview" class="logo-preview" id="logo-preview">
                        <button type="button" class="btn btn-sm btn-danger mt-2 btn-file-remove" onclick="removeLogoPreview()">
                            <i class="mdi mdi-delete"></i> Remove
                        </button>
                    </div>
                `;
                
                Swal.fire({
                    title: 'Removed!',
                    text: 'Logo has been removed.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
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

    // Form validation before submit
    document.getElementById('gstSettingsForm')?.addEventListener('submit', function(e) {
        const gstValue = gstInput?.value;
        const panValue = panInput?.value;
        
        if (gstValue && gstValue.length !== 15 && gstValue.length > 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Invalid GST Number',
                text: 'GST number must be 15 characters long',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        if (panValue && panValue.length !== 10 && panValue.length > 0) {
            e.preventDefault();
            Swal.fire({
                title: 'Invalid PAN Number',
                text: 'PAN number must be 10 characters long',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });

    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('gstSettingsForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            if (input.type !== 'file' && input.type !== 'checkbox') {
                input.addEventListener('change', () => { formDirty = true; });
                input.addEventListener('input', () => { formDirty = true; });
            } else if (input.type === 'checkbox') {
                input.addEventListener('change', () => { formDirty = true; });
            } else if (input.type === 'file') {
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
            document.getElementById('gstSettingsForm').submit();
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