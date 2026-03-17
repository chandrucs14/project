<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get PO ID from URL
$po_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = isset($_GET['error']) ? intval($_GET['error']) : 0;
$updated = isset($_GET['updated']) ? intval($_GET['updated']) : 0;
$deleted = isset($_GET['deleted']) ? intval($_GET['deleted']) : 0;

if ($po_id <= 0 && !$error && !$updated && !$deleted) {
    // Instead of redirecting, show error message
    $page_error = "Invalid purchase order ID";
}

// Initialize variables
$purchase_order = null;
$items = [];
$delete_error = '';

// Only fetch if we have a valid ID
if ($po_id > 0) {
    // Fetch purchase order details
    try {
        $stmt = $pdo->prepare("
            SELECT po.*, 
                   s.name as supplier_name,
                   s.company_name as supplier_company,
                   s.gst_number as supplier_gst,
                   s.phone as supplier_phone,
                   s.email as supplier_email,
                   s.address as supplier_address,
                   s.city as supplier_city,
                   s.state as supplier_state,
                   s.pincode as supplier_pincode,
                   u1.full_name as created_by_name,
                   u2.full_name as updated_by_name
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN users u1 ON po.created_by = u1.id
            LEFT JOIN users u2 ON po.updated_by = u2.id
            WHERE po.id = :id
        ");
        $stmt->execute([':id' => $po_id]);
        $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase_order) {
            $page_error = "Purchase order not found";
        } else {
            // Fetch PO items
            $itemsStmt = $pdo->prepare("
                SELECT poi.*, 
                       p.name as product_name,
                       p.unit,
                       p.category_id,
                       c.name as category_name,
                       g.gst_rate,
                       g.hsn_code
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN gst_details g ON poi.gst_id = g.id
                WHERE poi.purchase_order_id = :po_id
                ORDER BY poi.id ASC
            ");
            $itemsStmt->execute([':po_id' => $po_id]);
            $items = $itemsStmt->fetchAll();
        }

    } catch (Exception $e) {
        error_log("Error fetching purchase order: " . $e->getMessage());
        $page_error = "Database error: " . $e->getMessage();
    }
}

// Handle delete request
if (isset($_GET['delete']) && $_GET['delete'] == 'confirm' && $po_id > 0 && $purchase_order) {
    try {
        $pdo->beginTransaction();
        
        // Check if PO can be deleted (only draft or cancelled)
        if (!in_array($purchase_order['status'], ['draft', 'cancelled'])) {
            throw new Exception("Cannot delete purchase order with status: " . $purchase_order['status']);
        }
        
        // Delete PO items first
        $deleteItemsStmt = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = :po_id");
        $deleteItemsStmt->execute([':po_id' => $po_id]);
        
        // Delete PO
        $deleteStmt = $pdo->prepare("DELETE FROM purchase_orders WHERE id = :id");
        $deleteStmt->execute([':id' => $po_id]);
        
        // Log activity if table exists
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by, created_at)
                VALUES (:user_id, 4, :description, :activity_data, :created_by, NOW())
            ");
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => "Purchase Order deleted: " . $purchase_order['po_number'],
                ':activity_data' => json_encode([
                    'po_id' => $po_id,
                    'po_number' => $purchase_order['po_number'],
                    'supplier_id' => $purchase_order['supplier_id'],
                    'total_amount' => $purchase_order['total_amount']
                ]),
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
        } catch (Exception $logError) {
            // Log table might not exist, continue
            error_log("Activity log error: " . $logError->getMessage());
        }
        
        $pdo->commit();
        
        // Redirect to purchase-orders page with success message
        header("Location: purchase-orders.php?deleted=1");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $delete_error = "Failed to delete purchase order: " . $e->getMessage();
        error_log("PO deletion error: " . $e->getMessage());
    }
}

// Get status badge class
function getStatusBadge($status) {
    $badges = [
        'draft' => 'secondary',
        'sent' => 'info',
        'confirmed' => 'primary',
        'received' => 'success',
        'cancelled' => 'danger',
        'partial' => 'warning'
    ];
    return $badges[strtolower($status)] ?? 'secondary';
}

