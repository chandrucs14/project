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
$claimed_status = isset($_GET['claimed_status']) ? trim($_GET['claimed_status']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-04-01'); // Financial year start (April)
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$financial_year = isset($_GET['financial_year']) ? trim($_GET['financial_year']) : date('Y') . '-' . (date('Y') + 1);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle claim GST input credit
if (isset($_GET['claim']) && isset($_GET['id'])) {
    $credit_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get credit details
        $getStmt = $pdo->prepare("SELECT * FROM gst_input_credit WHERE id = ?");
        $getStmt->execute([$credit_id]);
        $credit = $getStmt->fetch();
        
        if (!$credit) {
            throw new Exception("GST input credit record not found.");
        }
        
        if ($credit['is_claimed'] == 1) {
            throw new Exception("This GST input credit has already been claimed.");
        }
        
        // Update as claimed
        $updateStmt = $pdo->prepare("
            UPDATE gst_input_credit 
            SET is_claimed = 1, claimed_date = CURDATE(), updated_at = NOW(), updated_by = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$_SESSION['user_id'], $credit_id]);
        
        // Get supplier name for logging
        $suppStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $suppStmt->execute([$credit['supplier_id']]);
        $supplier = $suppStmt->fetch();
        
        // Log activity
        $activity_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
            VALUES (?, 4, ?, ?, NOW())
        ");
        
        $activity_data = json_encode([
            'credit_id' => $credit_id,
            'supplier_name' => $supplier['name'],
            'gst_amount' => $credit['gst_amount'],
            'invoice_id' => $credit['invoice_id'],
            'financial_year' => $credit['financial_year']
        ]);
        
        $activity_stmt->execute([
            $_SESSION['user_id'],
            "GST input credit of ₹{$credit['gst_amount']} claimed for supplier: " . $supplier['name'],
            $activity_data
        ]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "GST input credit claimed successfully.";
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to claim GST input credit: " . $e->getMessage();
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
    }
}

// Handle unclaim GST input credit
if (isset($_GET['unclaim']) && isset($_GET['id'])) {
    $credit_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get credit details
        $getStmt = $pdo->prepare("SELECT * FROM gst_input_credit WHERE id = ?");
        $getStmt->execute([$credit_id]);
        $credit = $getStmt->fetch();
        
        if (!$credit) {
            throw new Exception("GST input credit record not found.");
        }
        
        if ($credit['is_claimed'] == 0) {
            throw new Exception("This GST input credit is not claimed.");
        }
        
        // Update as unclaimed
        $updateStmt = $pdo->prepare("
            UPDATE gst_input_credit 
            SET is_claimed = 0, claimed_date = NULL, updated_at = NOW(), updated_by = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$_SESSION['user_id'], $credit_id]);
        
        // Get supplier name for logging
        $suppStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $suppStmt->execute([$credit['supplier_id']]);
        $supplier = $suppStmt->fetch();
        
        // Log activity
        $activity_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
            VALUES (?, 4, ?, ?, NOW())
        ");
        
        $activity_data = json_encode([
            'credit_id' => $credit_id,
            'supplier_name' => $supplier['name'],
            'gst_amount' => $credit['gst_amount'],
            'invoice_id' => $credit['invoice_id'],
            'financial_year' => $credit['financial_year']
        ]);
        
        $activity_stmt->execute([
            $_SESSION['user_id'],
            "GST input credit of ₹{$credit['gst_amount']} unclaimed for supplier: " . $supplier['name'],
            $activity_data
        ]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "GST input credit unclaimed successfully.";
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to unclaim GST input credit: " . $e->getMessage();
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
    }
}

