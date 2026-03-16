<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}


// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : date('Y-m-01');
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : date('Y-m-d');
$filter_supplier = isset($_GET['filter_supplier']) ? $_GET['filter_supplier'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get suppliers for filter dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, supplier_code FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Handle status update
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && isset($_GET['id']) && isset($_GET['status'])) {
    $po_id = (int)$_GET['id'];
    $new_status = $_GET['status'];
    
    // Validate status
    $valid_statuses = ['draft', 'sent', 'confirmed', 'partially_received', 'completed', 'cancelled'];
    if (in_array($new_status, $valid_statuses)) {
        try {
            $pdo->beginTransaction();
            
            // Get PO details for logging
            $poStmt = $pdo->prepare("SELECT po_number, supplier_id FROM purchase_orders WHERE id = ?");
            $poStmt->execute([$po_id]);
            $po = $poStmt->fetch();
            
            if ($po) {
                // Update status
                $updateStmt = $pdo->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $updateStmt->execute([$new_status, $_SESSION['user_id'], $po_id]);
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'po_id' => $po_id,
                    'po_number' => $po['po_number'],
                    'old_status' => $_GET['old_status'] ?? 'unknown',
                    'new_status' => $new_status
                ]);
                
                $logStmt->execute([
                    $_SESSION['user_id'],
                    "Purchase order status updated: {$po['po_number']} to $new_status",
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Purchase order status updated successfully.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
            error_log("Status update error: " . $e->getMessage());
        }
    }
    header("Location: purchase-orders.php?" . http_build_query($_GET));
    exit();
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $po_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get PO details for logging
        $poStmt = $pdo->prepare("SELECT po_number, supplier_id, total_amount FROM purchase_orders WHERE id = ?");
        $poStmt->execute([$po_id]);
        $po = $poStmt->fetch();
        
        if ($po) {
            // Check if PO can be deleted (only draft or cancelled)
            $statusCheck = $pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
            $statusCheck->execute([$po_id]);
            $status = $statusCheck->fetchColumn();
            
            if (!in_array($status, ['draft', 'cancelled'])) {
                throw new Exception("Only draft or cancelled orders can be deleted.");
            }
            
            // Delete items first
            $deleteItems = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
            $deleteItems->execute([$po_id]);
            
            // Delete PO
            $deletePO = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?");
            $deletePO->execute([$po_id]);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'po_id' => $po_id,
                'po_number' => $po['po_number'],
                'supplier_id' => $po['supplier_id'],
                'total_amount' => $po['total_amount']
            ]);
            
            $logStmt->execute([
                $_SESSION['user_id'],
                "Purchase order deleted: " . $po['po_number'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Purchase order deleted successfully.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error deleting purchase order: " . $e->getMessage();
        error_log("Delete error: " . $e->getMessage());
    }
    
    header("Location: purchase-orders.php?" . http_build_query($_GET));
    exit();
}

// Build query with filters
$query = "
    SELECT po.*, 
           s.name as supplier_name,
           s.supplier_code,
           s.company_name,
           u.full_name as created_by_name,
           (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = po.id) as item_count
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE 1=1
";

$count_query = "
    SELECT COUNT(*) as total 
    FROM purchase_orders po
    WHERE 1=1
";

$params = [];

// Apply date filters
if (!empty($filter_date_from)) {
    $query .= " AND DATE(po.order_date) >= :date_from";
    $count_query .= " AND DATE(po.order_date) >= :date_from";
    $params[':date_from'] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND DATE(po.order_date) <= :date_to";
    $count_query .= " AND DATE(po.order_date) <= :date_to";
    $params[':date_to'] = $filter_date_to;
}

// Apply supplier filter
if (!empty($filter_supplier)) {
    $query .= " AND po.supplier_id = :supplier_id";
    $count_query .= " AND po.supplier_id = :supplier_id";
    $params[':supplier_id'] = $filter_supplier;
}

// Apply status filter
if (!empty($filter_status)) {
    $query .= " AND po.status = :status";
    $count_query .= " AND po.status = :status";
    $params[':status'] = $filter_status;
}

// Apply search
if (!empty($search)) {
    $query .= " AND (po.po_number LIKE :search OR s.name LIKE :search OR s.company_name LIKE :search)";
    $count_query .= " AND (po.po_number LIKE :search OR s.name LIKE :search OR s.company_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Get total records for pagination
$countStmt = $pdo->prepare($count_query);
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get purchase orders for current page
$query .= " ORDER BY po.order_date DESC, po.created_at DESC LIMIT :offset, :limit";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$purchase_orders = $stmt->fetchAll();

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(AVG(total_amount), 0) as avg_amount,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN status = 'partially_received' THEN 1 ELSE 0 END) as partially_received_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
    FROM purchase_orders po
    WHERE 1=1
";

$summary_params = [];
if (!empty($filter_date_from)) {
    $summary_query .= " AND DATE(order_date) >= :date_from";
    $summary_params[':date_from'] = $filter_date_from;
}
if (!empty($filter_date_to)) {
    $summary_query .= " AND DATE(order_date) <= :date_to";
    $summary_params[':date_to'] = $filter_date_to;
}
if (!empty($filter_supplier)) {
    $summary_query .= " AND supplier_id = :supplier_id";
    $summary_params[':supplier_id'] = $filter_supplier;
}

$summaryStmt = $pdo->prepare($summary_query);
$summaryStmt->execute($summary_params);
$summary = $summaryStmt->fetch();

// Check for session messages
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .status-badge {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 30px;
            display: inline-block;
        }
        .status-draft { background-color: #e9ecef; color: #495057; }
        .status-sent { background-color: #cfe2ff; color: #084298; }
        .status-confirmed { background-color: #d1e7dd; color: #0f5132; }
        .status-partially_received { background-color: #fff3cd; color: #856404; }
        .status-completed { background-color: #d1e7dd; color: #0f5132; }
        .status-cancelled { background-color: #f8d7da; color: #842029; }
        
        .table td { vertical-align: middle; }
        .avatar-sm {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        .btn-group .btn { margin: 0 2px; }
        .summary-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
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
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Purchases</a></li>
                                    <li class="breadcrumb-item active">Purchase Orders</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="create-purchase.php" class="btn btn-primary">
                                        <i class="mdi mdi-plus"></i> Create New PO
                                    </a>
                                    <a href="purchase-report.php" class="btn btn-info">
                                        <i class="mdi mdi-chart-bar"></i> Purchase Report
                                    </a>
                                    <button type="button" class="btn btn-success" id="exportBtn">
                                        <i class="mdi mdi-export"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Statistics Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-cart-arrow-down font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Orders</p>
                                        <h4><?= number_format($summary['total_orders'] ?? 0) ?></h4>
                                        <small class="text-muted">Value: ₹<?= number_format($summary['total_amount'] ?? 0, 2) ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('draft')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-secondary text-secondary rounded-circle">
                                                <i class="mdi mdi-pencil font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Draft</p>
                                        <h4><?= number_format($summary['draft_count'] ?? 0) ?></h4>
                                        <small class="text-muted">Pending creation</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('sent')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-send font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Sent</p>
                                        <h4><?= number_format($summary['sent_count'] ?? 0) ?></h4>
                                        <small class="text-muted">Awaiting confirmation</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('confirmed')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-check-circle font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Confirmed</p>
                                        <h4><?= number_format($summary['confirmed_count'] ?? 0) ?></h4>
                                        <small class="text-muted">Supplier confirmed</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('partially_received')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-truck-fast font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Partially Received</p>
                                        <h4><?= number_format($summary['partially_received_count'] ?? 0) ?></h4>
                                        <small class="text-muted">Some items received</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card" onclick="filterByStatus('completed')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-check-all font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Completed</p>
                                        <h4><?= number_format($summary['completed_count'] ?? 0) ?></h4>
                                        <small class="text-muted">Fully received</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end statistics cards -->

                <!-- Filter Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Purchase Orders</h4>
                                <form method="GET" action="purchase-orders.php" class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">From Date</label>
                                        <input type="date" class="form-control" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">To Date</label>
                                        <input type="date" class="form-control" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Supplier</label>
                                        <select class="form-control select2" name="filter_supplier">
                                            <option value="">All Suppliers</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?= $supplier['id'] ?>" <?= $filter_supplier == $supplier['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($supplier['name']) ?> (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-control" name="filter_status">
                                            <option value="">All Status</option>
                                            <option value="draft" <?= $filter_status == 'draft' ? 'selected' : '' ?>>Draft</option>
                                            <option value="sent" <?= $filter_status == 'sent' ? 'selected' : '' ?>>Sent</option>
                                            <option value="confirmed" <?= $filter_status == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                            <option value="partially_received" <?= $filter_status == 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                                            <option value="completed" <?= $filter_status == 'completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="cancelled" <?= $filter_status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="mdi mdi-filter"></i> Apply
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <!-- Search and Per Page -->
                                <form method="GET" action="purchase-orders.php" class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Search by PO number, supplier..." value="<?= htmlspecialchars($search) ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="mdi mdi-magnify"></i> Search
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="per_page" class="form-select" onchange="this.form.submit()">
                                            <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                            <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20 per page</option>
                                            <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                            <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="purchase-orders.php" class="btn btn-secondary w-100">
                                            <i class="mdi mdi-refresh"></i> Reset Filters
                                        </a>
                                    </div>
                                    <?php 
                                    // Preserve other filter parameters
                                    foreach (['filter_date_from', 'filter_date_to', 'filter_supplier', 'filter_status'] as $param):
                                        if (!empty($_GET[$param])):
                                    ?>
                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param]) ?>">
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter section -->

                <!-- Purchase Orders Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Purchase Order List</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <span class="text-muted">
                                                Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover table-centered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>PO #</th>
                                                <th>Supplier</th>
                                                <th>Order Date</th>
                                                <th>Expected Delivery</th>
                                                <th>Items</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($purchase_orders)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline" style="font-size: 48px;"></i>
                                                    <p class="mt-2">No purchase orders found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($purchase_orders as $po): ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                            <a href="view-purchase-order.php?id=<?= $po['id'] ?>" class="text-dark">
                                                                <?= htmlspecialchars($po['po_number']) ?>
                                                            </a>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-sm rounded-circle bg-soft-info text-info me-2 d-flex align-items-center justify-content-center">
                                                                <?= strtoupper(substr($po['supplier_name'] ?? 'S', 0, 1)) ?>
                                                            </div>
                                                            <div>
                                                                <h6 class="mb-0"><?= htmlspecialchars($po['supplier_name'] ?? 'Unknown') ?></h6>
                                                                <small class="text-muted"><?= htmlspecialchars($po['supplier_code'] ?? '') ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?= date('d M Y', strtotime($po['order_date'])) ?></td>
                                                    <td>
                                                        <?= $po['expected_delivery'] ? date('d M Y', strtotime($po['expected_delivery'])) : '-' ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-soft-info text-info">
                                                            <?= $po['item_count'] ?> items
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong>₹<?= number_format($po['total_amount'], 2) ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        switch($po['status']) {
                                                            case 'draft': $status_class = 'status-draft'; break;
                                                            case 'sent': $status_class = 'status-sent'; break;
                                                            case 'confirmed': $status_class = 'status-confirmed'; break;
                                                            case 'partially_received': $status_class = 'status-partially_received'; break;
                                                            case 'completed': $status_class = 'status-completed'; break;
                                                            case 'cancelled': $status_class = 'status-cancelled'; break;
                                                        }
                                                        ?>
                                                        <span class="status-badge <?= $status_class ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $po['status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= date('d M Y', strtotime($po['created_at'])) ?><br>
                                                            <span class="text-muted"><?= htmlspecialchars($po['created_by_name'] ?? 'System') ?></span>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <a href="view-purchase-order.php?id=<?= $po['id'] ?>" 
                                                               class="btn btn-sm btn-soft-primary" 
                                                               title="View Details"
                                                               data-bs-toggle="tooltip">
                                                                <i class="mdi mdi-eye"></i>
                                                            </a>
                                                            
                                                            <?php if ($po['status'] == 'draft'): ?>
                                                            <a href="edit-purchase-order.php?id=<?= $po['id'] ?>" 
                                                               class="btn btn-sm btn-soft-info" 
                                                               title="Edit"
                                                               data-bs-toggle="tooltip">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </a>
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-soft-success" 
                                                                    title="Send to Supplier"
                                                                    data-bs-toggle="tooltip"
                                                                    onclick="updateStatus(<?= $po['id'] ?>, 'sent')">
                                                                <i class="mdi mdi-send"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($po['status'] == 'sent'): ?>
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-soft-info" 
                                                                    title="Confirm Order"
                                                                    data-bs-toggle="tooltip"
                                                                    onclick="updateStatus(<?= $po['id'] ?>, 'confirmed')">
                                                                <i class="mdi mdi-check-circle"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (in_array($po['status'], ['confirmed', 'partially_received'])): ?>
                                                            <a href="receive-purchase.php?id=<?= $po['id'] ?>" 
                                                               class="btn btn-sm btn-soft-warning" 
                                                               title="Receive Items"
                                                               data-bs-toggle="tooltip">
                                                                <i class="mdi mdi-truck"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($po['status'] == 'draft'): ?>
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-soft-danger" 
                                                                    title="Delete"
                                                                    data-bs-toggle="tooltip"
                                                                    onclick="deletePO(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number']) ?>')">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="row mt-4">
                                    <div class="col-sm-6">
                                        <div class="text-muted">
                                            Page <?= $page ?> of <?= $total_pages ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>">
                                                    <i class="mdi mdi-chevron-left"></i>
                                                </a>
                                            </li>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($i) ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">
                                                    <i class="mdi mdi-chevron-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end table -->

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
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            width: '100%',
            placeholder: 'Select option'
        });
    });

    // Filter by status
    function filterByStatus(status) {
        const url = new URL(window.location.href);
        if (status) {
            url.searchParams.set('filter_status', status);
        } else {
            url.searchParams.delete('filter_status');
        }
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    // Update status function
    function updateStatus(poId, newStatus) {
        let title, text, icon;
        
        switch(newStatus) {
            case 'sent':
                title = 'Send Purchase Order?';
                text = 'This will mark the PO as sent to supplier. Continue?';
                icon = 'question';
                break;
            case 'confirmed':
                title = 'Confirm Purchase Order?';
                text = 'Mark this PO as confirmed by supplier. Continue?';
                icon = 'info';
                break;
            default:
                title = 'Update Status?';
                text = 'Are you sure you want to update this PO status?';
                icon = 'warning';
        }
        
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: '#556ee6',
            cancelButtonColor: '#f46a6a',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('action', 'update_status');
                currentUrl.searchParams.set('id', poId);
                currentUrl.searchParams.set('status', newStatus);
                currentUrl.searchParams.set('old_status', '<?= $po['status'] ?? '' ?>');
                window.location.href = currentUrl.toString();
            }
        });
    }

    // Delete PO function
    function deletePO(poId, poNumber) {
        Swal.fire({
            title: 'Delete Purchase Order?',
            html: `Are you sure you want to delete <strong>${poNumber}</strong>?`,
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('action', 'delete');
                currentUrl.searchParams.set('id', poId);
                window.location.href = currentUrl.toString();
            }
        });
    }

    // Export functionality
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        exportToCSV();
    });

    function exportToCSV() {
        const data = <?= json_encode($purchase_orders) ?>;
        if (data.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Data',
                text: 'No purchase orders to export',
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        // Create CSV content
        let csv = 'PO Number,Supplier,Order Date,Expected Delivery,Items,Total Amount,Status,Created By\n';
        
        data.forEach(po => {
            csv += `"${po.po_number}","${po.supplier_name || 'Unknown'}","${po.order_date}","${po.expected_delivery || ''}",${po.item_count},${po.total_amount},"${po.status}","${po.created_by_name || 'System'}"\n`;
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `purchase_orders_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
        
        Swal.fire({
            icon: 'success',
            title: 'Exported',
            text: 'Purchase orders exported successfully',
            timer: 1500,
            showConfirmButton: false
        });
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 500);
        });
    }, 5000);
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'purchase-orders.php?' . http_build_query($params);
}
?>

</body>
</html>