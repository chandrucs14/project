<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Initialize variables
$error = '';
$success = '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle purchase order status update
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $po_id = (int)$_GET['id'];
    
    $allowed_actions = ['draft', 'sent', 'confirmed', 'partially_received', 'completed', 'cancelled'];
    if (in_array($action, $allowed_actions)) {
        try {
            $pdo->beginTransaction();
            
            // Get PO details before update
            $getStmt = $pdo->prepare("SELECT po_number, supplier_id, total_amount FROM purchase_orders WHERE id = ?");
            $getStmt->execute([$po_id]);
            $po = $getStmt->fetch();
            
            if (!$po) {
                throw new Exception("Purchase order not found.");
            }
            
            // Update PO status
            $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$action, $_SESSION['user_id'], $po_id]);
            
            // If status is completed, update supplier outstanding
            if ($action === 'completed') {
                $updateSupplierStmt = $pdo->prepare("UPDATE suppliers SET outstanding_balance = outstanding_balance + ? WHERE id = ?");
                $updateSupplierStmt->execute([$po['total_amount'], $po['supplier_id']]);
                
                // Add to supplier_outstanding
                $outStmt = $pdo->prepare("
                    INSERT INTO supplier_outstanding (
                        supplier_id, transaction_type, reference_id, transaction_date, 
                        amount, balance_after, status, created_by, created_at
                    ) VALUES (?, 'purchase', ?, CURDATE(), ?, ?, 'pending', ?, NOW())
                ");
                
                // Get updated outstanding
                $balStmt = $pdo->prepare("SELECT outstanding_balance FROM suppliers WHERE id = ?");
                $balStmt->execute([$po['supplier_id']]);
                $newBalance = $balStmt->fetchColumn();
                
                $outStmt->execute([
                    $po['supplier_id'],
                    $po_id,
                    $po['total_amount'],
                    $newBalance,
                    $_SESSION['user_id']
                ]);
            }
            
            // If status is cancelled, revert any stock updates (to be implemented)
            
            // Get supplier name for logging
            $suppStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
            $suppStmt->execute([$po['supplier_id']]);
            $supplier = $suppStmt->fetch();
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'po_id' => $po_id,
                'po_number' => $po['po_number'],
                'supplier_name' => $supplier['name'],
                'new_status' => $action
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Purchase Order #{$po['po_number']} status updated to $action",
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Purchase order status updated successfully.";
            header("Location: purchase-orders.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to update purchase order status: " . $e->getMessage();
            error_log("PO status update error: " . $e->getMessage());
        }
    }
}