// Handle delete GST input credit record
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $credit_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get credit details for logging
        $getStmt = $pdo->prepare("SELECT * FROM gst_input_credit WHERE id = ?");
        $getStmt->execute([$credit_id]);
        $credit = $getStmt->fetch();
        
        if (!$credit) {
            throw new Exception("GST input credit record not found.");
        }
        
        // Get supplier name
        $suppStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $suppStmt->execute([$credit['supplier_id']]);
        $supplier = $suppStmt->fetch();
        
        // Delete the record
        $stmt = $pdo->prepare("DELETE FROM gst_input_credit WHERE id = ?");
        $result = $stmt->execute([$credit_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'credit_id' => $credit_id,
                'supplier_name' => $supplier['name'],
                'gst_amount' => $credit['gst_amount'],
                'invoice_id' => $credit['invoice_id'],
                'financial_year' => $credit['financial_year']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "GST input credit record deleted for supplier: " . $supplier['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "GST input credit record deleted successfully.";
        }
        
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to delete GST input credit record: " . $e->getMessage();
        header("Location: gst-input-credit.php?" . http_build_query(['supplier_id' => $supplier_id, 'search' => $search, 'claimed_status' => $claimed_status, 'from_date' => $from_date, 'to_date' => $to_date, 'financial_year' => $financial_year, 'page' => $page]));
        exit();
    }
}

// Build the query
$query = "
    SELECT 
        g.*,
        s.name as supplier_name,
        s.supplier_code,
        s.gst_number as supplier_gst,
        s.pan_number as supplier_pan,
        i.invoice_number,
        i.invoice_date,
        i.total_amount as invoice_total,
        po.po_number,
        po.order_date as po_date,
        u.full_name as created_by_name,
        u2.full_name as updated_by_name
    FROM gst_input_credit g
    JOIN suppliers s ON g.supplier_id = s.id
    LEFT JOIN invoices i ON g.invoice_id = i.id
    LEFT JOIN purchase_orders po ON g.purchase_order_id = po.id
    LEFT JOIN users u ON g.created_by = u.id
    LEFT JOIN users u2 ON g.updated_by = u2.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM gst_input_credit g
    JOIN suppliers s ON g.supplier_id = s.id
    WHERE 1=1
";

$params = [];

if ($supplier_id > 0) {
    $query .= " AND g.supplier_id = ?";
    $countQuery .= " AND g.supplier_id = ?";
    $params[] = $supplier_id;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.gst_number LIKE ? OR i.invoice_number LIKE ? OR po.po_number LIKE ?)";
    $countQuery .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.gst_number LIKE ? OR i.invoice_number LIKE ? OR po.po_number LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($claimed_status !== 'all') {
    $is_claimed = ($claimed_status === 'claimed') ? 1 : 0;
    $query .= " AND g.is_claimed = ?";
    $countQuery .= " AND g.is_claimed = ?";
    $params[] = $is_claimed;
}

