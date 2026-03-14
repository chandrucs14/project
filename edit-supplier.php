<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Check if supplier ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage-suppliers.php");
    exit();
}

$supplier_id = (int)$_GET['id'];
$error = '';
$success = '';

// Get supplier details
try {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        header("Location: manage-suppliers.php");
        exit();
    }
} catch (Exception $e) {
    $error = "Failed to fetch supplier details: " . $e->getMessage();
    error_log("Supplier edit error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_supplier'])) {
    // Get and sanitize form data
    $name = trim($_POST['name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $alternate_phone = trim($_POST['alternate_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $gst_number = trim($_POST['gst_number'] ?? '');
    $pan_number = trim($_POST['pan_number'] ?? '');
    $payment_terms = !empty($_POST['payment_terms']) ? intval($_POST['payment_terms']) : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($name)) {
        $error = "Supplier name is required.";
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
            
            // Check if phone number already exists for another supplier
            $checkStmt = $pdo->prepare("SELECT id FROM suppliers WHERE phone = ? AND id != ?");
            $checkStmt->execute([$phone, $supplier_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("Another supplier with this phone number already exists.");
            }
            
            // Check if email already exists for another supplier (if provided)
            if (!empty($email)) {
                $checkEmailStmt = $pdo->prepare("SELECT id FROM suppliers WHERE email = ? AND id != ?");
                $checkEmailStmt->execute([$email, $supplier_id]);
                if ($checkEmailStmt->fetch()) {
                    throw new Exception("Another supplier with this email address already exists.");
                }
            }
            
            // Check if GST number already exists for another supplier (if provided)
            if (!empty($gst_number)) {
                $checkGstStmt = $pdo->prepare("SELECT id FROM suppliers WHERE gst_number = ? AND id != ?");
                $checkGstStmt->execute([$gst_number, $supplier_id]);
                if ($checkGstStmt->fetch()) {
                    throw new Exception("Another supplier with this GST number already exists.");
                }
            }
            
            // Update supplier
            $stmt = $pdo->prepare("
                UPDATE suppliers SET
                    name = :name,
                    company_name = :company_name,
                    email = :email,
                    phone = :phone,
                    alternate_phone = :alternate_phone,
                    address = :address,
                    city = :city,
                    state = :state,
                    pincode = :pincode,
                    gst_number = :gst_number,
                    pan_number = :pan_number,
                    payment_terms = :payment_terms,
                    is_active = :is_active,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE id = :id
            ");
            
            $params = [
                ':name' => $name,
                ':company_name' => $company_name ?: null,
                ':email' => $email ?: null,
                ':phone' => $phone,
                ':alternate_phone' => $alternate_phone ?: null,
                ':address' => $address ?: null,
                ':city' => $city ?: null,
                ':state' => $state ?: null,
                ':pincode' => $pincode ?: null,
                ':gst_number' => $gst_number ?: null,
                ':pan_number' => $pan_number ?: null,
                ':payment_terms' => $payment_terms,
                ':is_active' => $is_active,
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $supplier_id
            ];
            
            $result = $stmt->execute($params);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at
                    ) VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'supplier_id' => $supplier_id,
                    'supplier_name' => $name,
                    'updated_fields' => [
                        'name' => $name !== $supplier['name'],
                        'phone' => $phone !== $supplier['phone'],
                        'email' => $email !== $supplier['email']
                    ]
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    'Supplier updated: ' . $name,
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "Supplier updated successfully.";
                header("Location: view-supplier.php?id=" . $supplier_id);
                exit();
            } else {
                $pdo->rollBack();
                $error = "Failed to update supplier. Please try again.";
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
            error_log("Supplier update error: " . $e->getMessage());
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
                            <h4 class="mb-0 font-size-18">Edit Supplier</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-suppliers.php">Suppliers</a></li>
                                    <li class="breadcrumb-item"><a href="view-supplier.php?id=<?= $supplier_id ?>">View Supplier</a></li>
                                    <li class="breadcrumb-item active">Edit Supplier</li>
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
                                <h4 class="card-title mb-4">Edit Supplier Information</h4>
                                <p class="card-title-desc">Update supplier details and information</p>

                                <form method="POST" action="" id="supplierForm">
                                    <!-- Basic Information -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-account"></i></span>
                                                    <input type="text" 
                                                           name="name" 
                                                           class="form-control" 
                                                           placeholder="Enter supplier name" 
                                                           required
                                                           value="<?= safe_echo($_POST['name'] ?? $supplier['name']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Company Name</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-domain"></i></span>
                                                    <input type="text" 
                                                           name="company_name" 
                                                           class="form-control" 
                                                           placeholder="Enter company name"
                                                           value="<?= safe_echo($_POST['company_name'] ?? $supplier['company_name']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email Address</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-email"></i></span>
                                                    <input type="email" 
                                                           name="email" 
                                                           class="form-control" 
                                                           placeholder="supplier@example.com"
                                                           value="<?= safe_echo($_POST['email'] ?? $supplier['email']) ?>">
                                                </div>
                                                <small class="text-muted">Used for sending purchase orders and communications</small>
                                            </div>
                                        </div>
                                        
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
                                                           value="<?= safe_echo($_POST['phone'] ?? $supplier['phone']) ?>">
                                                </div>
                                                <small class="text-muted">Enter 10 digit number without country code</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
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
                                                           value="<?= safe_echo($_POST['alternate_phone'] ?? $supplier['alternate_phone']) ?>">
                                                </div>
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
                                                           value="<?= safe_echo($_POST['payment_terms'] ?? $supplier['payment_terms']) ?>">
                                                </div>
                                                <small class="text-muted">Number of days for payment</small>
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
                                                  placeholder="Street address, building, area"><?= safe_echo($_POST['address'] ?? $supplier['address']) ?></textarea>
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
                                                           value="<?= safe_echo($_POST['city'] ?? $supplier['city']) ?>">
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
                                                           value="<?= safe_echo($_POST['state'] ?? $supplier['state']) ?>">
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
                                                           value="<?= safe_echo($_POST['pincode'] ?? $supplier['pincode']) ?>">
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
                                                           value="<?= safe_echo($_POST['gst_number'] ?? $supplier['gst_number']) ?>">
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
                                                           value="<?= safe_echo($_POST['pan_number'] ?? $supplier['pan_number']) ?>">
                                                </div>
                                                <small class="text-muted">Format: AAAAA0000A</small>
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
                                                           <?= ($supplier['is_active'] ?? 1) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_active">Active Status</label>
                                                </div>
                                                <small class="text-muted d-block">Toggle to activate or deactivate this supplier</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="text-end">
                                                <a href="view-supplier.php?id=<?= $supplier_id ?>" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="reset" class="btn btn-warning me-2" id="resetBtn">
                                                    <i class="mdi mdi-undo me-1"></i> Reset
                                                </button>
                                                <button type="submit" name="edit_supplier" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Update Supplier</span>
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

<!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Form submission loading state
    document.getElementById('supplierForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        setTimeout(function() {
            btn.disabled = true;
            btnText.style.display = 'none';
            loading.style.display = 'inline-block';
        }, 100);
    });
    
    // Reset button confirmation
    document.getElementById('resetBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Reset Form?',
            text: 'Are you sure you want to reset all fields to their original values?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, reset it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'edit-supplier.php?id=<?= $supplier_id ?>';
            }
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
    
    // GST number formatting and validation
    const gstInput = document.querySelector('input[name="gst_number"]');
    if (gstInput) {
        gstInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15);
        });
        
        gstInput.addEventListener('blur', function(e) {
            const gst = this.value;
            if (gst && gst.length === 15) {
                const gstRegex = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
                if (!gstRegex.test(gst)) {
                    this.classList.add('is-invalid');
                    showInvalidFeedback(this, 'Invalid GST number format');
                } else {
                    this.classList.remove('is-invalid');
                    removeInvalidFeedback(this);
                }
            }
        });
    }
    
    // PAN number formatting and validation
    const panInput = document.querySelector('input[name="pan_number"]');
    if (panInput) {
        panInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
        });
        
        panInput.addEventListener('blur', function(e) {
            const pan = this.value;
            if (pan && pan.length === 10) {
                const panRegex = /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/;
                if (!panRegex.test(pan)) {
                    this.classList.add('is-invalid');
                    showInvalidFeedback(this, 'Invalid PAN number format');
                } else {
                    this.classList.remove('is-invalid');
                    removeInvalidFeedback(this);
                }
            }
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
    const form = document.getElementById('supplierForm');
    if (form) {
        const formInputs = form.querySelectorAll('input, textarea, select');
        const originalValues = {};
        
        formInputs.forEach(input => {
            originalValues[input.name] = input.value;
            
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
                if (input.type !== 'checkbox' && input.value !== originalValues[input.name]) {
                    formDirty = true;
                }
                if (input.type === 'checkbox' && input.checked !== (originalValues[input.name] === 'on')) {
                    formDirty = true;
                }
            });
        }
        
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
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('supplierForm').submit();
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