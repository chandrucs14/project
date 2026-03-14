<?php
date_default_timezone_set('Asia/Kolkata');


// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Check if customer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage-customers.php");
    exit();
}

$customer_id = (int)$_GET['id'];
$error = '';
$success = '';

// Get customer details
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        header("Location: manage-customers.php");
        exit();
    }
} catch (Exception $e) {
    $error = "Failed to fetch customer details: " . $e->getMessage();
    error_log("Customer edit error: " . $e->getMessage());
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
    $payment_terms = !empty($_POST['payment_terms']) ? intval($_POST['payment_terms']) : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
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
            
            // Check if phone number already exists for another customer
            $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? AND id != ?");
            $checkStmt->execute([$phone, $customer_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("Another customer with this phone number already exists.");
            }
            
            // Check if email already exists for another customer (if provided)
            if (!empty($email)) {
                $checkEmailStmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
                $checkEmailStmt->execute([$email, $customer_id]);
                if ($checkEmailStmt->fetch()) {
                    throw new Exception("Another customer with this email address already exists.");
                }
            }
            
            // Update customer
            $stmt = $pdo->prepare("
                UPDATE customers SET
                    name = ?, email = ?, phone = ?, alternate_phone = ?,
                    address = ?, city = ?, state = ?, pincode = ?,
                    gst_number = ?, pan_number = ?, credit_limit = ?,
                    payment_terms = ?, is_active = ?, updated_at = NOW(),
                    updated_by = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $name, 
                $email ?: null, 
                $phone, 
                $alternate_phone ?: null,
                $address ?: null, 
                $city ?: null, 
                $state ?: null, 
                $pincode ?: null,
                $gst_number ?: null, 
                $pan_number ?: null, 
                $credit_limit,
                $payment_terms,
                $is_active,
                $_SESSION['user_id'],
                $customer_id
            ]);
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (
                    user_id, activity_type_id, description, activity_data, created_at
                ) VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'customer_id' => $customer_id,
                'customer_name' => $name,
                'updated_fields' => [
                    'name' => $name !== $customer['name'],
                    'phone' => $phone !== $customer['phone'],
                    'email' => $email !== $customer['email']
                ]
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                'Customer updated: ' . $name,
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Customer updated successfully.";
            header("Location: view-customer.php?id=" . $customer_id);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update customer: " . $e->getMessage();
            error_log("Customer update error: " . $e->getMessage());
        }
    }
}