// Handle delete purchase order
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $po_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if PO has items
        $checkItems = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?");
        $checkItems->execute([$po_id]);
        $itemCount = $checkItems->fetchColumn();
        
        // Get PO details for logging
        $getStmt = $pdo->prepare("SELECT po_number, supplier_id, status, total_amount FROM purchase_orders WHERE id = ?");
        $getStmt->execute([$po_id]);
        $po = $getStmt->fetch();
        
        if (!$po) {
            throw new Exception("Purchase order not found.");
        }
        
        // Get supplier name
        $suppStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $suppStmt->execute([$po['supplier_id']]);
        $supplier = $suppStmt->fetch();
        
        // If PO was completed, update supplier outstanding
        if ($po['status'] === 'completed') {
            $updateSupplierStmt = $pdo->prepare("UPDATE suppliers SET outstanding_balance = outstanding_balance - ? WHERE id = ?");
            $updateSupplierStmt->execute([$po['total_amount'], $po['supplier_id']]);
        }
        
        // Delete PO items
        if ($itemCount > 0) {
            $delItems = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
            $delItems->execute([$po_id]);
        }
        
        // Delete the PO
        $stmt = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?");
        $result = $stmt->execute([$po_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'po_id' => $po_id,
                'po_number' => $po['po_number'],
                'supplier_name' => $supplier['name']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Purchase Order #{$po['po_number']} deleted",
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Purchase order deleted successfully.";
        }
        
        header("Location: purchase-orders.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to delete purchase order: " . $e->getMessage();
        header("Location: purchase-orders.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
    }
}

// Build the query
$query = "
    SELECT 
        po.*,
        s.name as supplier_name,
        s.supplier_code,
        s.phone as supplier_phone,
        s.email as supplier_email,
        s.company_name,
        u.full_name as created_by_name,
        u2.full_name as updated_by_name,
        (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = po.id) as item_count,
        (SELECT COALESCE(SUM(received_quantity), 0) FROM purchase_order_items WHERE purchase_order_id = po.id) as total_received
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN users u2 ON po.updated_by = u2.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE 1=1
";

$params = [];

if ($supplier_id > 0) {
    $query .= " AND po.supplier_id = ?";
    $countQuery .= " AND po.supplier_id = ?";
    $params[] = $supplier_id;
}

if (!empty($search)) {
    $query .= " AND (po.po_number LIKE ? OR s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.company_name LIKE ?)";
    $countQuery .= " AND (po.po_number LIKE ? OR s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.company_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $query .= " AND po.status = ?";
    $countQuery .= " AND po.status = ?";
    $params[] = $status;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(po.order_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(po.order_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY po.order_date DESC, po.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$purchase_orders = $stmt->fetchAll();

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_pos,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_pos,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_pos,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_pos,
        SUM(CASE WHEN status = 'partially_received' THEN 1 ELSE 0 END) as partially_received_pos,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_pos,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_pos,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(AVG(total_amount), 0) as avg_amount
    FROM purchase_orders
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get all suppliers for dropdown
$suppStmt = $pdo->query("SELECT id, name, supplier_code, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppStmt->fetchAll();

// Get selected supplier details if supplier_id is provided
$selectedSupplier = null;
if ($supplier_id > 0) {
    $selSuppStmt = $pdo->prepare("SELECT name, supplier_code, company_name, phone, email FROM suppliers WHERE id = ?");
    $selSuppStmt->execute([$supplier_id]);
    $selectedSupplier = $selSuppStmt->fetch();
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
                            <h4 class="mb-0 font-size-18">Purchase Orders</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-suppliers.php">Suppliers</a></li>
                                    <li class="breadcrumb-item active">Purchase Orders</li>
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

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total_pos'] ?? 0) ?></h3>
                                Total POs
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['draft_pos'] ?? 0) ?></h3>
                                Draft
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['completed_pos'] ?? 0) ?></h3>
                                Completed
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">₹<?= number_format($stats['total_amount'] ?? 0, 2) ?></h3>
                                Total Value
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats Row -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2"><?= number_format($stats['sent_pos'] ?? 0) ?></h3>
                                Sent
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['confirmed_pos'] ?? 0) ?></h3>
                                Confirmed
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['partially_received_pos'] ?? 0) ?></h3>
                                Partially Received
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2"><?= number_format($stats['cancelled_pos'] ?? 0) ?></h3>
                                Cancelled
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Filter and Actions Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-9">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Supplier</label>
                                                        <select name="supplier_id" class="form-control">
                                                            <option value="0">All Suppliers</option>
                                                            <?php foreach ($suppliers as $supp): ?>
                                                                <option value="<?= $supp['id'] ?>" <?= $supplier_id == $supp['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($supp['name']) ?> 
                                                                    <?php if ($supp['company_name']): ?>
                                                                        (<?= htmlspecialchars($supp['company_name']) ?>)
                                                                    <?php endif; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">From Date</label>
                                                        <input type="date" class="form-control" name="from_date" value="<?= $from_date ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">To Date</label>
                                                        <input type="date" class="form-control" name="to_date" value="<?= $to_date ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                            <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                                                            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                            <option value="partially_received" <?= $status === 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                                                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-50">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                            <a href="purchase-orders.php" class="btn btn-secondary w-50">
                                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-md-12">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               name="search" 
                                                               placeholder="Search by PO number, supplier name, phone, email or company..." 
                                                               value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="create-po.php" class="btn btn-success">
                                                <i class="mdi mdi-cart-plus me-1"></i> Create Purchase Order
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Supplier Details (if selected) -->
                <?php if ($selectedSupplier): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Supplier</h6>
                                        <h5><?= htmlspecialchars($selectedSupplier['name']) ?></h5>
                                        <p class="mb-0">Code: <?= htmlspecialchars($selectedSupplier['supplier_code']) ?></p>
                                        <?php if ($selectedSupplier['company_name']): ?>
                                            <small><?= htmlspecialchars($selectedSupplier['company_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Contact</h6>
                                        <p class="mb-1"><i class="mdi mdi-phone me-1"></i> <?= htmlspecialchars($selectedSupplier['phone'] ?? 'N/A') ?></p>
                                        <p class="mb-0"><i class="mdi mdi-email me-1"></i> <?= htmlspecialchars($selectedSupplier['email'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Purchase Orders Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Purchase Orders List</h4>
                                
                                <?php if (empty($purchase_orders)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-cart-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No purchase orders found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="create-po.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-cart-plus me-1"></i> Create Purchase Order
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>PO #</th>
                                                    <th>Supplier</th>
                                                    <th>Order Date</th>
                                                    <th>Expected Delivery</th>
                                                    <th>Items</th>
                                                    <th>Total Amount</th>
                                                    <th>Received</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($purchase_orders as $po): ?>
                                                    <?php 
                                                    $received_percentage = $po['total_amount'] > 0 ? ($po['total_received'] / $po['total_amount']) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                                        </td>
                                                        <td>
                                                            <a href="view-supplier.php?id=<?= $po['supplier_id'] ?>" class="text-primary">
                                                                <?= htmlspecialchars($po['supplier_name']) ?>
                                                            </a>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($po['supplier_code']) ?></small>
                                                            <?php if ($po['company_name']): ?>
                                                                <br><small><?= htmlspecialchars($po['company_name']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= date('d-m-Y', strtotime($po['order_date'])) ?></td>
                                                        <td>
                                                            <?= $po['expected_delivery'] ? date('d-m-Y', strtotime($po['expected_delivery'])) : '-' ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info"><?= $po['item_count'] ?> items</span>
                                                        </td>
                                                        <td>₹<?= number_format($po['total_amount'], 2) ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="me-2"><?= number_format($received_percentage, 1) ?>%</span>
                                                                <div class="progress flex-grow-1" style="height: 5px;">
                                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                                         style="width: <?= $received_percentage ?>%;" 
                                                                         aria-valuenow="<?= $received_percentage ?>" 
                                                                         aria-valuemin="0" 
                                                                         aria-valuemax="100"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = '';
                                                            $statusIcon = '';
                                                            switch($po['status']) {
                                                                case 'draft':
                                                                    $statusClass = 'secondary';
                                                                    $statusIcon = 'file-document-outline';
                                                                    break;
                                                                case 'sent':
                                                                    $statusClass = 'primary';
                                                                    $statusIcon = 'send';
                                                                    break;
                                                                case 'confirmed':
                                                                    $statusClass = 'info';
                                                                    $statusIcon = 'check-circle';
                                                                    break;
                                                                case 'partially_received':
                                                                    $statusClass = 'warning';
                                                                    $statusIcon = 'truck-check';
                                                                    break;
                                                                case 'completed':
                                                                    $statusClass = 'success';
                                                                    $statusIcon = 'check-all';
                                                                    break;
                                                                case 'cancelled':
                                                                    $statusClass = 'danger';
                                                                    $statusIcon = 'close-circle';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                                <i class="mdi mdi-<?= $statusIcon ?> me-1"></i>
                                                                <?= ucfirst(str_replace('_', ' ', $po['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="view-purchase-order.php?id=<?= $po['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-primary" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="View Details">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </a>
                                                                <a href="edit-purchase-order.php?id=<?= $po['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-success" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </a>
                                                                <a href="receive-purchase-order.php?id=<?= $po['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-info" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Receive Items">
                                                                    <i class="mdi mdi-truck"></i>
                                                                </a>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-soft-warning dropdown-toggle" 
                                                                        data-bs-toggle="dropdown" 
                                                                        aria-expanded="false"
                                                                        data-bs-toggle="tooltip" 
                                                                        title="Change Status">
                                                                    <i class="mdi mdi-cog"></i>
                                                                </button>
                                                                <ul class="dropdown-menu">
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'draft' ? 'disabled' : '' ?>" 
                                                                           href="?action=draft&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-file-document-outline text-secondary me-2"></i> Draft</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'sent' ? 'disabled' : '' ?>" 
                                                                           href="?action=sent&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-send text-primary me-2"></i> Sent</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'confirmed' ? 'disabled' : '' ?>" 
                                                                           href="?action=confirmed&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-check-circle text-info me-2"></i> Confirmed</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'partially_received' ? 'disabled' : '' ?>" 
                                                                           href="?action=partially_received&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-truck-check text-warning me-2"></i> Partially Received</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'completed' ? 'disabled' : '' ?>" 
                                                                           href="?action=completed&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-check-all text-success me-2"></i> Completed</a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li><a class="dropdown-item <?= $po['status'] == 'cancelled' ? 'disabled' : '' ?> text-danger" 
                                                                           href="?action=cancelled&id=<?= $po['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-close-circle text-danger me-2"></i> Cancel PO</a>
                                                                    </li>
                                                                </ul>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $po['id'] ?>, '<?= htmlspecialchars(addslashes($po['po_number'])) ?>', '<?= htmlspecialchars(addslashes($search)) ?>', '<?= $supplier_id ?>', '<?= $status ?>', '<?= $from_date ?>', '<?= $to_date ?>', <?= $page ?>)"
                                                                   class="btn btn-sm btn-soft-danger"
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Delete">
                                                                    <i class="mdi mdi-delete"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- end table-responsive -->

                                    <!-- Pagination -->
                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-sm-6">
                                                <div class="text-muted">
                                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <ul class="pagination justify-content-end">
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Purchase Summary -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Purchase Summary</h5>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Purchase Value:</span>
                                        <strong>₹<?= number_format($stats['total_amount'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Average Order Value:</span>
                                        <strong>₹<?= number_format($stats['avg_amount'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total POs:</span>
                                        <strong><?= number_format($stats['total_pos'] ?? 0) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Status Distribution</h5>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-secondary"><?= number_format($stats['draft_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Draft</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?= number_format($stats['sent_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Sent</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-info"><?= number_format($stats['confirmed_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Confirmed</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?= number_format($stats['partially_received_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Partially Received</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-success"><?= number_format($stats['completed_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Completed</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-danger"><?= number_format($stats['cancelled_pos'] ?? 0) ?></h4>
                                            <p class="mb-0">Cancelled</p>
                                        </div>
                                    </div>
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
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
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
    
    // Search with debounce
    let searchTimeout;
    document.querySelector('input[name="search"]')?.addEventListener('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });
    
    // Auto-submit on filter changes
    document.querySelector('select[name="supplier_id"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Date validation
    document.querySelector('input[name="from_date"]')?.addEventListener('change', function() {
        const toDate = document.querySelector('input[name="to_date"]');
        if (toDate.value < this.value) {
            toDate.value = this.value;
        }
    });
    
    document.querySelector('input[name="to_date"]')?.addEventListener('change', function() {
        const fromDate = document.querySelector('input[name="from_date"]');
        if (fromDate.value > this.value) {
            fromDate.value = this.value;
        }
    });
    
    // Confirm delete action
    function confirmDelete(id, poNumber, search, supplierId, status, fromDate, toDate, page) {
        Swal.fire({
            title: 'Delete Purchase Order?',
            html: `Are you sure you want to delete purchase order <strong>#${poNumber}</strong>?<br><br>
                   <span class="text-danger">This action cannot be undone!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Deleting...',
                    text: 'Please wait while we delete the purchase order',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                window.location.href = `purchase-orders.php?delete=1&id=${id}&supplier_id=${supplierId}&search=${encodeURIComponent(search)}&status=${status}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Confirm status change
    function confirmStatusChange(action, id, poNumber, search, supplierId, status, fromDate, toDate, page) {
        let title, text, icon, confirmButtonColor;
        
        switch(action) {
            case 'cancelled':
                title = 'Cancel Purchase Order?';
                text = `Are you sure you want to cancel purchase order <strong>#${poNumber}</strong>?`;
                icon = 'warning';
                confirmButtonColor = '#f46a6a';
                break;
            case 'completed':
                title = 'Mark as Completed?';
                text = `Are you sure you want to mark purchase order <strong>#${poNumber}</strong> as completed? This will update supplier outstanding.`;
                icon = 'success';
                confirmButtonColor = '#34c38f';
                break;
            default:
                title = 'Change PO Status?';
                text = `Are you sure you want to change the status of purchase order <strong>#${poNumber}</strong>?`;
                icon = 'question';
                confirmButtonColor = '#556ee6';
        }
        
        Swal.fire({
            title: title,
            html: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: confirmButtonColor,
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `purchase-orders.php?action=${action}&id=${id}&supplier_id=${supplierId}&search=${encodeURIComponent(search)}&status=${status}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + P for new purchase order
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            window.location.href = 'create-po.php';
        }
        
        // Ctrl + F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.querySelector('input[name="search"]')?.focus();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value !== '') {
                searchInput.value = '';
                document.getElementById('filterForm').submit();
            }
        }
    });
</script>

</body>
</html>