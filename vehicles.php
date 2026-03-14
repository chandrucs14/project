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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$ownership = isset($_GET['ownership']) ? trim($_GET['ownership']) : 'all';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle add vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {
    $vehicle_number = strtoupper(trim($_POST['vehicle_number'] ?? ''));
    $owner_name = trim($_POST['owner_name'] ?? '');
    $owner_phone = trim($_POST['owner_phone'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $capacity = !empty($_POST['capacity']) ? floatval($_POST['capacity']) : null;
    $capacity_unit = trim($_POST['capacity_unit'] ?? '');
    $insurance_expiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $fitness_expiry = !empty($_POST['fitness_expiry']) ? $_POST['fitness_expiry'] : null;
    $is_owned = isset($_POST['is_owned']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($vehicle_number)) {
        $error = "Vehicle number is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if vehicle number already exists
            $checkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ?");
            $checkStmt->execute([$vehicle_number]);
            if ($checkStmt->fetch()) {
                throw new Exception("Vehicle number $vehicle_number already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (
                    vehicle_number, owner_name, owner_phone, vehicle_type, 
                    capacity, capacity_unit, insurance_expiry, fitness_expiry,
                    is_owned, is_active, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $vehicle_number, $owner_name ?: null, $owner_phone ?: null, $vehicle_type ?: null,
                $capacity, $capacity_unit ?: null, $insurance_expiry, $fitness_expiry,
                $is_owned, $is_active, $_SESSION['user_id']
            ]);
            
            if ($result) {
                $vehicle_id = $pdo->lastInsertId();
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'vehicle_id' => $vehicle_id,
                    'vehicle_number' => $vehicle_number
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "New vehicle added: $vehicle_number",
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Vehicle added successfully.";
                header("Location: vehicles.php");
                exit();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Handle edit vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_vehicle'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $vehicle_number = strtoupper(trim($_POST['vehicle_number'] ?? ''));
    $owner_name = trim($_POST['owner_name'] ?? '');
    $owner_phone = trim($_POST['owner_phone'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $capacity = !empty($_POST['capacity']) ? floatval($_POST['capacity']) : null;
    $capacity_unit = trim($_POST['capacity_unit'] ?? '');
    $insurance_expiry = !empty($_POST['insurance_expiry']) ? $_POST['insurance_expiry'] : null;
    $fitness_expiry = !empty($_POST['fitness_expiry']) ? $_POST['fitness_expiry'] : null;
    $is_owned = isset($_POST['is_owned']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($vehicle_number)) {
        $error = "Vehicle number is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if vehicle number already exists for another vehicle
            $checkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? AND id != ?");
            $checkStmt->execute([$vehicle_number, $vehicle_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("Vehicle number $vehicle_number already exists.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE vehicles SET 
                    vehicle_number = ?, owner_name = ?, owner_phone = ?, vehicle_type = ?,
                    capacity = ?, capacity_unit = ?, insurance_expiry = ?, fitness_expiry = ?,
                    is_owned = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $vehicle_number, $owner_name ?: null, $owner_phone ?: null, $vehicle_type ?: null,
                $capacity, $capacity_unit ?: null, $insurance_expiry, $fitness_expiry,
                $is_owned, $is_active, $_SESSION['user_id'], $vehicle_id
            ]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'vehicle_id' => $vehicle_id,
                    'vehicle_number' => $vehicle_number
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "Vehicle updated: $vehicle_number",
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Vehicle updated successfully.";
                header("Location: vehicles.php");
                exit();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $vehicle_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if vehicle is used in any invoices
        $checkInvoiceStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_vehicles WHERE vehicle_id = ?");
        $checkInvoiceStmt->execute([$vehicle_id]);
        $invoiceCount = $checkInvoiceStmt->fetchColumn();
        
        // Check if vehicle is used in any expenses
        $checkExpenseStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE vehicle_id = ?");
        $checkExpenseStmt->execute([$vehicle_id]);
        $expenseCount = $checkExpenseStmt->fetchColumn();
        
        if ($invoiceCount > 0 || $expenseCount > 0) {
            throw new Exception("Cannot delete vehicle because it is used in invoices or expenses.");
        }
        
        // Get vehicle details for logging
        $getStmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
        $getStmt->execute([$vehicle_id]);
        $vehicle = $getStmt->fetch();
        
        $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ?");
        $result = $stmt->execute([$vehicle_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'vehicle_id' => $vehicle_id,
                'vehicle_number' => $vehicle['vehicle_number']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Vehicle deleted: " . $vehicle['vehicle_number'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Vehicle deleted successfully.";
        }
        
        header("Location: vehicles.php?" . http_build_query(['search' => $search, 'ownership' => $ownership, 'status' => $status, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: vehicles.php?" . http_build_query(['search' => $search, 'ownership' => $ownership, 'status' => $status, 'page' => $page]));
        exit();
    }
}

// Build query
$query = "SELECT v.*, u.full_name as created_by_name, u2.full_name as updated_by_name 
          FROM vehicles v 
          LEFT JOIN users u ON v.created_by = u.id 
          LEFT JOIN users u2 ON v.updated_by = u2.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM vehicles WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (v.vehicle_number LIKE ? OR v.owner_name LIKE ? OR v.owner_phone LIKE ? OR v.vehicle_type LIKE ?)";
    $countQuery .= " AND (vehicle_number LIKE ? OR owner_name LIKE ? OR owner_phone LIKE ? OR vehicle_type LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($ownership !== 'all') {
    $is_owned = ($ownership === 'owned') ? 1 : 0;
    $query .= " AND v.is_owned = ?";
    $countQuery .= " AND is_owned = ?";
    $params[] = $is_owned;
}

if ($status !== 'all') {
    $is_active = ($status === 'active') ? 1 : 0;
    $query .= " AND v.is_active = ?";
    $countQuery .= " AND is_active = ?";
    $params[] = $is_active;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY v.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_owned = 1 THEN 1 ELSE 0 END) as owned,
        SUM(CASE WHEN is_owned = 0 THEN 1 ELSE 0 END) as hired,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN insurance_expiry < CURDATE() AND insurance_expiry IS NOT NULL THEN 1 ELSE 0 END) as insurance_expired,
        SUM(CASE WHEN fitness_expiry < CURDATE() AND fitness_expiry IS NOT NULL THEN 1 ELSE 0 END) as fitness_expired
    FROM vehicles
");
$stats = $statsStmt->fetch();

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
                            <h4 class="mb-0 font-size-18">Manage Vehicles</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Vehicles</li>
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
                                <h3 class="text-info mt-2"><?= number_format($stats['total'] ?? 0) ?></h3>
                                Total Vehicles
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['owned'] ?? 0) ?></h3>
                                Owned
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['hired'] ?? 0) ?></h3>
                                Hired
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2"><?= number_format($stats['active'] ?? 0) ?></h3>
                                Active
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expiry Alerts -->
                <?php if (($stats['insurance_expired'] ?? 0) > 0 || ($stats['fitness_expired'] ?? 0) > 0): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <i class="mdi mdi-alert me-2"></i>
                            <strong>Expiry Alerts:</strong>
                            <?php if (($stats['insurance_expired'] ?? 0) > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $stats['insurance_expired'] ?> Insurance Expired</span>
                            <?php endif; ?>
                            <?php if (($stats['fitness_expired'] ?? 0) > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $stats['fitness_expired'] ?> Fitness Expired</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Search</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   name="search" 
                                                                   placeholder="Search by number, owner, phone, type..." 
                                                                   value="<?= htmlspecialchars($search) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Ownership</label>
                                                        <select name="ownership" class="form-control">
                                                            <option value="all" <?= $ownership === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="owned" <?= $ownership === 'owned' ? 'selected' : '' ?>>Owned</option>
                                                            <option value="hired" <?= $ownership === 'hired' ? 'selected' : '' ?>>Hired</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-100">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="vehicles.php?reset=1" class="btn btn-secondary me-2">
                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                            </a>
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                                                <i class="mdi mdi-plus me-1"></i> Add Vehicle
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Vehicles Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Vehicle List</h4>
                                
                                <?php if (empty($vehicles)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-truck" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No vehicles found</h5>
                                        <p class="text-muted">Click the button below to add your first vehicle</p>
                                        <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                                            <i class="mdi mdi-plus me-1"></i> Add Vehicle
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Vehicle</th>
                                                    <th>Owner</th>
                                                    <th>Type</th>
                                                    <th>Capacity</th>
                                                    <th>Insurance Expiry</th>
                                                    <th>Fitness Expiry</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($vehicles as $vehicle): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                                                            <?php if (!$vehicle['is_owned']): ?>
                                                                <span class="badge bg-soft-info text-info ms-1">Hired</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($vehicle['owner_name']): ?>
                                                                <?= htmlspecialchars($vehicle['owner_name']) ?>
                                                                <br><small class="text-muted"><?= htmlspecialchars($vehicle['owner_phone'] ?? '') ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($vehicle['vehicle_type'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <?php if ($vehicle['capacity']): ?>
                                                                <?= $vehicle['capacity'] ?> <?= htmlspecialchars($vehicle['capacity_unit'] ?? '') ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($vehicle['insurance_expiry']): ?>
                                                                <?= date('d-m-Y', strtotime($vehicle['insurance_expiry'])) ?>
                                                                <?php if (strtotime($vehicle['insurance_expiry']) < time()): ?>
                                                                    <span class="badge bg-soft-danger text-danger">Expired</span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($vehicle['fitness_expiry']): ?>
                                                                <?= date('d-m-Y', strtotime($vehicle['fitness_expiry'])) ?>
                                                                <?php if (strtotime($vehicle['fitness_expiry']) < time()): ?>
                                                                    <span class="badge bg-soft-danger text-danger">Expired</span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($vehicle['is_active']): ?>
                                                                <span class="badge bg-soft-success text-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-danger text-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-soft-primary" 
                                                                        onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle)) ?>)"
                                                                        data-bs-toggle="tooltip" 
                                                                        title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </button>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $vehicle['id'] ?>, '<?= htmlspecialchars(addslashes($vehicle['vehicle_number'])) ?>')"
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&ownership=<?= $ownership ?>&status=<?= $status ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&ownership=<?= $ownership ?>&status=<?= $status ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&ownership=<?= $ownership ?>&status=<?= $status ?>">
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

<!-- Add Vehicle Modal -->
<div class="modal fade" id="addVehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-truck-plus me-2"></i>Add Vehicle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Vehicle Number <span class="text-danger">*</span></label>
                                <input type="text" name="vehicle_number" class="form-control" placeholder="e.g., TN01AB1234" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Vehicle Type</label>
                                <input type="text" name="vehicle_type" class="form-control" placeholder="e.g., Truck, Lorry, Van">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Owner Name</label>
                                <input type="text" name="owner_name" class="form-control" placeholder="Owner name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Owner Phone</label>
                                <input type="tel" name="owner_phone" class="form-control" placeholder="10 digit number" maxlength="10">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" class="form-control" placeholder="e.g., 10" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Capacity Unit</label>
                                <select name="capacity_unit" class="form-control">
                                    <option value="">Select unit</option>
                                    <option value="TON">TON</option>
                                    <option value="KG">KG</option>
                                    <option value="CUBIC">CUBIC</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Ownership</label>
                                <div class="form-check form-switch mt-2">
                                    <input type="checkbox" class="form-check-input" name="is_owned" id="add_is_owned" checked>
                                    <label class="form-check-label" for="add_is_owned">Owned</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Insurance Expiry</label>
                                <input type="date" name="insurance_expiry" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Fitness Expiry</label>
                                <input type="date" name="fitness_expiry" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="is_active" id="add_is_active" checked>
                                    <label class="form-check-label" for="add_is_active">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_vehicle" class="btn btn-success">Save Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Vehicle Modal -->
<div class="modal fade" id="editVehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-truck-edit me-2"></i>Edit Vehicle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Vehicle Number <span class="text-danger">*</span></label>
                                <input type="text" name="vehicle_number" id="edit_vehicle_number" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Vehicle Type</label>
                                <input type="text" name="vehicle_type" id="edit_vehicle_type" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Owner Name</label>
                                <input type="text" name="owner_name" id="edit_owner_name" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Owner Phone</label>
                                <input type="tel" name="owner_phone" id="edit_owner_phone" class="form-control" maxlength="10">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" id="edit_capacity" class="form-control" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Capacity Unit</label>
                                <select name="capacity_unit" id="edit_capacity_unit" class="form-control">
                                    <option value="">Select unit</option>
                                    <option value="TON">TON</option>
                                    <option value="KG">KG</option>
                                    <option value="CUBIC">CUBIC</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Ownership</label>
                                <div class="form-check form-switch mt-2">
                                    <input type="checkbox" class="form-check-input" name="is_owned" id="edit_is_owned">
                                    <label class="form-check-label" for="edit_is_owned">Owned</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Insurance Expiry</label>
                                <input type="date" name="insurance_expiry" id="edit_insurance_expiry" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Fitness Expiry</label>
                                <input type="date" name="fitness_expiry" id="edit_fitness_expiry" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="is_active" id="edit_is_active">
                                    <label class="form-check-label" for="edit_is_active">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_vehicle" class="btn btn-primary">Update Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- SweetAlert2 -->
<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto-hide alerts
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 500);
        });
    }, 5000);

    // Edit vehicle function
    function editVehicle(vehicle) {
        document.getElementById('edit_vehicle_id').value = vehicle.id;
        document.getElementById('edit_vehicle_number').value = vehicle.vehicle_number;
        document.getElementById('edit_vehicle_type').value = vehicle.vehicle_type || '';
        document.getElementById('edit_owner_name').value = vehicle.owner_name || '';
        document.getElementById('edit_owner_phone').value = vehicle.owner_phone || '';
        document.getElementById('edit_capacity').value = vehicle.capacity || '';
        document.getElementById('edit_capacity_unit').value = vehicle.capacity_unit || '';
        document.getElementById('edit_insurance_expiry').value = vehicle.insurance_expiry || '';
        document.getElementById('edit_fitness_expiry').value = vehicle.fitness_expiry || '';
        document.getElementById('edit_is_owned').checked = vehicle.is_owned == 1;
        document.getElementById('edit_is_active').checked = vehicle.is_active == 1;
        $('#editVehicleModal').modal('show');
    }

    // Confirm delete
    function confirmDelete(id, number) {
        Swal.fire({
            title: 'Delete Vehicle?',
            html: `Are you sure you want to delete vehicle <strong>${number}</strong>?<br><br>
                   <span class="text-danger">This action cannot be undone!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `vehicles.php?delete=1&id=${id}`;
            }
        });
    }

    // Auto-submit on filter change
    document.querySelector('select[name="ownership"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });

    // Search debounce
    let searchTimeout;
    document.querySelector('input[name="search"]')?.addEventListener('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });

    // Phone number validation
    document.querySelector('input[name="owner_phone"]')?.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
    });
</script>

</body>
</html>