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

// Generate unique order number
function generateOrderNumber($pdo) {
    $prefix = 'ORD';
    $year = date('y');
    $month = date('m');
    
    // Get the last order number
    $stmt = $pdo->query("SELECT order_number FROM orders WHERE order_number LIKE 'ORD%' ORDER BY id DESC LIMIT 1");
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        // Extract the sequence number (last 4 digits)
        $sequence = intval(substr($lastNumber, -4)) + 1;
        $newSequence = str_pad($sequence, 4, '0', STR_PAD_LEFT);
    } else {
        $newSequence = '0001';
    }
    
    return $prefix . $year . $month . $newSequence;
}

// Fetch customers for dropdown
$custStmt = $pdo->query("SELECT id, name, customer_code, phone, email, city, outstanding_balance, credit_limit FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $custStmt->fetchAll();

// Fetch products for dropdown
$prodStmt = $pdo->query("
    SELECT p.*, c.name as category_name, g.gst_rate 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN gst_details g ON p.gst_id = g.id 
    WHERE p.is_active = 1 
    ORDER BY p.name
");
$products = $prodStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    
    $customer_id = (int)$_POST['customer_id'];
    $order_date = $_POST['order_date'];
    $delivery_date = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
    $advance_paid = floatval($_POST['advance_paid'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Get product items from form
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    
    // Validation
    if ($customer_id <= 0) {
        $error = "Please select a customer.";
    } elseif (empty($product_ids)) {
        $error = "Please add at least one product to the order.";
    } elseif (empty($order_date)) {
        $error = "Order date is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get customer details for credit check
            $custStmt = $pdo->prepare("SELECT name, outstanding_balance, credit_limit FROM customers WHERE id = ?");
            $custStmt->execute([$customer_id]);
            $customer = $custStmt->fetch();
            
            if (!$customer) {
                throw new Exception("Customer not found.");
            }
            
            // Calculate totals
            $subtotal = 0;
            $gst_total = 0;
            $total_amount = 0;
            $items = [];
            
            foreach ($product_ids as $index => $product_id) {
                if (empty($product_id)) continue;
                
                $quantity = floatval($quantities[$index] ?? 0);
                $price = floatval($prices[$index] ?? 0);
                
                if ($quantity <= 0 || $price <= 0) continue;
                
                // Get product details for GST calculation
                $prodStmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                $prodStmt->execute([$product_id]);
                $product = $prodStmt->fetch();
                
                if (!$product) continue;
                
                // Get GST rate if applicable
                $gst_rate = 0;
                $gst_amount = 0;
                if ($product['gst_id']) {
                    $gstStmt = $pdo->prepare("SELECT gst_rate FROM gst_details WHERE id = ?");
                    $gstStmt->execute([$product['gst_id']]);
                    $gst = $gstStmt->fetch();
                    $gst_rate = $gst['gst_rate'] ?? 0;
                    $gst_amount = ($price * $quantity) * ($gst_rate / 100);
                }
                
                $item_total = $price * $quantity;
                $subtotal += $item_total;
                $gst_total += $gst_amount;
                
                $items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'gst_id' => $product['gst_id'],
                    'gst_amount' => $gst_amount,
                    'total' => $item_total + $gst_amount
                ];
            }
            
            $total_amount = $subtotal + $gst_total;
            $balance_amount = $total_amount - $advance_paid;
            
            // Check credit limit if applicable
            if ($customer['credit_limit'] > 0) {
                $new_outstanding = $customer['outstanding_balance'] + $balance_amount;
                if ($new_outstanding > $customer['credit_limit']) {
                    throw new Exception("This order will exceed the customer's credit limit. Current outstanding: ₹{$customer['outstanding_balance']}, Credit limit: ₹{$customer['credit_limit']}");
                }
            }
            
            // Generate order number
            $order_number = generateOrderNumber($pdo);
            
            // Insert order
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, customer_id, order_date, delivery_date, 
                    subtotal, gst_total, discount_amount, total_amount, 
                    advance_paid, balance_amount, status, notes, created_by, created_at
                ) VALUES (
                    :order_number, :customer_id, :order_date, :delivery_date,
                    :subtotal, :gst_total, 0, :total_amount,
                    :advance_paid, :balance_amount, 'pending', :notes, :created_by, NOW()
                )
            ");
            
            $params = [
                ':order_number' => $order_number,
                ':customer_id' => $customer_id,
                ':order_date' => $order_date,
                ':delivery_date' => $delivery_date,
                ':subtotal' => $subtotal,
                ':gst_total' => $gst_total,
                ':total_amount' => $total_amount,
                ':advance_paid' => $advance_paid,
                ':balance_amount' => $balance_amount,
                ':notes' => $notes ?: null,
                ':created_by' => $_SESSION['user_id']
            ];
            
            $result = $stmt->execute($params);
            
            if ($result) {
                $order_id = $pdo->lastInsertId();
                
                // Insert order items
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, quantity, delivered_quantity, 
                        unit_price, gst_id, gst_amount, total_price, created_at
                    ) VALUES (
                        ?, ?, ?, 0, ?, ?, ?, ?, NOW()
                    )
                ");
                
                foreach ($items as $item) {
                    $itemStmt->execute([
                        $order_id,
                        $item['product_id'],
                        $item['quantity'],
                        $item['price'],
                        $item['gst_id'],
                        $item['gst_amount'],
                        $item['total']
                    ]);
                }
                
                // If advance payment was made, update customer outstanding
                if ($advance_paid > 0) {
                    $new_outstanding = $customer['outstanding_balance'] + $balance_amount;
                    $updateCustStmt = $pdo->prepare("UPDATE customers SET outstanding_balance = ? WHERE id = ?");
                    $updateCustStmt->execute([$new_outstanding, $customer_id]);
                }
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'order_id' => $order_id,
                    'order_number' => $order_number,
                    'customer_name' => $customer['name'],
                    'total_amount' => $total_amount,
                    'advance_paid' => $advance_paid
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "New order created: #$order_number for " . $customer['name'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "Order created successfully. Order Number: " . $order_number;
                header("Location: view-order.php?id=" . $order_id);
                exit();
            } else {
                $pdo->rollBack();
                $error = "Failed to create order. Please try again.";
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
            error_log("Order creation error: " . $e->getMessage());
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
        .product-row {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            position: relative;
        }
        .remove-product {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
            color: #dc3545;
        }
        .remove-product:hover {
            color: #c82333;
        }
        .totals-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .totals-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .totals-item.total {
            font-size: 20px;
            font-weight: bold;
            border-top: 1px solid rgba(255,255,255,0.3);
            padding-top: 10px;
            margin-top: 10px;
        }
        .customer-info {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
                            <h4 class="mb-0 font-size-18">Create New Order</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="orders.php">Orders</a></li>
                                    <li class="breadcrumb-item active">Create Order</li>
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

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Order Information</h4>

                                <form method="POST" action="" id="orderForm">
                                    <!-- Customer Selection -->
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                                            <select name="customer_id" id="customer_id" class="form-control" required onchange="loadCustomerDetails(this.value)">
                                                <option value="">Choose customer...</option>
                                                <?php foreach ($customers as $cust): ?>
                                                    <option value="<?= $cust['id'] ?>" 
                                                            data-outstanding="<?= $cust['outstanding_balance'] ?>"
                                                            data-credit="<?= $cust['credit_limit'] ?>"
                                                            data-phone="<?= $cust['phone'] ?>"
                                                            data-email="<?= $cust['email'] ?>"
                                                            data-city="<?= $cust['city'] ?>">
                                                        <?= htmlspecialchars($cust['name']) ?> (<?= htmlspecialchars($cust['customer_code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">&nbsp;</label>
                                            <a href="add-customer.php" class="btn btn-success w-100" target="_blank">
                                                <i class="mdi mdi-account-plus me-1"></i> New Customer
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Customer Details Display -->
                                    <div id="customer_details" class="customer-info" style="display: none;">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Phone:</strong> <span id="cust_phone"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Email:</strong> <span id="cust_email"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>City:</strong> <span id="cust_city"></span>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-6">
                                                <strong>Outstanding Balance:</strong> <span id="cust_outstanding" class="text-danger"></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Credit Limit:</strong> <span id="cust_credit"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Order Dates -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Order Date <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Expected Delivery Date</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Products Section -->
                                    <h5 class="font-size-14 mb-3">Products</h5>
                                    
                                    <div id="products-container">
                                        <!-- Product rows will be added here -->
                                        <div class="product-row" id="product-row-0">
                                            <div class="remove-product" onclick="removeProduct(0)" title="Remove Product">
                                                <i class="mdi mdi-close-circle"></i>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-2">
                                                        <label class="form-label">Select Product</label>
                                                        <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, 0)" required>
                                                            <option value="">Choose product...</option>
                                                            <?php foreach ($products as $prod): ?>
                                                                <option value="<?= $prod['id'] ?>" 
                                                                        data-price="<?= $prod['selling_price'] ?>"
                                                                        data-stock="<?= $prod['current_stock'] ?>"
                                                                        data-unit="<?= $prod['unit'] ?>"
                                                                        data-gst="<?= $prod['gst_rate'] ?? 0 ?>">
                                                                    <?= htmlspecialchars($prod['name']) ?> 
                                                                    (<?= htmlspecialchars($prod['category_name'] ?? 'Uncategorized') ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" name="quantity[]" class="form-control quantity" value="1" min="0.01" step="0.01" onchange="calculateRow(0)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Unit Price (₹)</label>
                                                        <input type="number" name="price[]" class="form-control price" value="0" min="0" step="0.01" onchange="calculateRow(0)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Total (₹)</label>
                                                        <input type="text" class="form-control row-total" id="row-total-0" value="0.00" readonly>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <small class="text-muted">Available Stock: <span id="stock-0">0</span> <span id="unit-0"></span></small>
                                                </div>
                                                <div class="col-md-4">
                                                    <small class="text-muted">GST: <span id="gst-0">0%</span></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Add More Products Button -->
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <button type="button" class="btn btn-primary" onclick="addProduct()">
                                                <i class="mdi mdi-plus me-1"></i> Add More Product
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label class="form-label">Order Notes</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions or notes..."></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <hr>
                                            <div class="text-end">
                                                <a href="orders.php" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="submit" name="create_order" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Create Order</span>
                                                    <span id="loading" style="display:none;">
                                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                                        Creating...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary Sidebar -->
                    <div class="col-lg-4">
                        <div class="totals-card">
                            <h5 class="text-white mb-3">Order Summary</h5>
                            <div class="totals-item">
                                <span>Subtotal:</span>
                                <span id="summary-subtotal">₹0.00</span>
                            </div>
                            <div class="totals-item">
                                <span>GST Total:</span>
                                <span id="summary-gst">₹0.00</span>
                            </div>
                            <div class="totals-item">
                                <span>Advance Payment:</span>
                                <span>
                                    <input type="number" name="advance_paid" id="advance_paid" class="form-control form-control-sm" style="width: 120px; display: inline-block;" value="0" min="0" step="0.01" onchange="updateBalance()">
                                </span>
                            </div>
                            <div class="totals-item total">
                                <span>Balance Amount:</span>
                                <span id="summary-balance">₹0.00</span>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Quick Tips</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Select a customer first</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Add products to the order</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Enter quantities and prices</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Advance payment is optional</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Check credit limit before finalizing</li>
                                </ul>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Recent Products</h5>
                                <div class="list-group">
                                    <?php 
                                    $recentStmt = $pdo->query("SELECT name, selling_price FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
                                    $recentProducts = $recentStmt->fetchAll();
                                    foreach ($recentProducts as $rp): 
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><?= htmlspecialchars($rp['name']) ?></span>
                                            <span class="badge bg-primary">₹<?= number_format($rp['selling_price'], 2) ?></span>
                                        </div>
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
    let productCount = 1;
    
    // Form submission loading state
    document.getElementById('orderForm')?.addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loading = document.getElementById('loading');
        
        btn.disabled = true;
        btnText.style.display = 'none';
        loading.style.display = 'inline-block';
    });
    
    // Add product row
    function addProduct() {
        const container = document.getElementById('products-container');
        const newRow = document.createElement('div');
        newRow.className = 'product-row';
        newRow.id = `product-row-${productCount}`;
        newRow.innerHTML = `
            <div class="remove-product" onclick="removeProduct(${productCount})" title="Remove Product">
                <i class="mdi mdi-close-circle"></i>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label">Select Product</label>
                        <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, ${productCount})" required>
                            <option value="">Choose product...</option>
                            <?php foreach ($products as $prod): ?>
                                <option value="<?= $prod['id'] ?>" 
                                        data-price="<?= $prod['selling_price'] ?>"
                                        data-stock="<?= $prod['current_stock'] ?>"
                                        data-unit="<?= $prod['unit'] ?>"
                                        data-gst="<?= $prod['gst_rate'] ?? 0 ?>">
                                    <?= htmlspecialchars($prod['name']) ?> 
                                    (<?= htmlspecialchars($prod['category_name'] ?? 'Uncategorized') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-2">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity[]" class="form-control quantity" value="1" min="0.01" step="0.01" onchange="calculateRow(${productCount})" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-2">
                        <label class="form-label">Unit Price (₹)</label>
                        <input type="number" name="price[]" class="form-control price" value="0" min="0" step="0.01" onchange="calculateRow(${productCount})" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-2">
                        <label class="form-label">Total (₹)</label>
                        <input type="text" class="form-control row-total" id="row-total-${productCount}" value="0.00" readonly>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted">Available Stock: <span id="stock-${productCount}">0</span> <span id="unit-${productCount}"></span></small>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">GST: <span id="gst-${productCount}">0%</span></small>
                </div>
            </div>
        `;
        container.appendChild(newRow);
        productCount++;
    }
    
    // Remove product row
    function removeProduct(index) {
        if (document.querySelectorAll('.product-row').length > 1) {
            document.getElementById(`product-row-${index}`).remove();
            calculateTotals();
        } else {
            Swal.fire({
                title: 'Cannot Remove',
                text: 'At least one product is required',
                icon: 'warning',
                confirmButtonColor: '#556ee6'
            });
        }
    }
    
    // Load product details when selected
    function loadProductDetails(select, index) {
        const selected = select.options[select.selectedIndex];
        if (selected.value) {
            const price = selected.dataset.price;
            const stock = selected.dataset.stock;
            const unit = selected.dataset.unit;
            const gst = selected.dataset.gst;
            
            document.querySelector(`#product-row-${index} .price`).value = price;
            document.querySelector(`#stock-${index}`).textContent = stock;
            document.querySelector(`#unit-${index}`).textContent = unit;
            document.querySelector(`#gst-${index}`).textContent = gst + '%';
            
            calculateRow(index);
        }
    }
    
    // Calculate row total
    function calculateRow(index) {
        const row = document.getElementById(`product-row-${index}`);
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const total = quantity * price;
        
        row.querySelector('.row-total').value = total.toFixed(2);
        calculateTotals();
    }
    
    // Calculate all totals
    function calculateTotals() {
        let subtotal = 0;
        let gstTotal = 0;
        
        document.querySelectorAll('.product-row').forEach((row, index) => {
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            const gstRate = parseFloat(document.querySelector(`#gst-${index}`)?.textContent || 0);
            
            const rowSubtotal = quantity * price;
            const rowGst = rowSubtotal * (gstRate / 100);
            
            subtotal += rowSubtotal;
            gstTotal += rowGst;
        });
        
        const total = subtotal + gstTotal;
        const advance = parseFloat(document.getElementById('advance_paid').value) || 0;
        const balance = total - advance;
        
        document.getElementById('summary-subtotal').textContent = '₹' + subtotal.toFixed(2);
        document.getElementById('summary-gst').textContent = '₹' + gstTotal.toFixed(2);
        document.getElementById('summary-balance').textContent = '₹' + balance.toFixed(2);
    }
    
    // Update balance when advance payment changes
    function updateBalance() {
        calculateTotals();
    }
    
    // Load customer details
    function loadCustomerDetails(customerId) {
        const select = document.getElementById('customer_id');
        const selected = select.options[select.selectedIndex];
        
        if (customerId) {
            const outstanding = selected.dataset.outstanding || '0';
            const credit = selected.dataset.credit || '0';
            const phone = selected.dataset.phone || 'N/A';
            const email = selected.dataset.email || 'N/A';
            const city = selected.dataset.city || 'N/A';
            
            document.getElementById('cust_phone').textContent = phone;
            document.getElementById('cust_email').textContent = email;
            document.getElementById('cust_city').textContent = city;
            document.getElementById('cust_outstanding').textContent = '₹' + parseFloat(outstanding).toFixed(2);
            document.getElementById('cust_credit').textContent = credit ? '₹' + parseFloat(credit).toFixed(2) : 'No Limit';
            
            document.getElementById('customer_details').style.display = 'block';
        } else {
            document.getElementById('customer_details').style.display = 'none';
        }
    }
    
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
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Form validation before submit
    document.getElementById('orderForm')?.addEventListener('submit', function(e) {
        const customerId = document.getElementById('customer_id').value;
        if (!customerId) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please select a customer',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        let hasProducts = false;
        document.querySelectorAll('.product-select').forEach(select => {
            if (select.value) hasProducts = true;
        });
        
        if (!hasProducts) {
            e.preventDefault();
            Swal.fire({
                title: 'Validation Error',
                text: 'Please add at least one product',
                icon: 'error',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.getElementById('orderForm').submit();
        }
    });
</script>

</body>
</html>