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

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $po_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get PO details for logging
        $poStmt = $pdo->prepare("SELECT po_number, supplier_id, total_amount, status FROM purchase_orders WHERE id = ?");
        $poStmt->execute([$po_id]);
        $po = $poStmt->fetch();
        
        if ($po) {
            // Allow deletion for any status - REMOVED THE RESTRICTION
            // Delete items first
            $deleteItems = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
            $deleteItems->execute([$po_id]);
            
            // Delete PO
            $deletePO = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?");
            $deletePO->execute([$po_id]);
            
            // Log activity if activity_logs table exists
            try {
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by, created_at)
                    VALUES (?, 5, ?, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'po_id' => $po_id,
                    'po_number' => $po['po_number'],
                    'supplier_id' => $po['supplier_id'],
                    'total_amount' => $po['total_amount']
                ]);
                
                $logStmt->execute([
                    $_SESSION['user_id'] ?? null,
                    "Purchase order deleted: " . $po['po_number'],
                    $activity_data,
                    $_SESSION['user_id'] ?? null
                ]);
            } catch (Exception $logError) {
                // Log table might not exist, continue with deletion
                error_log("Activity log error: " . $logError->getMessage());
            }
            
            $pdo->commit();
            $_SESSION['success_message'] = "Purchase order " . $po['po_number'] . " deleted successfully.";
        } else {
            throw new Exception("Purchase order not found.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error deleting purchase order: " . $e->getMessage();
        error_log("Delete error: " . $e->getMessage());
    }
    
    // Redirect without action and id parameters to prevent accidental re-deletion
    $redirect_params = $_GET;
    unset($redirect_params['action']);
    unset($redirect_params['id']);
    header("Location: purchase-orders.php?" . http_build_query($redirect_params));
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
    $query .= " AND LOWER(po.status) = :status";
    $count_query .= " AND LOWER(po.status) = :status";
    $params[':status'] = strtolower($filter_status);
}

