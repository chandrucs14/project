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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Records per page
$offset = ($page - 1) * $limit;

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $supplier_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get supplier data for logging
        $getStmt = $pdo->prepare("SELECT name, supplier_code FROM suppliers WHERE id = ?");
        $getStmt->execute([$supplier_id]);
        $supplier = $getStmt->fetch();
        
        if (!$supplier) {
            throw new Exception("Supplier not found.");
        }
        
        // Check if supplier has any purchase orders
        $checkPO = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
        $checkPO->execute([$supplier_id]);
        $poCount = $checkPO->fetchColumn();
        
        // Check if supplier has any expenses
        $checkExpenses = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE supplier_id = ?");
        $checkExpenses->execute([$supplier_id]);
        $expenseCount = $checkExpenses->fetchColumn();
        
        // Check if supplier has any outstanding records
        $checkOutstanding = $pdo->prepare("SELECT COUNT(*) FROM supplier_outstanding WHERE supplier_id = ?");
        $checkOutstanding->execute([$supplier_id]);
        $outstandingCount = $checkOutstanding->fetchColumn();
        
        if ($poCount > 0 || $expenseCount > 0 || $outstandingCount > 0) {
            // Cannot delete - has transactions
            $pdo->rollBack();
            $_SESSION['error_message'] = "Cannot delete supplier because they have associated transactions (purchase orders, expenses, or outstanding records).";
            header("Location: manage-suppliers.php?" . http_build_query(['search' => $search, 'status' => $status, 'page' => $page]));
            exit();
        } else {
            // Hard delete - no transactions
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $result = $stmt->execute([$supplier_id]);
            
            if ($result && $stmt->rowCount() > 0) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 5, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'supplier_id' => $supplier_id,
                    'supplier_code' => $supplier['supplier_code'],
                    'supplier_name' => $supplier['name']
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    "Supplier deleted: " . $supplier['name'],
                    $activity_data
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = "Supplier deleted successfully.";
            } else {
                $pdo->rollBack();
                $_SESSION['error_message'] = "Failed to delete supplier. Please try again.";
            }
        }
        
        header("Location: manage-suppliers.php?" . http_build_query(['search' => $search, 'status' => $status, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to delete supplier: " . $e->getMessage();
        error_log("Supplier delete error: " . $e->getMessage());
        header("Location: manage-suppliers.php?" . http_build_query(['search' => $search, 'status' => $status, 'page' => $page]));
        exit();
    }
}

// Build the query with filters
$query = "SELECT * FROM suppliers WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM suppliers WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ? OR supplier_code LIKE ?)";
    $countQuery .= " AND (name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ? OR supplier_code LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $is_active = ($status === 'active') ? 1 : 0;
    $query .= " AND is_active = ?";
    $countQuery .= " AND is_active = ?";
    $params[] = $is_active;
}

// Get total records for pagination
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get suppliers for current page
$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

// Calculate statistics
$statsQuery = "SELECT 
                COUNT(*) as total_suppliers,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_suppliers,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_suppliers,
                COALESCE(SUM(outstanding_balance), 0) as total_outstanding,
                COALESCE(AVG(outstanding_balance), 0) as avg_outstanding
               FROM suppliers";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get recent suppliers for widget