// Get status icon
function getStatusIcon($status) {
    $icons = [
        'draft' => 'mdi-pencil',
        'sent' => 'mdi-send',
        'confirmed' => 'mdi-check-circle',
        'received' => 'mdi-truck-check',
        'cancelled' => 'mdi-close-circle',
        'partial' => 'mdi-package-variant'
    ];
    return $icons[strtolower($status)] ?? 'mdi-information';
}

// Format currency
function formatCurrency($amount) {
    return '₹' . number_format(floatval($amount), 2);
}

// Helper function to safely get array value
function safeGet($array, $key, $default = 0) {
    return isset($array[$key]) ? $array[$key] : $default;
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
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 25px;
            border-left: 2px solid #e9ecef;
        }
        
        .timeline-badge {
            position: absolute;
            left: -11px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            text-align: center;
            line-height: 20px;
            color: #fff;
            font-size: 12px;
        }
        
        .timeline-content {
            padding-bottom: 20px;
        }
        
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
        
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
        }
        
        @media print {
            .vertical-menu, .topbar, .footer, .btn, .modal, 
            .page-title-right, .card-title .btn, .no-print,
            .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate {
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
            body {
                background: white;
            }
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
                            <h4 class="mb-0 font-size-18">Purchase Order Details</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="purchase-orders.php">Purchase Orders</a></li>
                                    <li class="breadcrumb-item active">View PO</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Messages -->
                <?php if (isset($page_error)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($page_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($delete_error)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($delete_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error == 1): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i>Invalid purchase order ID
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($deleted == 1): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i>Purchase order deleted successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($updated == 1): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i>Purchase order updated successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($purchase_order): ?>
                <!-- Action Bar -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                    <div>
                                        <h5 class="mb-1">PO #<?= htmlspecialchars($purchase_order['po_number']) ?></h5>
                                        <p class="text-muted mb-0">Created on <?= date('d M Y', strtotime($purchase_order['order_date'])) ?></p>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <!-- Edit Button -->
                                        <?php if (in_array(strtolower($purchase_order['status']), ['draft', 'sent'])): ?>
                                        <a href="edit-purchase-order.php?id=<?= $po_id ?>" class="btn btn-info">
                                            <i class="mdi mdi-pencil"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                        
                                        <!-- Delete Button -->
                                        <?php if (in_array(strtolower($purchase_order['status']), ['draft', 'cancelled'])): ?>
                                        <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                                            <i class="mdi mdi-delete"></i> Delete
                                        </button>
                                        <?php endif; ?>
                                        
                                        <!-- Print Button -->
                                        <a href="print-purchase-order.php?id=<?= $po_id ?>" class="btn btn-success" target="_blank">
                                            <i class="mdi mdi-printer"></i> Print
                                        </a>
                                        
                                        <!-- Back Button -->
                                        <a href="purchase-orders.php" class="btn btn-secondary">
                                            <i class="mdi mdi-arrow-left"></i> Back
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- PO Items Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Order Items</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0" id="itemsTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Unit</th>
                                                <th>Unit Price</th>
                                                <th>GST %</th>
                                                <th>GST Amount</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $subtotal = 0;
                                            $gst_total = 0;
                                            if (!empty($items)):
                                                foreach ($items as $item): 
                                                    $item_subtotal = floatval($item['quantity']) * floatval($item['unit_price']);
                                                    $subtotal += $item_subtotal;
                                                    $gst_total += floatval($item['gst_amount']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <h5 class="font-size-14 mb-1"><?= htmlspecialchars($item['product_name']) ?></h5>
                                                </td>
                                                <td><?= htmlspecialchars($item['category_name'] ?? 'N/A') ?></td>
                                                <td><?= number_format($item['quantity'], 2) ?></td>
                                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                                <td><?= formatCurrency($item['unit_price']) ?></td>
                                                <td><?= floatval($item['gst_rate']) ?>%</td>
                                                <td><?= formatCurrency($item['gst_amount']) ?></td>
                                                <td><?= formatCurrency($item['total_price']) ?></td>
                                            </tr>
                                            <?php 
                                                endforeach;
                                            else:
                                            ?>
                                            <tr>
                                                <td colspan="8" class="text-center">No items found</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if (!empty($items)): ?>
                                        <tfoot>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>Subtotal:</strong></td>
                                                <td><strong><?= formatCurrency($subtotal) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>GST Total:</strong></td>
                                                <td><strong><?= formatCurrency($gst_total) ?></strong></td>
                                            </tr>
                                            <?php if (floatval(safeGet($purchase_order, 'discount_amount')) > 0): ?>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>Discount:</strong></td>
                                                <td><strong class="text-danger">-<?= formatCurrency($purchase_order['discount_amount']) ?></strong></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if (floatval(safeGet($purchase_order, 'shipping_charge')) > 0): ?>
                                            <tr>
                                                <td colspan="7" class="text-end"><strong>Shipping Charge:</strong></td>
                                                <td><strong><?= formatCurrency($purchase_order['shipping_charge']) ?></strong></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr class="border-top">
                                                <td colspan="7" class="text-end"><h5>Total Amount:</h5></td>
                                                <td><h5 class="text-success"><?= formatCurrency($purchase_order['total_amount']) ?></h5></td>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Notes Card -->
                        <?php if (!empty($purchase_order['notes']) || !empty($purchase_order['terms'])): ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <?php if (!empty($purchase_order['notes'])): ?>
                                    <div class="col-md-6">
                                        <h5 class="font-size-14 mb-3">Notes</h5>
                                        <p class="text-muted"><?= nl2br(htmlspecialchars($purchase_order['notes'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($purchase_order['terms'])): ?>
                                    <div class="col-md-6">
                                        <h5 class="font-size-14 mb-3">Terms & Conditions</h5>
                                        <p class="text-muted"><?= nl2br(htmlspecialchars($purchase_order['terms'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Audit Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Audit Information</h4>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Created By:</strong> <?= htmlspecialchars($purchase_order['created_by_name'] ?? 'System') ?></p>
                                        <p class="text-muted"><?= date('d M Y h:i A', strtotime($purchase_order['created_at'])) ?></p>
                                    </div>
                                    <?php if (!empty($purchase_order['updated_by_name'])): ?>
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Last Updated By:</strong> <?= htmlspecialchars($purchase_order['updated_by_name']) ?></p>
                                        <p class="text-muted"><?= date('d M Y h:i A', strtotime($purchase_order['updated_at'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Supplier Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-truck me-2"></i>
                                    Supplier Information
                                </h4>
                                
                                <div class="mb-3">
                                    <h5 class="mb-1"><?= htmlspecialchars($purchase_order['supplier_name']) ?></h5>
                                    <?php if (!empty($purchase_order['supplier_company'])): ?>
                                    <p class="text-muted"><?= htmlspecialchars($purchase_order['supplier_company']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($purchase_order['supplier_gst'])): ?>
                                <div class="d-flex mb-2">
                                    <i class="mdi mdi-certificate text-primary me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">GST Number</small>
                                        <span><?= htmlspecialchars($purchase_order['supplier_gst']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($purchase_order['supplier_phone'])): ?>
                                <div class="d-flex mb-2">
                                    <i class="mdi mdi-phone text-success me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">Phone</small>
                                        <span><?= htmlspecialchars($purchase_order['supplier_phone']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($purchase_order['supplier_email'])): ?>
                                <div class="d-flex mb-2">
                                    <i class="mdi mdi-email text-info me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">Email</small>
                                        <span><?= htmlspecialchars($purchase_order['supplier_email']) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($purchase_order['supplier_address'])): ?>
                                <div class="d-flex">
                                    <i class="mdi mdi-map-marker text-danger me-2" style="font-size: 18px;"></i>
                                    <div>
                                        <small class="text-muted d-block">Address</small>
                                        <span>
                                            <?= htmlspecialchars($purchase_order['supplier_address']) ?><br>
                                            <?= htmlspecialchars($purchase_order['supplier_city'] ?? '') ?> 
                                            <?= htmlspecialchars($purchase_order['supplier_state'] ?? '') ?> 
                                            <?= htmlspecialchars($purchase_order['supplier_pincode'] ?? '') ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Order Summary Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Order Summary</h4>
                                
                                <div class="mb-3">
                                    <label class="text-muted mb-1">Order Date</label>
                                    <p class="fw-bold"><?= date('d F Y', strtotime($purchase_order['order_date'])) ?></p>
                                </div>
                                
                                <?php if (!empty($purchase_order['expected_delivery'])): ?>
                                <div class="mb-3">
                                    <label class="text-muted mb-1">Expected Delivery</label>
                                    <p class="fw-bold"><?= date('d F Y', strtotime($purchase_order['expected_delivery'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="text-muted mb-1">Payment Terms</label>
                                    <p class="fw-bold"><?= htmlspecialchars($purchase_order['payment_terms'] ?? 'N/A') ?></p>
                                </div>
                                
                                <hr>
                                
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span class="fw-bold"><?= formatCurrency($subtotal ?? 0) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>GST Total:</span>
                                    <span class="fw-bold"><?= formatCurrency($gst_total ?? 0) ?></span>
                                </div>
                                <?php if (floatval(safeGet($purchase_order, 'discount_amount')) > 0): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Discount:</span>
                                    <span class="fw-bold text-danger">-<?= formatCurrency($purchase_order['discount_amount']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (floatval(safeGet($purchase_order, 'shipping_charge')) > 0): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shipping:</span>
                                    <span class="fw-bold"><?= formatCurrency($purchase_order['shipping_charge']) ?></span>
                                </div>
                                <?php endif; ?>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span class="h5">Total:</span>
                                    <span class="h5 text-success"><?= formatCurrency($purchase_order['total_amount']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline Card -->
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Order Timeline</h4>
                                
                                <div class="timeline">
                                    <div class="timeline-item pb-3">
                                        <div class="timeline-badge bg-success"><i class="mdi mdi-check"></i></div>
                                        <div class="timeline-content">
                                            <h6 class="mb-0">Order Created</h6>
                                            <small class="text-muted"><?= date('d M Y h:i A', strtotime($purchase_order['created_at'])) ?></small>
                                        </div>
                                    </div>
                                    
                                    <?php if (in_array(strtolower($purchase_order['status']), ['confirmed', 'received', 'partial'])): ?>
                                    <div class="timeline-item pb-3">
                                        <div class="timeline-badge bg-primary"><i class="mdi mdi-check-circle"></i></div>
                                        <div class="timeline-content">
                                            <h6 class="mb-0">Order Confirmed</h6>
                                            <small class="text-muted">Status: Confirmed</small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (strtolower($purchase_order['status']) == 'received'): ?>
                                    <div class="timeline-item pb-3">
                                        <div class="timeline-badge bg-success"><i class="mdi mdi-truck-check"></i></div>
                                        <div class="timeline-content">
                                            <h6 class="mb-0">Order Received</h6>
                                            <small class="text-muted">Status: Received</small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (strtolower($purchase_order['status']) == 'partial'): ?>
                                    <div class="timeline-item pb-3">
                                        <div class="timeline-badge bg-warning"><i class="mdi mdi-package-variant"></i></div>
                                        <div class="timeline-content">
                                            <h6 class="mb-0">Partially Received</h6>
                                            <small class="text-muted">Status: Partial</small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (strtolower($purchase_order['status']) == 'cancelled'): ?>
                                    <div class="timeline-item pb-3">
                                        <div class="timeline-badge bg-danger"><i class="mdi mdi-close-circle"></i></div>
                                        <div class="timeline-content">
                                            <h6 class="mb-0">Order Cancelled</h6>
                                            <small class="text-muted">Status: Cancelled</small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->
                <?php endif; ?>

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
<!-- DataTables JS -->
<script src="assets/libs/datatables/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables/dataTables.bootstrap5.min.js"></script>

<script>
    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

    // Initialize DataTable
    $(document).ready(function() {
        if ($('#itemsTable').length > 0) {
            $('#itemsTable').DataTable({
                paging: false,
                searching: false,
                ordering: true,
                info: false
            });
        }
    });

    // Confirm delete function
    function confirmDelete() {
        Swal.fire({
            title: 'Delete Purchase Order',
            text: 'Are you sure you want to delete this purchase order? This action cannot be undone!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'view-purchase-order.php?id=<?= $po_id ?>&delete=confirm';
            }
        });
    }

    // Auto-hide alerts
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            try {
                var bsAlert = new bootstrap.Alert(alert);
                setTimeout(function() {
                    bsAlert.close();
                }, 5000);
            } catch(e) {
                // If alert is already closed, ignore
            }
        });
    }, 100);
</script>

</body>
</html>