// Apply search
if (!empty($search)) {
    $query .= " AND (po.po_number LIKE :search OR s.name LIKE :search OR s.company_name LIKE :search)";
    $count_query .= " AND (po.po_number LIKE :search OR s.name LIKE :search OR s.company_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Get total records for pagination
try {
    $countStmt = $pdo->prepare($count_query);
    $countStmt->execute($params);
    $total_records = $countStmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
} catch (Exception $e) {
    $total_records = 0;
    $total_pages = 1;
    error_log("Count query error: " . $e->getMessage());
}

// Get purchase orders for current page
try {
    $query .= " ORDER BY po.order_date DESC, po.created_at DESC LIMIT :offset, :limit";
    
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt->execute();
    $purchase_orders = $stmt->fetchAll();
} catch (Exception $e) {
    $purchase_orders = [];
    error_log("Query error: " . $e->getMessage());
}

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(AVG(total_amount), 0) as avg_amount,
        SUM(CASE WHEN LOWER(status) = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN LOWER(status) = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN LOWER(status) = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN LOWER(status) = 'partially_received' OR LOWER(status) = 'partial' THEN 1 ELSE 0 END) as partially_received_count,
        SUM(CASE WHEN LOWER(status) = 'completed' OR LOWER(status) = 'complete' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN LOWER(status) = 'cancelled' OR LOWER(status) = 'cancel' THEN 1 ELSE 0 END) as cancelled_count
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

try {
    $summaryStmt = $pdo->prepare($summary_query);
    $summaryStmt->execute($summary_params);
    $summary = $summaryStmt->fetch();
} catch (Exception $e) {
    $summary = [
        'total_orders' => 0,
        'total_amount' => 0,
        'avg_amount' => 0,
        'draft_count' => 0,
        'sent_count' => 0,
        'confirmed_count' => 0,
        'partially_received_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0
    ];
    error_log("Summary query error: " . $e->getMessage());
}

// Debug: Get distinct status values
try {
    $debugStmt = $pdo->query("SELECT DISTINCT LOWER(status) as status FROM purchase_orders");
    $statuses = $debugStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $statuses = [];
}

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
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-view { background-color: #e7f5ff; color: #0d6efd; border: 1px solid #b8daff; }
        .btn-edit { background-color: #fff3cd; color: #856404; border: 1px solid #ffe69c; }
        .btn-delete { background-color: #f8d7da; color: #dc3545; border: 1px solid #f5c2c7; }
        
        .btn-view:hover { background-color: #0d6efd; color: white; }
        .btn-edit:hover { background-color: #856404; color: white; }
        .btn-delete:hover { background-color: #dc3545; color: white; }
        
        /* Make sure delete button is always visible */
        .btn-delete {
            display: inline-flex !important;
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

                <!-- Debug Info (Hidden by default) -->
                <div class="debug-info" style="display: none; background: #f8f9fa; padding: 10px; margin-bottom: 10px; border-radius: 5px;">
                    <strong>Debug:</strong> Status values in DB: <?= json_encode($statuses) ?>
                </div>

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
                                            <option value="draft" <?= strtolower($filter_status) == 'draft' ? 'selected' : '' ?>>Draft</option>
                                            <option value="sent" <?= strtolower($filter_status) == 'sent' ? 'selected' : '' ?>>Sent</option>
                                            <option value="confirmed" <?= strtolower($filter_status) == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                            <option value="partially_received" <?= strtolower($filter_status) == 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                                            <option value="completed" <?= strtolower($filter_status) == 'completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="cancelled" <?= strtolower($filter_status) == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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
                                                <?php foreach ($purchase_orders as $po): 
                                                    $current_status = strtolower(trim($po['status']));
                                                    $editable_statuses = ['draft', 'sent'];
                                                ?>
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
                                                        switch($current_status) {
                                                            case 'draft': $status_class = 'status-draft'; break;
                                                            case 'sent': $status_class = 'status-sent'; break;
                                                            case 'confirmed': $status_class = 'status-confirmed'; break;
                                                            case 'partially_received':
                                                            case 'partial': $status_class = 'status-partially_received'; break;
                                                            case 'completed':
                                                            case 'complete': $status_class = 'status-completed'; break;
                                                            case 'cancelled':
                                                            case 'cancel': $status_class = 'status-cancelled'; break;
                                                            default: $status_class = 'status-draft';
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
                                                        <div class="action-buttons">
                                                            <!-- View Action - Always Visible -->
                                                            <a href="view-purchase-order.php?id=<?= $po['id'] ?>" 
                                                               class="btn-action btn-view" 
                                                               title="View Details"
                                                               data-bs-toggle="tooltip">
                                                                <i class="mdi mdi-eye"></i>
                                                            </a>
                                                            
                                                            <!-- Edit Action - Show for draft and sent status -->
                                                            <?php if (in_array($current_status, $editable_statuses)): ?>
                                                            <a href="edit-purchase-order.php?id=<?= $po['id'] ?>" 
                                                               class="btn-action btn-edit" 
                                                               title="Edit"
                                                               data-bs-toggle="tooltip">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </a>
                                                            <?php else: ?>
                                                            <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="Cannot edit - Status: <?= $current_status ?>">
                                                                <button type="button" class="btn-action btn-secondary" style="opacity: 0.5; cursor: not-allowed;" disabled>
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </button>
                                                            </span>
                                                            <?php endif; ?>
                                                            
                                                            <!-- Delete Action - ALWAYS VISIBLE for all statuses -->
                                                            <button type="button" 
                                                                    class="btn-action btn-delete" 
                                                                    title="Delete Purchase Order"
                                                                    data-bs-toggle="tooltip"
                                                                    onclick="deletePO(<?= $po['id'] ?>, '<?= htmlspecialchars(addslashes($po['po_number'])) ?>', '<?= $current_status ?>')">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
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
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

    // Initialize Select2
    $(document).ready(function() {
        $('.select2').select2({
            width: '100%',
            placeholder: 'Select option'
        });
        
        // Display debug info in console
        <?php if (!empty($statuses)): ?>
        console.log('Status values in DB:', <?= json_encode($statuses) ?>);
        <?php endif; ?>
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

    // Delete PO function - Now with warning about consequences
    function deletePO(poId, poNumber, status) {
        let warningMessage = `Are you sure you want to delete <strong>${poNumber}</strong>?`;
        
        // Add status-specific warnings
        if (status !== 'draft' && status !== 'cancelled' && status !== 'cancel') {
            warningMessage += `<br><br><span style="color: #dc3545;"> </span>`;
        }
        
        Swal.fire({
            title: 'Delete Purchase Order?',
            html: warningMessage,
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                // Get current URL parameters
                const currentUrl = new URL(window.location.href);
                
                // Remove any existing action/id parameters to avoid duplicates
                currentUrl.searchParams.delete('action');
                currentUrl.searchParams.delete('id');
                
                // Add new parameters
                currentUrl.searchParams.append('action', 'delete');
                currentUrl.searchParams.append('id', poId);
                
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
            const poNumber = (po.po_number || '').replace(/"/g, '""');
            const supplierName = (po.supplier_name || 'Unknown').replace(/"/g, '""');
            const orderDate = po.order_date || '';
            const expectedDelivery = po.expected_delivery || '';
            const itemCount = po.item_count || 0;
            const totalAmount = po.total_amount || 0;
            const status = (po.status || '').replace(/"/g, '""');
            const createdBy = (po.created_by_name || 'System').replace(/"/g, '""');
            
            csv += `"${poNumber}","${supplierName}","${orderDate}","${expectedDelivery}",${itemCount},${totalAmount},"${status}","${createdBy}"\n`;
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `purchase_orders_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
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
    // Remove action and id if they exist in params (clean URLs)
    unset($params['action']);
    unset($params['id']);
    return 'purchase-orders.php?' . http_build_query($params);
}
?>

</body>
</html>