if (!empty($financial_year)) {
    $query .= " AND g.financial_year = ?";
    $countQuery .= " AND g.financial_year = ?";
    $params[] = $financial_year;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(g.input_credit_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(g.input_credit_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY g.input_credit_date DESC, g.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$gst_credits = $stmt->fetchAll();

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN is_claimed = 1 THEN 1 ELSE 0 END) as claimed_count,
        SUM(CASE WHEN is_claimed = 0 THEN 1 ELSE 0 END) as unclaimed_count,
        COALESCE(SUM(gst_amount), 0) as total_gst,
        COALESCE(SUM(CASE WHEN is_claimed = 1 THEN gst_amount ELSE 0 END), 0) as claimed_gst,
        COALESCE(SUM(CASE WHEN is_claimed = 0 THEN gst_amount ELSE 0 END), 0) as unclaimed_gst
    FROM gst_input_credit
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get financial year wise summary
$fyQuery = "
    SELECT 
        financial_year,
        COUNT(*) as record_count,
        SUM(gst_amount) as total_gst,
        SUM(CASE WHEN is_claimed = 1 THEN gst_amount ELSE 0 END) as claimed_gst,
        SUM(CASE WHEN is_claimed = 0 THEN gst_amount ELSE 0 END) as unclaimed_gst
    FROM gst_input_credit
    GROUP BY financial_year
    ORDER BY financial_year DESC
    LIMIT 5
";
$fyStmt = $pdo->query($fyQuery);
$fySummary = $fyStmt->fetchAll();

// Get all suppliers for dropdown
$suppStmt = $pdo->query("SELECT id, name, supplier_code, gst_number FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppStmt->fetchAll();

// Get all financial years for filter
$fyFilterStmt = $pdo->query("SELECT DISTINCT financial_year FROM gst_input_credit ORDER BY financial_year DESC");
$fyFilter = $fyFilterStmt->fetchAll();

// Generate financial year options
$current_year = date('Y');
$fy_options = [];
for ($i = -2; $i <= 2; $i++) {
    $year = $current_year + $i;
    $fy_options[] = $year . '-' . ($year + 1);
}

// Get selected supplier details if supplier_id is provided
$selectedSupplier = null;
if ($supplier_id > 0) {
    $selSuppStmt = $pdo->prepare("SELECT name, supplier_code, gst_number, pan_number FROM suppliers WHERE id = ?");
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
                            <h4 class="mb-0 font-size-18">GST Input Credit Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="gst-details.php">GST</a></li>
                                    <li class="breadcrumb-item active">Input Credit</li>
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
                                <h3 class="text-info mt-2"><?= number_format($stats['total_records'] ?? 0) ?></h3>
                                Total Records
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['claimed_count'] ?? 0) ?></h3>
                                Claimed
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['unclaimed_count'] ?? 0) ?></h3>
                                Unclaimed
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">₹<?= number_format($stats['total_gst'] ?? 0, 2) ?></h3>
                                Total GST
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats Row -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2">₹<?= number_format($stats['claimed_gst'] ?? 0, 2) ?></h3>
                                Claimed GST
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2">₹<?= number_format($stats['unclaimed_gst'] ?? 0, 2) ?></h3>
                                Unclaimed GST
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= $stats['total_gst'] > 0 ? number_format(($stats['claimed_gst'] / $stats['total_gst']) * 100, 1) : 0 ?>%</h3>
                                Claim Rate
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
                                    <div class="col-md-10">
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
                                                                    <?php if ($supp['gst_number']): ?>
                                                                        (<?= htmlspecialchars($supp['gst_number']) ?>)
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
                                                        <label class="form-label">Financial Year</label>
                                                        <select name="financial_year" class="form-control">
                                                            <option value="">All Years</option>
                                                            <?php foreach ($fy_options as $fy): ?>
                                                                <option value="<?= $fy ?>" <?= $financial_year == $fy ? 'selected' : '' ?>>
                                                                    <?= $fy ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="claimed_status" class="form-control">
                                                            <option value="all" <?= $claimed_status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="claimed" <?= $claimed_status === 'claimed' ? 'selected' : '' ?>>Claimed</option>
                                                            <option value="unclaimed" <?= $claimed_status === 'unclaimed' ? 'selected' : '' ?>>Unclaimed</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-md-9">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                        <input type="text" 
                                                               class="form-control" 
                                                               name="search" 
                                                               placeholder="Search by supplier name, code, GST, invoice/PO number..." 
                                                               value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" class="btn btn-primary w-50">
                                                            <i class="mdi mdi-filter me-1"></i> Filter
                                                        </button>
                                                        <a href="gst-input-credit.php" class="btn btn-secondary w-50">
                                                            <i class="mdi mdi-refresh me-1"></i> Reset
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="add-gst-credit.php" class="btn btn-success">
                                                <i class="mdi mdi-plus me-1"></i> Add Credit
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
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">GST/PAN</h6>
                                        <p class="mb-1">GST: <?= htmlspecialchars($selectedSupplier['gst_number'] ?? 'N/A') ?></p>
                                        <p class="mb-0">PAN: <?= htmlspecialchars($selectedSupplier['pan_number'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- GST Input Credit Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">GST Input Credit Records</h4>
                                
                                <?php if (empty($gst_credits)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-percent" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No GST input credit records found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="add-gst-credit.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-plus me-1"></i> Add GST Credit
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Reference</th>
                                                    <th>Financial Year</th>
                                                    <th>GST Amount</th>
                                                    <th>Status</th>
                                                    <th>Claimed Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($gst_credits as $credit): ?>
                                                    <tr>
                                                        <td><?= date('d-m-Y', strtotime($credit['input_credit_date'])) ?></td>
                                                        <td>
                                                            <a href="view-supplier.php?id=<?= $credit['supplier_id'] ?>" class="text-primary">
                                                                <?= htmlspecialchars($credit['supplier_name']) ?>
                                                            </a>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($credit['supplier_code']) ?></small>
                                                            <?php if ($credit['supplier_gst']): ?>
                                                                <br><small class="text-muted">GST: <?= htmlspecialchars($credit['supplier_gst']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($credit['invoice_id']): ?>
                                                                <a href="view-invoice.php?id=<?= $credit['invoice_id'] ?>" class="text-info">
                                                                    Invoice: <?= htmlspecialchars($credit['invoice_number']) ?>
                                                                </a>
                                                                <br>
                                                                <small class="text-muted">Date: <?= date('d-m-Y', strtotime($credit['invoice_date'])) ?></small>
                                                            <?php elseif ($credit['purchase_order_id']): ?>
                                                                <a href="view-purchase-order.php?id=<?= $credit['purchase_order_id'] ?>" class="text-info">
                                                                    PO: <?= htmlspecialchars($credit['po_number']) ?>
                                                                </a>
                                                                <br>
                                                                <small class="text-muted">Date: <?= date('d-m-Y', strtotime($credit['po_date'])) ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">Manual Entry</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info"><?= htmlspecialchars($credit['financial_year']) ?></span>
                                                        </td>
                                                        <td>
                                                            <strong class="text-primary">₹<?= number_format($credit['gst_amount'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php if ($credit['is_claimed']): ?>
                                                                <span class="badge bg-soft-success text-success">
                                                                    <i class="mdi mdi-check-circle me-1"></i> Claimed
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-warning text-warning">
                                                                    <i class="mdi mdi-clock-outline me-1"></i> Unclaimed
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= $credit['claimed_date'] ? date('d-m-Y', strtotime($credit['claimed_date'])) : '-' ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <?php if (!$credit['is_claimed']): ?>
                                                                    <a href="?claim=1&id=<?= $credit['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&claimed_status=<?= $claimed_status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&financial_year=<?= $financial_year ?>&page=<?= $page ?>" 
                                                                       class="btn btn-sm btn-soft-success" 
                                                                       data-bs-toggle="tooltip" 
                                                                       title="Claim Credit"
                                                                       onclick="return confirmClaim('claim', <?= $credit['gst_amount'] ?>)">
                                                                        <i class="mdi mdi-check"></i>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <a href="?unclaim=1&id=<?= $credit['id'] ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&claimed_status=<?= $claimed_status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&financial_year=<?= $financial_year ?>&page=<?= $page ?>" 
                                                                       class="btn btn-sm btn-soft-warning" 
                                                                       data-bs-toggle="tooltip" 
                                                                       title="Unclaim Credit"
                                                                       onclick="return confirmClaim('unclaim', <?= $credit['gst_amount'] ?>)">
                                                                        <i class="mdi mdi-undo"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <a href="edit-gst-credit.php?id=<?= $credit['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-primary" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </a>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $credit['id'] ?>, '<?= htmlspecialchars(addslashes($credit['supplier_name'])) ?>', <?= $credit['gst_amount'] ?>)"
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&claimed_status=<?= $claimed_status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&financial_year=<?= $financial_year ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&claimed_status=<?= $claimed_status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&financial_year=<?= $financial_year ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&supplier_id=<?= $supplier_id ?>&search=<?= urlencode($search) ?>&claimed_status=<?= $claimed_status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&financial_year=<?= $financial_year ?>">
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

                <!-- Financial Year Summary -->
                <?php if (!empty($fySummary)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Financial Year Summary</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Financial Year</th>
                                                <th>Records</th>
                                                <th>Total GST</th>
                                                <th>Claimed GST</th>
                                                <th>Unclaimed GST</th>
                                                <th>Claim Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fySummary as $fy): ?>
                                                <?php 
                                                $claimRate = $fy['total_gst'] > 0 ? ($fy['claimed_gst'] / $fy['total_gst']) * 100 : 0;
                                                ?>
                                                <tr>
                                                    <td><span class="badge bg-soft-info text-info"><?= htmlspecialchars($fy['financial_year']) ?></span></td>
                                                    <td><?= $fy['record_count'] ?></td>
                                                    <td>₹<?= number_format($fy['total_gst'], 2) ?></td>
                                                    <td class="text-success">₹<?= number_format($fy['claimed_gst'], 2) ?></td>
                                                    <td class="text-warning">₹<?= number_format($fy['unclaimed_gst'], 2) ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <span class="me-2"><?= number_format($claimRate, 1) ?>%</span>
                                                            <div class="progress flex-grow-1" style="height: 5px;">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?= $claimRate ?>%;" 
                                                                     aria-valuenow="<?= $claimRate ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- end financial year summary -->

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
    
    document.querySelector('select[name="claimed_status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.querySelector('select[name="financial_year"]')?.addEventListener('change', function() {
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
    
    // Confirm claim/unclaim action
    function confirmClaim(action, amount) {
        let title, text, icon;
        
        if (action === 'claim') {
            title = 'Claim GST Credit?';
            text = `Are you sure you want to claim GST input credit of <strong>₹${amount.toFixed(2)}</strong>?`;
            icon = 'question';
        } else {
            title = 'Unclaim GST Credit?';
            text = `Are you sure you want to unclaim GST input credit of <strong>₹${amount.toFixed(2)}</strong>?`;
            icon = 'warning';
        }
        
        Swal.fire({
            title: title,
            html: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: action === 'claim' ? '#34c38f' : '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                return true;
            }
            return false;
        });
        
        // Return false to prevent default link behavior - the onclick will handle it
        return false;
    }
    
    // Confirm delete action
    function confirmDelete(id, supplierName, amount) {
        Swal.fire({
            title: 'Delete GST Credit?',
            html: `Are you sure you want to delete GST input credit record for <strong>${supplierName}</strong> of <strong>₹${amount.toFixed(2)}</strong>?<br><br>
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
                    text: 'Please wait while we delete the record',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Get current filter parameters
                const urlParams = new URLSearchParams(window.location.search);
                const supplierId = urlParams.get('supplier_id') || '0';
                const search = urlParams.get('search') || '';
                const claimedStatus = urlParams.get('claimed_status') || 'all';
                const fromDate = urlParams.get('from_date') || '';
                const toDate = urlParams.get('to_date') || '';
                const financialYear = urlParams.get('financial_year') || '';
                const page = urlParams.get('page') || '1';
                
                window.location.href = `gst-input-credit.php?delete=1&id=${id}&supplier_id=${supplierId}&search=${encodeURIComponent(search)}&claimed_status=${claimedStatus}&from_date=${fromDate}&to_date=${toDate}&financial_year=${financialYear}&page=${page}`;
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + A for add new credit
        if (e.altKey && e.key === 'a') {
            e.preventDefault();
            window.location.href = 'add-gst-credit.php';
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

    // Override default link behavior for claim/unclaim
    document.querySelectorAll('a[onclick*="confirmClaim"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const href = this.href;
            const amount = parseFloat(this.href.match(/confirmClaim\('(claim|unclaim)', ([\d.]+)\)/)?.[2] || 0);
            const action = this.href.match(/confirmClaim\('(claim|unclaim)'/)?.[1] || 'claim';
            
            confirmClaim(action, amount).then(confirmed => {
                if (confirmed) {
                    window.location.href = href;
                }
            });
        });
    });
</script>

</body>
</html>