<?php
ob_start();
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';

if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$success = '';

$userId = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0 ? (int)$_SESSION['user_id'] : null;

// Preserve form values
$formData = [
    'supplier_id' => '',
    'order_date' => date('Y-m-d'),
    'expected_delivery' => date('Y-m-d', strtotime('+7 days')),
    'notes' => '',
    'product_id' => [''],
    'quantity' => [1],
    'price' => [0]
];

// Generate unique PO number
function generatePONumber(PDO $pdo): string
{
    $prefix = 'PO';
    $year = date('y');
    $month = date('m');

    try {
        $pattern = $prefix . $year . $month . '%';

        $stmt = $pdo->prepare("
            SELECT po_number 
            FROM purchase_orders 
            WHERE po_number LIKE :pattern 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([':pattern' => $pattern]);
        $lastNumber = $stmt->fetchColumn();

        if ($lastNumber) {
            $sequence = (int)substr($lastNumber, -4) + 1;
            $newSequence = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        } else {
            $newSequence = '0001';
        }

        return $prefix . $year . $month . $newSequence;
    } catch (Throwable $e) {
        error_log("Error generating PO number: " . $e->getMessage());
        return $prefix . $year . $month . date('d') . rand(100, 999);
    }
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Fetch suppliers
try {
    $suppStmt = $pdo->query("
        SELECT id, name, supplier_code, company_name, phone, email, address, city, state, gst_number, payment_terms
        FROM suppliers
        WHERE is_active = 1
        ORDER BY name
    ");
    $suppliers = $suppStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Fetch products
try {
    $prodStmt = $pdo->query("
        SELECT p.*, c.name AS category_name, g.gst_rate, g.id AS gst_lookup_id
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN gst_details g ON p.gst_id = g.id
        WHERE p.is_active = 1
        ORDER BY p.name
    ");
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['supplier_id'] = $_POST['supplier_id'] ?? '';
    $formData['order_date'] = $_POST['order_date'] ?? date('Y-m-d');
    $formData['expected_delivery'] = $_POST['expected_delivery'] ?? date('Y-m-d', strtotime('+7 days'));
    $formData['notes'] = trim($_POST['notes'] ?? '');
    $formData['product_id'] = $_POST['product_id'] ?? [''];
    $formData['quantity'] = $_POST['quantity'] ?? [1];
    $formData['price'] = $_POST['price'] ?? [0];

    $supplier_id = (int)($formData['supplier_id'] ?? 0);
    $order_date = trim($formData['order_date'] ?? '');
    $expected_delivery = trim($formData['expected_delivery'] ?? '');
    $expected_delivery = $expected_delivery !== '' ? $expected_delivery : null;
    $notes = $formData['notes'];

    $product_ids = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
    $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $prices = is_array($_POST['price'] ?? null) ? $_POST['price'] : [];

    if ($supplier_id <= 0) {
        $error = "Please select a supplier.";
    } elseif ($order_date === '') {
        $error = "Order date is required.";
    } else {
        try {
            $pdo->beginTransaction();

            $suppStmt = $pdo->prepare("
                SELECT id, name, supplier_code, payment_terms
                FROM suppliers
                WHERE id = ?
                LIMIT 1
            ");
            $suppStmt->execute([$supplier_id]);
            $supplier = $suppStmt->fetch(PDO::FETCH_ASSOC);

            if (!$supplier) {
                throw new Exception("Selected supplier not found.");
            }

            $subtotal = 0;
            $gst_total = 0;
            $items = [];

            foreach ($product_ids as $index => $product_id_raw) {
                $product_id = (int)$product_id_raw;
                $quantity = isset($quantities[$index]) ? (float)$quantities[$index] : 0;
                $price = isset($prices[$index]) ? (float)$prices[$index] : 0;

                if ($product_id <= 0) {
                    continue;
                }

                if ($quantity <= 0 || $price <= 0) {
                    continue;
                }

                $prodStmt = $pdo->prepare("
                    SELECT id, name, gst_id
                    FROM products
                    WHERE id = ?
                    LIMIT 1
                ");
                $prodStmt->execute([$product_id]);
                $product = $prodStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    continue;
                }

                $gst_rate = 0;
                $gst_amount = 0;
                $gst_id = null;

                if (!empty($product['gst_id'])) {
                    $gstStmt = $pdo->prepare("
                        SELECT id, gst_rate
                        FROM gst_details
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $gstStmt->execute([(int)$product['gst_id']]);
                    $gst = $gstStmt->fetch(PDO::FETCH_ASSOC);

                    if ($gst) {
                        $gst_rate = (float)$gst['gst_rate'];
                        $gst_id = (int)$gst['id'];
                    }
                }

                $item_subtotal = $quantity * $price;
                $gst_amount = $item_subtotal * ($gst_rate / 100);
                $item_total = $item_subtotal + $gst_amount;

                $subtotal += $item_subtotal;
                $gst_total += $gst_amount;

                $items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'gst_id' => $gst_id,
                    'gst_amount' => $gst_amount,
                    'total' => $item_total
                ];
            }

            if (empty($items)) {
                throw new Exception("Please add at least one valid product with quantity and price.");
            }

            $total_amount = $subtotal + $gst_total;
            $po_number = generatePONumber($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (
                    po_number,
                    supplier_id,
                    order_date,
                    expected_delivery,
                    status,
                    subtotal,
                    gst_total,
                    discount_amount,
                    total_amount,
                    notes,
                    created_by,
                    created_at
                ) VALUES (
                    :po_number,
                    :supplier_id,
                    :order_date,
                    :expected_delivery,
                    :status,
                    :subtotal,
                    :gst_total,
                    :discount_amount,
                    :total_amount,
                    :notes,
                    :created_by,
                    NOW()
                )
            ");

            $stmt->execute([
                ':po_number' => $po_number,
                ':supplier_id' => $supplier_id,
                ':order_date' => $order_date,
                ':expected_delivery' => $expected_delivery,
                ':status' => 'draft',
                ':subtotal' => $subtotal,
                ':gst_total' => $gst_total,
                ':discount_amount' => 0.00,
                ':total_amount' => $total_amount,
                ':notes' => $notes !== '' ? $notes : null,
                ':created_by' => $userId
            ]);

            $po_id = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare("
                INSERT INTO purchase_order_items (
                    purchase_order_id,
                    product_id,
                    quantity,
                    received_quantity,
                    unit_price,
                    gst_id,
                    gst_amount,
                    total_price,
                    created_by,
                    created_at
                ) VALUES (
                    ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($items as $item) {
                $itemStmt->execute([
                    $po_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['gst_id'],
                    $item['gst_amount'],
                    $item['total'],
                    $userId
                ]);
            }

            try {
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id,
                        activity_type_id,
                        description,
                        activity_data,
                        created_at,
                        created_by
                    ) VALUES (?, 3, ?, ?, NOW(), ?)
                ");

                $activity_data = json_encode([
                    'po_id' => $po_id,
                    'po_number' => $po_number,
                    'supplier_id' => $supplier_id,
                    'supplier_name' => $supplier['name'],
                    'total_amount' => $total_amount,
                    'item_count' => count($items)
                ], JSON_UNESCAPED_UNICODE);

                $activity_stmt->execute([
                    $userId,
                    "New purchase order created: {$po_number} for {$supplier['name']}",
                    $activity_data,
                    $userId
                ]);
            } catch (Throwable $e) {
                error_log("Activity log error: " . $e->getMessage());
            }

            $pdo->commit();

            $_SESSION['success_message'] = "Purchase order created successfully. PO Number: " . $po_number;
            header("Location: purchase-orders.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Purchase order creation failed: " . $e->getMessage();
            error_log("PO creation failed: " . $e->getMessage());
        }
    }
}

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .product-row {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            position: relative;
            border-left: 4px solid #556ee6;
        }
        .remove-product {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
            color: #dc3545;
            font-size: 20px;
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
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
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
        .supplier-info {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #34c38f;
        }
        .product-info-badge {
            background-color: #e9ecef;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
        }
        .gst-badge {
            background-color: #556ee6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
    </style>
</head>

<body data-sidebar="dark">
<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0 font-size-18">Create Purchase Order</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="purchase-orders.php">Purchase Orders</a></li>
                                    <li class="breadcrumb-item active">Create PO</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i>
                        <?= h($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i>
                        <?= h($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Purchase Order Information</h4>

                                <form method="POST" action="" id="poForm">
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                                            <select name="supplier_id" id="supplier_id" class="form-control select2" required onchange="loadSupplierDetails(this.value)">
                                                <option value="">Choose supplier...</option>
                                                <?php foreach ($suppliers as $supp): ?>
                                                    <option
                                                        value="<?= (int)$supp['id'] ?>"
                                                        data-code="<?= h($supp['supplier_code']) ?>"
                                                        data-company="<?= h($supp['company_name']) ?>"
                                                        data-phone="<?= h($supp['phone']) ?>"
                                                        data-email="<?= h($supp['email']) ?>"
                                                        data-address="<?= h($supp['address']) ?>"
                                                        data-city="<?= h($supp['city']) ?>"
                                                        data-state="<?= h($supp['state']) ?>"
                                                        data-gst="<?= h($supp['gst_number']) ?>"
                                                        data-terms="<?= h($supp['payment_terms']) ?>"
                                                        <?= ((string)$formData['supplier_id'] === (string)$supp['id']) ? 'selected' : '' ?>
                                                    >
                                                        <?= h($supp['name']) ?><?= !empty($supp['company_name']) ? ' (' . h($supp['company_name']) . ')' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">&nbsp;</label>
                                            <a href="add-supplier.php" class="btn btn-success w-100" target="_blank">
                                                <i class="mdi mdi-truck-plus me-1"></i> New Supplier
                                            </a>
                                        </div>
                                    </div>

                                    <div id="supplier_details" class="supplier-info" style="display:none;">
                                        <div class="row">
                                            <div class="col-md-4"><strong>Code:</strong> <span id="supp_code"></span></div>
                                            <div class="col-md-4"><strong>Phone:</strong> <span id="supp_phone"></span></div>
                                            <div class="col-md-4"><strong>Email:</strong> <span id="supp_email"></span></div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-4"><strong>GST:</strong> <span id="supp_gst"></span></div>
                                            <div class="col-md-4"><strong>Payment Terms:</strong> <span id="supp_terms"></span> days</div>
                                            <div class="col-md-4"><strong>Address:</strong> <span id="supp_address"></span></div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Order Date <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="order_date" class="form-control" value="<?= h($formData['order_date']) ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Expected Delivery Date</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                                    <input type="date" name="expected_delivery" class="form-control" value="<?= h($formData['expected_delivery']) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <h5 class="font-size-14 mb-3">Products</h5>

                                    <div id="products-container">
                                        <?php
                                        $rowCount = max(
                                            count($formData['product_id']),
                                            count($formData['quantity']),
                                            count($formData['price'])
                                        );

                                        if ($rowCount < 1) {
                                            $rowCount = 1;
                                        }

                                        for ($i = 0; $i < $rowCount; $i++):
                                            $selectedProductId = $formData['product_id'][$i] ?? '';
                                            $selectedQuantity = $formData['quantity'][$i] ?? 1;
                                            $selectedPrice = $formData['price'][$i] ?? 0;

                                            $selectedProduct = null;
                                            foreach ($products as $prod) {
                                                if ((string)$prod['id'] === (string)$selectedProductId) {
                                                    $selectedProduct = $prod;
                                                    break;
                                                }
                                            }

                                            $stock = $selectedProduct['current_stock'] ?? 0;
                                            $unit = $selectedProduct['unit'] ?? '';
                                            $gstRate = $selectedProduct['gst_rate'] ?? 0;
                                            $categoryName = $selectedProduct['category_name'] ?? '';
                                            $rowSubtotal = ((float)$selectedQuantity * (float)$selectedPrice);
                                            $rowGst = $rowSubtotal * ((float)$gstRate / 100);
                                            $rowTotal = $rowSubtotal + $rowGst;
                                        ?>
                                        <div class="product-row" id="product-row-<?= $i ?>">
                                            <div class="remove-product" onclick="removeProduct(<?= $i ?>)" title="Remove Product">
                                                <i class="mdi mdi-close-circle"></i>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-5">
                                                    <div class="mb-2">
                                                        <label class="form-label">Select Product</label>
                                                        <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, <?= $i ?>)" required>
                                                            <option value="">Choose product...</option>
                                                            <?php foreach ($products as $prod): ?>
                                                                <option
                                                                    value="<?= (int)$prod['id'] ?>"
                                                                    data-price="<?= h($prod['cost_price'] ?? $prod['selling_price'] ?? 0) ?>"
                                                                    data-stock="<?= h($prod['current_stock'] ?? 0) ?>"
                                                                    data-unit="<?= h($prod['unit'] ?? '') ?>"
                                                                    data-gst="<?= h($prod['gst_rate'] ?? 0) ?>"
                                                                    data-gst-id="<?= h($prod['gst_lookup_id'] ?? '') ?>"
                                                                    data-category="<?= h($prod['category_name'] ?? 'Uncategorized') ?>"
                                                                    <?= ((string)$selectedProductId === (string)$prod['id']) ? 'selected' : '' ?>
                                                                >
                                                                    <?= h($prod['name']) ?> (<?= h($prod['category_name'] ?? 'Uncategorized') ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" name="quantity[]" class="form-control quantity" value="<?= h($selectedQuantity) ?>" min="0.01" step="0.01" onchange="calculateRow(<?= $i ?>)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-2">
                                                        <label class="form-label">Unit Price (₹)</label>
                                                        <input type="number" name="price[]" class="form-control price" value="<?= h($selectedPrice) ?>" min="0" step="0.01" onchange="calculateRow(<?= $i ?>)" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-2">
                                                        <label class="form-label">Total (₹)</label>
                                                        <input type="text" class="form-control row-total" id="row-total-<?= $i ?>" value="<?= number_format((float)$rowTotal, 2, '.', '') ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <small class="text-muted">
                                                        <i class="mdi mdi-package-variant me-1"></i>
                                                        Current Stock: <span id="stock-<?= $i ?>"><?= h($stock) ?></span>
                                                        <span id="unit-<?= $i ?>"><?= h($unit) ?></span>
                                                        <span class="product-info-badge" id="category-<?= $i ?>"><?= h($categoryName) ?></span>
                                                    </small>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted">
                                                        <i class="mdi mdi-percent me-1"></i>
                                                        GST: <span id="gst-<?= $i ?>"><?= h($gstRate) ?>%</span>
                                                        <span class="gst-badge" id="gst-amount-<?= $i ?>">₹<?= number_format((float)$rowGst, 2) ?></span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endfor; ?>
                                    </div>

                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <button type="button" class="btn btn-primary" onclick="addProduct()">
                                                <i class="mdi mdi-plus me-1"></i> Add More Product
                                            </button>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label class="form-label">PO Notes / Instructions</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions for supplier..."><?= h($formData['notes']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <hr>
                                            <div class="text-end">
                                                <a href="purchase-orders.php" class="btn btn-secondary me-2">
                                                    <i class="mdi mdi-arrow-left me-1"></i> Cancel
                                                </a>
                                                <button type="submit" class="btn btn-success" id="submitBtn">
                                                    <i class="mdi mdi-content-save me-1"></i>
                                                    <span id="btnText">Create Purchase Order</span>
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

                    <div class="col-lg-4">
                        <div class="totals-card">
                            <h5 class="text-white mb-3">PO Summary</h5>
                            <div class="totals-item">
                                <span>Subtotal:</span>
                                <span id="summary-subtotal">₹0.00</span>
                            </div>
                            <div class="totals-item">
                                <span>GST Total:</span>
                                <span id="summary-gst">₹0.00</span>
                            </div>
                            <div class="totals-item total">
                                <span>Total Amount:</span>
                                <span id="summary-total">₹0.00</span>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Quick Tips</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Select a supplier first</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Add products to the order</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> Cost price will be auto-filled</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> GST is calculated automatically</li>
                                    <li class="mb-2"><i class="mdi mdi-check-circle text-success me-2"></i> PO will be created as Draft</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let productCount = document.querySelectorAll('.product-row').length;

$(document).ready(function () {
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select option'
    });

    loadSupplierDetails($('#supplier_id').val());
    calculateTotals();
});

function buildProductOptions() {
    let productOptions = '<option value="">Choose product...</option>';
    <?php foreach ($products as $prod): ?>
    productOptions += `<option value="<?= (int)$prod['id'] ?>"
        data-price="<?= h($prod['cost_price'] ?? $prod['selling_price'] ?? 0) ?>"
        data-stock="<?= h($prod['current_stock'] ?? 0) ?>"
        data-unit="<?= h($prod['unit'] ?? '') ?>"
        data-gst="<?= h($prod['gst_rate'] ?? 0) ?>"
        data-gst-id="<?= h($prod['gst_lookup_id'] ?? '') ?>"
        data-category="<?= h($prod['category_name'] ?? 'Uncategorized') ?>">
        <?= h($prod['name']) ?> (<?= h($prod['category_name'] ?? 'Uncategorized') ?>)
    </option>`;
    <?php endforeach; ?>
    return productOptions;
}

function addProduct() {
    const container = document.getElementById('products-container');
    const index = productCount;
    const newRow = document.createElement('div');
    newRow.className = 'product-row';
    newRow.id = `product-row-${index}`;

    newRow.innerHTML = `
        <div class="remove-product" onclick="removeProduct(${index})" title="Remove Product">
            <i class="mdi mdi-close-circle"></i>
        </div>
        <div class="row">
            <div class="col-md-5">
                <div class="mb-2">
                    <label class="form-label">Select Product</label>
                    <select name="product_id[]" class="form-control product-select" onchange="loadProductDetails(this, ${index})" required>
                        ${buildProductOptions()}
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="mb-2">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity[]" class="form-control quantity" value="1" min="0.01" step="0.01" onchange="calculateRow(${index})" required>
                </div>
            </div>
            <div class="col-md-2">
                <div class="mb-2">
                    <label class="form-label">Unit Price (₹)</label>
                    <input type="number" name="price[]" class="form-control price" value="0" min="0" step="0.01" onchange="calculateRow(${index})" required>
                </div>
            </div>
            <div class="col-md-3">
                <div class="mb-2">
                    <label class="form-label">Total (₹)</label>
                    <input type="text" class="form-control row-total" id="row-total-${index}" value="0.00" readonly>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="mdi mdi-package-variant me-1"></i>Current Stock: <span id="stock-${index}">0</span>
                    <span id="unit-${index}"></span>
                    <span class="product-info-badge" id="category-${index}"></span>
                </small>
            </div>
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="mdi mdi-percent me-1"></i>GST: <span id="gst-${index}">0%</span>
                    <span class="gst-badge" id="gst-amount-${index}">₹0.00</span>
                </small>
            </div>
        </div>
    `;

    container.appendChild(newRow);
    productCount++;
}

function removeProduct(index) {
    const rows = document.querySelectorAll('.product-row');
    if (rows.length <= 1) {
        Swal.fire({
            title: 'Cannot Remove',
            text: 'At least one product is required',
            icon: 'warning',
            confirmButtonColor: '#556ee6'
        });
        return;
    }

    const row = document.getElementById(`product-row-${index}`);
    if (row) {
        row.remove();
        calculateTotals();
    }
}

function loadProductDetails(select, index) {
    const selected = select.options[select.selectedIndex];
    if (!selected || !selected.value) {
        calculateRow(index);
        return;
    }

    const row = document.getElementById(`product-row-${index}`);
    if (!row) return;

    const priceInput = row.querySelector('.price');
    const stockSpan = document.getElementById(`stock-${index}`);
    const unitSpan = document.getElementById(`unit-${index}`);
    const gstSpan = document.getElementById(`gst-${index}`);
    const categorySpan = document.getElementById(`category-${index}`);

    if (priceInput) priceInput.value = selected.dataset.price || 0;
    if (stockSpan) stockSpan.textContent = selected.dataset.stock || 0;
    if (unitSpan) unitSpan.textContent = selected.dataset.unit || '';
    if (gstSpan) gstSpan.textContent = (selected.dataset.gst || 0) + '%';
    if (categorySpan) categorySpan.textContent = selected.dataset.category || '';

    calculateRow(index);
}

function calculateRow(index) {
    const row = document.getElementById(`product-row-${index}`);
    if (!row) return;

    const quantity = parseFloat(row.querySelector('.quantity')?.value || 0);
    const price = parseFloat(row.querySelector('.price')?.value || 0);
    const gstText = document.getElementById(`gst-${index}`)?.textContent || '0%';
    const gstRate = parseFloat(gstText) || 0;

    const subtotal = quantity * price;
    const gstAmount = subtotal * gstRate / 100;
    const total = subtotal + gstAmount;

    const rowTotal = document.getElementById(`row-total-${index}`);
    const gstAmountSpan = document.getElementById(`gst-amount-${index}`);

    if (rowTotal) rowTotal.value = total.toFixed(2);
    if (gstAmountSpan) gstAmountSpan.textContent = '₹' + gstAmount.toFixed(2);

    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0;
    let gstTotal = 0;

    document.querySelectorAll('.product-row').forEach(function(row) {
        const quantity = parseFloat(row.querySelector('.quantity')?.value || 0);
        const price = parseFloat(row.querySelector('.price')?.value || 0);
        const rowId = row.id.replace('product-row-', '');
        const gstText = document.getElementById(`gst-${rowId}`)?.textContent || '0%';
        const gstRate = parseFloat(gstText) || 0;

        const rowSubtotal = quantity * price;
        const rowGst = rowSubtotal * gstRate / 100;

        subtotal += rowSubtotal;
        gstTotal += rowGst;
    });

    const total = subtotal + gstTotal;

    document.getElementById('summary-subtotal').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('summary-gst').textContent = '₹' + gstTotal.toFixed(2);
    document.getElementById('summary-total').textContent = '₹' + total.toFixed(2);
}

function loadSupplierDetails(supplierId) {
    const select = document.getElementById('supplier_id');
    if (!select || !supplierId) {
        document.getElementById('supplier_details').style.display = 'none';
        return;
    }

    const selected = select.options[select.selectedIndex];
    if (!selected) return;

    document.getElementById('supp_code').textContent = selected.dataset.code || 'N/A';
    document.getElementById('supp_phone').textContent = selected.dataset.phone || 'N/A';
    document.getElementById('supp_email').textContent = selected.dataset.email || 'N/A';
    document.getElementById('supp_gst').textContent = selected.dataset.gst || 'N/A';
    document.getElementById('supp_terms').textContent = selected.dataset.terms || 'N/A';

    let fullAddress = selected.dataset.address || '';
    if (selected.dataset.city) fullAddress += (fullAddress ? ', ' : '') + selected.dataset.city;
    if (selected.dataset.state) fullAddress += (fullAddress ? ', ' : '') + selected.dataset.state;

    document.getElementById('supp_address').textContent = fullAddress || 'N/A';
    document.getElementById('supplier_details').style.display = 'block';
}

document.getElementById('poForm')?.addEventListener('submit', function(e) {
    const supplierId = document.getElementById('supplier_id')?.value;

    if (!supplierId) {
        e.preventDefault();
        Swal.fire({
            title: 'Validation Error',
            text: 'Please select a supplier',
            icon: 'error',
            confirmButtonColor: '#556ee6'
        });
        return;
    }

    let hasValidProduct = false;
    document.querySelectorAll('.product-row').forEach(function(row) {
        const productId = row.querySelector('.product-select')?.value || '';
        const quantity = parseFloat(row.querySelector('.quantity')?.value || 0);
        const price = parseFloat(row.querySelector('.price')?.value || 0);

        if (productId && quantity > 0 && price > 0) {
            hasValidProduct = true;
        }
    });

    if (!hasValidProduct) {
        e.preventDefault();
        Swal.fire({
            title: 'Validation Error',
            text: 'Please add at least one valid product with quantity and price',
            icon: 'error',
            confirmButtonColor: '#556ee6'
        });
        return;
    }

    const btn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const loading = document.getElementById('loading');

    if (btn) btn.disabled = true;
    if (btnText) btnText.style.display = 'none';
    if (loading) loading.style.display = 'inline-block';
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(function() {
            if (alert.parentNode) alert.remove();
        }, 500);
    });
}, 5000);

let formDirty = false;
const form = document.getElementById('poForm');
if (form) {
    form.querySelectorAll('input, select, textarea').forEach(function(input) {
        input.addEventListener('change', function() { formDirty = true; });
        input.addEventListener('input', function() { formDirty = true; });
    });

    window.addEventListener('beforeunload', function(e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    form.addEventListener('submit', function() {
        formDirty = false;
    });
}
</script>
</body>
</html>