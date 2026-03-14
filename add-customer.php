<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';



$error = '';
$success = '';

// Generate unique customer code
function generateCustomerCode($pdo) {
    $prefix = 'CUS';
    $year = date('y');
    $month = date('m');
    
    // Get the last customer code
    $stmt = $pdo->query("SELECT customer_code FROM customers WHERE customer_code LIKE 'CUS%' ORDER BY id DESC LIMIT 1");
    $lastCode = $stmt->fetchColumn();
    
    if ($lastCode) {
        // Extract the sequence number (last 4 digits)
        $sequence = intval(substr($lastCode, -4)) + 1;
        $newSequence = str_pad($sequence, 4, '0', STR_PAD_LEFT);
    } else {
        $newSequence = '0001';
    }
    
    return $prefix . $year . $month . $newSequence;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $alternate_phone = trim($_POST['alternate_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $gst_number = trim($_POST['gst_number'] ?? '');
    $pan_number = trim($_POST['pan_number'] ?? '');
    $credit_limit = !empty($_POST['credit_limit']) ? floatval($_POST['credit_limit']) : null;
    $payment_terms = !empty($_POST['payment_terms']) ? intval($_POST['payment_terms']) : 30;
    
    // Validation
    if (empty($name)) {
        $error = "Customer name is required.";
    } elseif (empty($phone)) {
        $error = "Phone number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Please enter a valid 10-digit phone number.";
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!empty($gst_number) && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gst_number)) {
        $error = "Please enter a valid GST number.";
    } elseif (!empty($pan_number) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan_number)) {
        $error = "Please enter a valid PAN number.";
    } elseif (!empty($pincode) && !preg_match('/^[0-9]{6}$/', $pincode)) {
        $error = "Please enter a valid 6-digit pincode.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if phone number already exists
            $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
            $checkStmt->execute([$phone]);
            if ($checkStmt->fetch()) {
                throw new Exception("A customer with this phone number already exists.");
            }
            
            // Check if email already exists (if provided)
            if (!empty($email)) {
                $checkEmailStmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
                $checkEmailStmt->execute([$email]);
                if ($checkEmailStmt->fetch()) {
                    throw new Exception("A customer with this email address already exists.");
                }
            }
            
            // Generate customer code
            $customer_code = generateCustomerCode($pdo);
            
            // Insert customer
            $stmt = $pdo->prepare("
                INSERT INTO customers (
                    customer_code, name, email, phone, alternate_phone, 
                    address, city, state, pincode, gst_number, pan_number, 
                    credit_limit, payment_terms, outstanding_balance, is_active, 
                    created_at, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, 0.00, 1, 
                    NOW(), ?
                )
            ");
            
            $stmt->execute([
                $customer_code, $name, $email, $phone, $alternate_phone,
                $address, $city, $state, $pincode, $gst_number, $pan_number,
                $credit_limit, $payment_terms,
                $_SESSION['user_id']
            ]);
            
            $customer_id = $pdo->lastInsertId();
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (
                    user_id, activity_type_id, description, activity_data, created_at
                ) VALUES (?, 3, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'customer_id' => $customer_id,
                'customer_code' => $customer_code,
                'customer_name' => $name,
                'phone' => $phone,
                'email' => $email
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                'New customer created: ' . $name,
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Customer created successfully. Customer Code: " . $customer_code;
            header("Location: customers.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to create customer: " . $e->getMessage();
            error_log("Customer creation error: " . $e->getMessage());
        }
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Add Customer | Ecommer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Premium Multipurpose Admin & Dashboard Template" name="description" />
    <meta content="Themesbrand" name="author" />
    <!-- App favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico">

    <!-- Bootstrap Css -->
    <link href="assets/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <!-- App Css-->
    <link href="assets/css/app.min.css" id="app-style" rel="stylesheet" type="text/css" />
    
    <!-- Custom CSS for form enhancements -->
    <style>
        .form-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .form-section-title {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #556ee6;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-section-title i {
            color: #556ee6;
            font-size: 18px;
        }
        
        .required-field::after {
            content: " *";
            color: #f46a6a;
            font-weight: bold;
        }
        
        .info-text {
            font-size: 11px;
            color: #74788d;
            margin-top: 4px;
        }
        
        .info-text i {
            font-size: 10px;
            color: #556ee6;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #556ee6 0%, #34c38f 100%);
            color: white;
        }
        
        .btn-custom-primary {
            background: linear-gradient(135deg, #556ee6 0%, #34c38f 100%);
            border: none;
            color: white;
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-custom-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(85, 110, 230, 0.4);
            color: white;
        }
        
        .btn-custom-secondary {
            background: #f8f9fa;
            border: 1px solid #ced4da;
            color: #495057;
            padding: 10px 25px;
            font-weight: 500;
        }
        
        .btn-custom-secondary:hover {
            background: #e9ecef;
            color: #212529;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #556ee6;
            box-shadow: 0 0 0 0.2rem rgba(85, 110, 230, 0.25);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-right: none;
        }
        
        .input-group .form-control {
            border-left: none;
        }
        
        .input-group .form-control:focus {
            border-left: none;
        }
        
        .input-group .form-control:focus + .input-group-text {
            border-color: #556ee6;
        }
        
        /* Loading spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.2em;
        }
        
        /* Validation styles */
        .is-invalid {
            border-color: #f46a6a !important;
        }
        
        .is-invalid:focus {
            box-shadow: 0 0 0 0.2rem rgba(244, 106, 106, 0.25) !important;
        }
        
        .invalid-feedback {
            color: #f46a6a;
            font-size: 11px;
            margin-top: 4px;
        }
    </style>
</head>

<body data-sidebar="dark">

    <!-- Loader -->
    <div id="preloader">
        <div id="status">
            <div class="spinner-chase">
                <div class="chase-dot"></div>
                <div class="chase-dot"></div>
                <div class="chase-dot"></div>
                <div class="chase-dot"></div>
                <div class="chase-dot"></div>
                <div class="chase-dot"></div>
            </div>
        </div>
    </div>

    <!-- Begin page -->
    <div id="layout-wrapper">

        <!-- Topbar -->
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
                                <h4 class="mb-0 font-size-18">Add New Customer</h4>
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Dashboard</a></li>
                                        <li class="breadcrumb-item"><a href="customers.php">Customers</a></li>
                                        <li class="breadcrumb-item active">Add Customer</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- end page title -->

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header card-header-custom">
                                    <h5 class="card-title text-white mb-0">
                                        <i class="mdi mdi-account-plus me-2"></i>
                                        Customer Registration Form
                                    </h5>
                                </div>
                                <div class="card-body">
                                    
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

                                    <form method="POST" action="" id="customerForm" novalidate>
                                        <!-- Basic Information Section -->
                                        <div class="form-section">
                                            <div class="form-section-title">
                                                <i class="mdi mdi-information-outline"></i>
                                                Basic Information
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required-field">
                                                            <i class="mdi mdi-account me-1"></i>
                                                            Customer Name
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-account"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="name" 
                                                                   class="form-control" 
                                                                   placeholder="Enter full name" 
                                                                   required
                                                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-email me-1"></i>
                                                            Email Address
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-email"></i>
                                                            </span>
                                                            <input type="email" 
                                                                   name="email" 
                                                                   class="form-control" 
                                                                   placeholder="customer@example.com"
                                                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            Used for sending invoices and notifications
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required-field">
                                                            <i class="mdi mdi-phone me-1"></i>
                                                            Phone Number
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-phone"></i>
                                                            </span>
                                                            <input type="tel" 
                                                                   name="phone" 
                                                                   class="form-control" 
                                                                   placeholder="10 digit mobile number"
                                                                   maxlength="10"
                                                                   pattern="[0-9]{10}"
                                                                   required
                                                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            Enter 10 digit number without country code
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-phone-in-talk me-1"></i>
                                                            Alternate Phone
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-phone-in-talk"></i>
                                                            </span>
                                                            <input type="tel" 
                                                                   name="alternate_phone" 
                                                                   class="form-control" 
                                                                   placeholder="Alternate contact number"
                                                                   maxlength="10"
                                                                   pattern="[0-9]{10}"
                                                                   value="<?= htmlspecialchars($_POST['alternate_phone'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Address Section -->
                                        <div class="form-section">
                                            <div class="form-section-title">
                                                <i class="mdi mdi-map-marker"></i>
                                                Address Information
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    <i class="mdi mdi-home me-1"></i>
                                                    Address
                                                </label>
                                                <textarea name="address" 
                                                          class="form-control" 
                                                          rows="3"
                                                          placeholder="Street address, building, area"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-city me-1"></i>
                                                            City
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-city"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="city" 
                                                                   class="form-control" 
                                                                   placeholder="City"
                                                                   value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-map me-1"></i>
                                                            State
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-map"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="state" 
                                                                   class="form-control" 
                                                                   placeholder="State"
                                                                   value="<?= htmlspecialchars($_POST['state'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-mailbox me-1"></i>
                                                            Pincode
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-mailbox"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="pincode" 
                                                                   class="form-control" 
                                                                   placeholder="6 digit pincode"
                                                                   maxlength="6"
                                                                   pattern="[0-9]{6}"
                                                                   value="<?= htmlspecialchars($_POST['pincode'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Tax Information Section -->
                                        <div class="form-section">
                                            <div class="form-section-title">
                                                <i class="mdi mdi-file-document"></i>
                                                Tax Information
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-card-account-details me-1"></i>
                                                            GST Number
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-card-account-details"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="gst_number" 
                                                                   class="form-control" 
                                                                   placeholder="22AAAAA0000A1Z5"
                                                                   maxlength="15"
                                                                   value="<?= htmlspecialchars($_POST['gst_number'] ?? '') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            15 characters: 2 digits + 5 letters + 4 digits + 1 letter + 1 alphanumeric + Z + 1 alphanumeric
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-card-account-details-outline me-1"></i>
                                                            PAN Number
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-card-account-details-outline"></i>
                                                            </span>
                                                            <input type="text" 
                                                                   name="pan_number" 
                                                                   class="form-control" 
                                                                   placeholder="AAAAA0000A"
                                                                   maxlength="10"
                                                                   value="<?= htmlspecialchars($_POST['pan_number'] ?? '') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            10 characters: 5 letters + 4 digits + 1 letter
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Business Terms Section -->
                                        <div class="form-section">
                                            <div class="form-section-title">
                                                <i class="mdi mdi-handshake"></i>
                                                Business Terms
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-credit-card me-1"></i>
                                                            Credit Limit (₹)
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-currency-inr"></i>
                                                            </span>
                                                            <input type="number" 
                                                                   name="credit_limit" 
                                                                   class="form-control" 
                                                                   placeholder="Enter credit limit"
                                                                   min="0"
                                                                   step="0.01"
                                                                   value="<?= htmlspecialchars($_POST['credit_limit'] ?? '') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            Leave empty for no credit limit
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            <i class="mdi mdi-calendar-clock me-1"></i>
                                                            Payment Terms (Days)
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">
                                                                <i class="mdi mdi-calendar"></i>
                                                            </span>
                                                            <input type="number" 
                                                                   name="payment_terms" 
                                                                   class="form-control" 
                                                                   placeholder="30"
                                                                   min="0"
                                                                   max="365"
                                                                   value="<?= htmlspecialchars($_POST['payment_terms'] ?? '30') ?>">
                                                        </div>
                                                        <div class="info-text">
                                                            <i class="mdi mdi-information"></i>
                                                            Number of days for payment (default: 30)
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Form Actions -->
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="text-end">
                                                    <button type="button" class="btn btn-custom-secondary me-2" onclick="window.location.href='customers.php'">
                                                        <i class="mdi mdi-cancel me-1"></i>
                                                        Cancel
                                                    </button>
                                                    <button type="reset" class="btn btn-danger me-2" id="resetBtn">
                                                        <i class="mdi mdi-undo me-1"></i>
                                                        Reset
                                                    </button>
                                                    <button type="submit" class="btn btn-custom-primary" id="submitBtn">
                                                        <i class="mdi mdi-content-save me-1"></i>
                                                        <span id="btnText">Save Customer</span>
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
                    </div>
                    <!-- end row -->

                </div> <!-- container-fluid -->
            </div>
            <!-- End Page-content -->

            <!-- Footer -->
            <?php include('includes/footer.php'); ?>
        </div>
        <!-- end main content-->
    </div>
    <!-- END layout-wrapper -->

    <!-- Right Sidebar -->
    <?php include('includes/rightbar.php'); ?>

    <!-- JAVASCRIPT -->
    <script src="assets/libs/jquery/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/libs/metismenu/metisMenu.min.js"></script>
    <script src="assets/libs/simplebar/simplebar.min.js"></script>
    <script src="assets/libs/node-waves/waves.min.js"></script>
    <script src="assets/js/app.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Form submission loading state
        document.getElementById('customerForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const loading = document.getElementById('loading');
            
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        });
        
        // Reset button confirmation
        document.getElementById('resetBtn').addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to reset all fields? This action cannot be undone.')) {
                document.getElementById('customerForm').reset();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
        
        // Phone number validation and formatting
        const phoneInput = document.querySelector('input[name="phone"]');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
            });
        }
        
        const alternatePhoneInput = document.querySelector('input[name="alternate_phone"]');
        if (alternatePhoneInput) {
            alternatePhoneInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
            });
        }
        
        // Pincode validation
        const pincodeInput = document.querySelector('input[name="pincode"]');
        if (pincodeInput) {
            pincodeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }
        
        // GST number formatting
        const gstInput = document.querySelector('input[name="gst_number"]');
        if (gstInput) {
            gstInput.addEventListener('input', function(e) {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
            });
        }
        
        // PAN number formatting
        const panInput = document.querySelector('input[name="pan_number"]');
        if (panInput) {
            panInput.addEventListener('input', function(e) {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
            });
        }
        
        // Email validation
        const emailInput = document.querySelector('input[name="email"]');
        if (emailInput) {
            emailInput.addEventListener('blur', function(e) {
                const email = this.value;
                if (email && !isValidEmail(email)) {
                    this.classList.add('is-invalid');
                    showInvalidFeedback(this, 'Please enter a valid email address');
                } else {
                    this.classList.remove('is-invalid');
                    removeInvalidFeedback(this);
                }
            });
        }
        
        // Helper function for email validation
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Show invalid feedback
        function showInvalidFeedback(element, message) {
            removeInvalidFeedback(element);
            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.innerHTML = '<i class="mdi mdi-alert-circle"></i> ' + message;
            element.closest('.mb-3').appendChild(feedback);
        }
        
        // Remove invalid feedback
        function removeInvalidFeedback(element) {
            const parent = element.closest('.mb-3');
            const existing = parent.querySelector('.invalid-feedback');
            if (existing) {
                existing.remove();
            }
        }
        
        // Auto-capitalize name
        const nameInput = document.querySelector('input[name="name"]');
        if (nameInput) {
            nameInput.addEventListener('input', function(e) {
                // Capitalize first letter of each word
                this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
            });
        }
        
        // Credit limit validation
        const creditLimitInput = document.querySelector('input[name="credit_limit"]');
        if (creditLimitInput) {
            creditLimitInput.addEventListener('input', function(e) {
                if (this.value < 0) {
                    this.value = 0;
                }
            });
        }
        
        // Payment terms validation
        const paymentTermsInput = document.querySelector('input[name="payment_terms"]');
        if (paymentTermsInput) {
            paymentTermsInput.addEventListener('input', function(e) {
                let value = parseInt(this.value) || 0;
                if (value < 0) value = 0;
                if (value > 365) value = 365;
                this.value = value;
            });
        }
        
        // Prevent double form submission
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const submitButtons = form.querySelectorAll('button[type="submit"]');
                submitButtons.forEach(button => {
                    button.disabled = true;
                });
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to submit form
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('customerForm').submit();
            }
            
            // Escape to reset
            if (e.key === 'Escape') {
                e.preventDefault();
                if (confirm('Are you sure you want to reset all fields?')) {
                    document.getElementById('customerForm').reset();
                }
            }
        });
        
        // Form dirty check
        let formDirty = false;
        const form = document.getElementById('customerForm');
        const formInputs = form.querySelectorAll('input, textarea, select');
        
        formInputs.forEach(input => {
            input.addEventListener('change', () => {
                formDirty = true;
            });
            input.addEventListener('input', () => {
                formDirty = true;
            });
        });
        
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // Reset dirty flag on form submit
        form.addEventListener('submit', () => {
            formDirty = false;
        });
        
        // Add tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>