$recentStmt = $pdo->query("SELECT id, name, supplier_code, company_name, created_at, outstanding_balance FROM suppliers ORDER BY created_at DESC LIMIT 5");
$recentSuppliers = $recentStmt->fetchAll();

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
                            <h4 class="mb-0 font-size-18">Manage Suppliers</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Suppliers</li>
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
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total_suppliers'] ?? 0) ?></h3>
                                Total Suppliers
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2"><?= number_format($stats['active_suppliers'] ?? 0) ?></h3>
                                Active Suppliers
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2"><?= number_format($stats['inactive_suppliers'] ?? 0) ?></h3>
                                Inactive Suppliers
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">₹<?= number_format($stats['total_outstanding'] ?? 0, 2) ?></h3>
                                Total Outstanding
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
                                    <div class="col-md-8">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Search</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   name="search" 
                                                                   placeholder="Search by name, company, email, phone or code..." 
                                                                   value="<?= htmlspecialchars($search) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                                                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
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
                                                            <a href="manage-suppliers.php" class="btn btn-secondary w-50">
                                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <a href="add-supplier.php" class="btn btn-success">
                                                <i class="mdi mdi-truck-plus me-1"></i> Add New Supplier
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Suppliers Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Supplier List</h4>
                                
                                <?php if (empty($suppliers)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-truck-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No suppliers found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                        <a href="add-supplier.php" class="btn btn-primary mt-2">
                                            <i class="mdi mdi-truck-plus me-1"></i> Add New Supplier
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Contact</th>
                                                    <th>Company</th>
                                                    <th>Outstanding</th>
                                                    <th>Payment Terms</th>
                                                    <th>Status</th>
                                                    <th>Joined</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="avatar-sm rounded-circle bg-soft-primary text-primary me-3 d-flex align-items-center justify-content-center">
                                                                    <?= strtoupper(substr($supplier['name'], 0, 2)) ?>
                                                                </div>
                                                                <div>
                                                                    <h6 class="mb-1"><?= htmlspecialchars($supplier['name']) ?></h6>
                                                                    <small class="text-muted">Code: <?= htmlspecialchars($supplier['supplier_code']) ?></small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div><i class="mdi mdi-email-outline me-1 text-muted"></i> <?= htmlspecialchars($supplier['email'] ?? 'N/A') ?></div>
                                                            <div><i class="mdi mdi-phone me-1 text-muted"></i> <?= htmlspecialchars($supplier['phone']) ?></div>
                                                            <?php if ($supplier['alternate_phone']): ?>
                                                                <small class="text-muted">Alt: <?= htmlspecialchars($supplier['alternate_phone']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($supplier['company_name'] ?? 'N/A') ?>
                                                        </td>
                                                        <td>
                                                            <span class="<?= $supplier['outstanding_balance'] > 0 ? 'text-danger' : 'text-success' ?> font-weight-bold">
                                                                ₹<?= number_format($supplier['outstanding_balance'], 2) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($supplier['payment_terms']): ?>
                                                                <?= $supplier['payment_terms'] ?> days
                                                            <?php else: ?>
                                                                <span class="text-muted">Not set</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($supplier['is_active']): ?>
                                                                <span class="badge bg-soft-success text-success">
                                                                    <i class="mdi mdi-check-circle me-1"></i> Active
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-danger text-danger">
                                                                    <i class="mdi mdi-close-circle me-1"></i> Inactive
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= date('d-m-Y', strtotime($supplier['created_at'])) ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="view-supplier.php?id=<?= $supplier['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-primary" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="View Details">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </a>
                                                                <a href="edit-supplier.php?id=<?= $supplier['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-success" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </a>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $supplier['id'] ?>, '<?= htmlspecialchars(addslashes($supplier['name'])) ?>', '<?= htmlspecialchars(addslashes($search)) ?>', '<?= $status ?>', <?= $page ?>)"
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>">
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

                <!-- Recent Suppliers Widget -->
                <?php if (!empty($recentSuppliers)): ?>
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recently Added Suppliers</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Supplier</th>
                                                <th>Code</th>
                                                <th>Company</th>
                                                <th>Joined Date</th>
                                                <th>Outstanding</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentSuppliers as $recent): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($recent['name']) ?></td>
                                                <td><?= htmlspecialchars($recent['supplier_code']) ?></td>
                                                <td><?= htmlspecialchars($recent['company_name'] ?? 'N/A') ?></td>
                                                <td><?= date('d M Y', strtotime($recent['created_at'])) ?></td>
                                                <td>₹<?= number_format($recent['outstanding_balance'], 2) ?></td>
                                                <td>
                                                    <a href="view-supplier.php?id=<?= $recent['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                        <i class="mdi mdi-eye"></i>
                                                    </a>
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
                <!-- end recent suppliers -->

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
    
    // Auto-submit on status change
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Confirm delete action
    function confirmDelete(id, name, search, status, page) {
        Swal.fire({
            title: 'Delete Supplier?',
            html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>
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
                    text: 'Please wait while we delete the supplier',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                window.location.href = `manage-suppliers.php?delete=1&id=${id}&search=${encodeURIComponent(search)}&status=${status}&page=${page}`;
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + N for new supplier
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'add-supplier.php';
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