// Helper function to safely echo values with null check
function safe_echo($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

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
                            <h4 class="mb-0 font-size-18">Edit Customer</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-customers.php">Customers</a></li>
                                    <li class="breadcrumb-item"><a href="view-customer.php?id=<?= $customer_id ?>">View Customer</a></li>
                                    <li class="breadcrumb-item active">Edit Customer</li>
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
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Edit Customer Information</h4>
                                <p class="card-title-desc">Update customer details and information</p>

                                <form method="POST" action="" id="customerForm">
                                    <!-- Basic Information -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-account"></i></span>
                                                    <input type="text" 
                                                           name="name" 
                                                           class="form-control" 
                                                           placeholder="Enter full name" 
                                                           required
                                                           value="<?= safe_echo($_POST['name'] ?? $customer['name']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email Address</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-email"></i></span>
                                                    <input type="email" 
                                                           name="email" 
                                                           class="form-control" 
                                                           placeholder="customer@example.com"
                                                           value="<?= safe_echo($_POST['email'] ?? $customer['email']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-phone"></i></span>
                                                    <input type="tel" 
                                                           name="phone" 
                                                           class="form-control" 
                                                           placeholder="10 digit mobile number"
                                                           maxlength="10"
                                                           pattern="[0-9]{10}"
                                                           required
                                                           value="<?= safe_echo($_POST['phone'] ?? $customer['phone']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Alternate Phone</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-phone-in-talk"></i></span>
                                                    <input type="tel" 
                                                           name="alternate_phone" 
                                                           class="form-control" 
                                                           placeholder="Alternate contact number"
                                                           maxlength="10"
                                                           pattern="[0-9]{10}"
                                                           value="<?= safe_echo($_POST['alternate_phone'] ?? $customer['alternate_phone']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Address Information -->
                                    <h5 class="font-size-14 mb-3">Address Information</h5>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" 
                                                  class="form-control" 
                                                  rows="3"
                                                  placeholder="Street address, building, area"><?= safe_echo($_POST['address'] ?? $customer['address']) ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">City</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-city"></i></span>
                                                    <input type="text" 
                                                           name="city" 
                                                           class="form-control" 
                                                           placeholder="City"
                                                           value="<?= safe_echo($_POST['city'] ?? $customer['city']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">State</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-map"></i></span>
                                                    <input type="text" 
                                                           name="state" 
                                                           class="form-control" 
                                                           placeholder="State"
                                                           value="<?= safe_echo($_POST['state'] ?? $customer['state']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Pincode</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-mailbox"></i></span>
                                                    <input type="text" 
                                                           name="pincode" 
                                                           class="form-control" 
                                                           placeholder="6 digit pincode"
                                                           maxlength="6"
                                                           pattern="[0-9]{6}"
                                                           value="<?= safe_echo($_POST['pincode'] ?? $customer['pincode']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Tax Information -->
                                    <h5 class="font-size-14 mb-3">Tax Information</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">GST Number</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-card-account-details"></i></span>
                                                    <input type="text" 
                                                           name="gst_number" 
                                                           class="form-control" 
                                                           placeholder="22AAAAA0000A1Z5"
                                                           maxlength="15"
                                                           value="<?= safe_echo($_POST['gst_number'] ?? $customer['gst_number']) ?>">
                                                </div>
                                                <small class="text-muted">Format: 22AAAAA0000A1Z5</small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">PAN Number</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-card-account-details-outline"></i></span>
                                                    <input type="text" 
                                                           name="pan_number" 
                                                           class="form-control" 
                                                           placeholder="AAAAA0000A"
                                                           maxlength="10"
                                                           value="<?= safe_echo($_POST['pan_number'] ?? $customer['pan_number']) ?>">
                                                </div>
                                                <small class="text-muted">Format: AAAAA0000A</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Business Terms -->
                                    <h5 class="font-size-14 mb-3">Business Terms</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Credit Limit (₹)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                                    <input type="number" 
                                                           name="credit_limit" 
                                                           class="form-control" 
                                                           placeholder="Enter credit limit"
                                                           min="0"
                                                           step="0.01"
                                                           value="<?= safe_echo($_POST['credit_limit'] ?? $customer['credit_limit']) ?>">
                                                </div>
                                                <small class="text-muted">Leave empty for no credit limit</small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Payment Terms (Days)</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="number" 
                                                           name="payment_terms" 
                                                           class="form-control" 
                                                           placeholder="30"
                                                           min="0"
                                                           max="365"
                                                           value="<?= safe_echo($_POST['payment_terms'] ?? $customer['payment_terms']) ?>">
                                                </div>
                                                <small class="text-muted">Number of days for payment</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <div class="form-check form-switch form-switch-md">
                                                    <input type="checkbox" 
                                                           class="form-check-input" 
                                                           name="is_active" 
                                                           id="is_active" 
                                                           <?= ($customer['is_active'] ?? 1) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_active">Active Status</label>
                                                </div>
                                                <small class="text-muted d-block">Toggle to activate or deactivate this customer</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="text-end">
                                                <a href="view-customer.php?id=<?= $customer_id ?>" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Update Customer</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Updating...
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

<script>
    // Form submission loading state
    document.getElementById('customerForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        btn.disabled = true;
        btnText.style.display = 'none';
        loading.style.display = 'inline-block';
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
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
    
    // Warn before leaving if form is dirty
    let formDirty = false;
    const form = document.getElementById('customerForm');
    if (form) {
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
    }
</script>

</body>
</html>