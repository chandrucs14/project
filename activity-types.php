<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';



// Check if user is admin
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?error=unauthorized");
    exit();
}

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    
    try {
        // Check if activity type is in use
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE activity_type_id = ?");
        $checkStmt->execute([$delete_id]);
        $usage_count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($usage_count > 0) {
            $error_message = "Cannot delete activity type because it is used in $usage_count activity log(s).";
        } else {
            // Delete activity type
            $deleteStmt = $pdo->prepare("DELETE FROM activity_types WHERE id = ?");
            $deleteStmt->execute([$delete_id]);
            
            // Log activity
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                VALUES (?, 5, 'Activity type deleted', ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['user_id'],
                json_encode(['deleted_activity_type_id' => $delete_id]),
                $_SESSION['user_id']
            ]);
            
            $success_message = "Activity type deleted successfully.";
        }
    } catch (PDOException $e) {
        error_log("Error deleting activity type: " . $e->getMessage());
        $error_message = "An error occurred while deleting the activity type.";
    }
}

// Handle add form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        $error_message = "Activity type name is required.";
    } else {
        try {
            // Check if name already exists
            $checkStmt = $pdo->prepare("SELECT id FROM activity_types WHERE name = ?");
            $checkStmt->execute([$name]);
            
            if ($checkStmt->fetch()) {
                $error_message = "Activity type with this name already exists.";
            } else {
                // Insert new activity type
                $insertStmt = $pdo->prepare("
                    INSERT INTO activity_types (name, description, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$name, $description, $_SESSION['user_id']]);
                
                $new_id = $pdo->lastInsertId();
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                    VALUES (?, 3, 'Activity type created', ?, ?)
                ");
                $logStmt->execute([
                    $_SESSION['user_id'],
                    json_encode(['activity_type_id' => $new_id, 'name' => $name]),
                    $_SESSION['user_id']
                ]);
                
                $success_message = "Activity type added successfully.";
            }
        } catch (PDOException $e) {
            error_log("Error adding activity type: " . $e->getMessage());
            $error_message = "An error occurred while adding the activity type.";
        }
    }
}

// Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        $error_message = "Activity type name is required.";
    } else if ($id <= 0) {
        $error_message = "Invalid activity type ID.";
    } else {
        try {
            // Check if name already exists for another record
            $checkStmt = $pdo->prepare("SELECT id FROM activity_types WHERE name = ? AND id != ?");
            $checkStmt->execute([$name, $id]);
            
            if ($checkStmt->fetch()) {
                $error_message = "Activity type with this name already exists.";
            } else {
                // Update activity type
                $updateStmt = $pdo->prepare("
                    UPDATE activity_types 
                    SET name = ?, description = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$name, $description, $_SESSION['user_id'], $id]);
                
                // Log activity
                $logStmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
                    VALUES (?, 4, 'Activity type updated', ?, ?)
                ");
                $logStmt->execute([
                    $_SESSION['user_id'],
                    json_encode(['activity_type_id' => $id, 'name' => $name]),
                    $_SESSION['user_id']
                ]);
                
                $success_message = "Activity type updated successfully.";
            }
        } catch (PDOException $e) {
            error_log("Error updating activity type: " . $e->getMessage());
            $error_message = "An error occurred while updating the activity type.";
        }
    }
}

// Build query with search
$query = "SELECT at.*, 
          u1.full_name as created_by_name,
          u2.full_name as updated_by_name,
          (SELECT COUNT(*) FROM activity_logs WHERE activity_type_id = at.id) as usage_count
          FROM activity_types at
          LEFT JOIN users u1 ON at.created_by = u1.id
          LEFT JOIN users u2 ON at.updated_by = u2.id
          WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM activity_types WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (at.name LIKE :search OR at.description LIKE :search)";
    $count_query .= " AND (name LIKE :search OR description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Get total records for pagination
$countStmt = $pdo->prepare($count_query);
$countStmt->execute($params);
$total_records = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get activity types for current page
$query .= " ORDER BY at.created_at DESC LIMIT :offset, :limit";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$activity_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
try {
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_types,
            SUM((SELECT COUNT(*) FROM activity_logs WHERE activity_type_id = activity_types.id)) as total_usage,
            MAX(created_at) as latest_created
        FROM activity_types
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = [
        'total_types' => 0,
        'total_usage' => 0,
        'latest_created' => null
    ];
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
                            <h4 class="mb-0 font-size-18">Activity Types Management</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="masters.php">Masters</a></li>
                                    <li class="breadcrumb-item active">Activity Types</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-format-list-bulleted font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Activity Types</p>
                                        <h4><?= number_format($stats['total_types'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-counter font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Usage</p>
                                        <h4><?= number_format($stats['total_usage'] ?? 0) ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-calendar-clock font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Latest Created</p>
                                        <h4><?= $stats['latest_created'] ? date('d M Y', strtotime($stats['latest_created'])) : 'N/A' ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addActivityTypeModal">
                                        <i class="mdi mdi-plus"></i> Add New Activity Type
                                    </button>
                                    <button type="button" class="btn btn-success" id="exportBtn">
                                        <i class="mdi mdi-export"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filters -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Search Activity Types</h4>
                                <form method="GET" action="activity-types.php" class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" 
                                                   placeholder="Search by name or description..." 
                                                   value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Search
                                                </button>
                                                <a href="activity-types.php" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i> Reset
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Types Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Activity Types List</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <form method="GET" action="activity-types.php" class="d-inline-block">
                                                <select name="per_page" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                    <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                                                    <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                                                    <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                                    <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                                </select>
                                                <?php if (!empty($search)): ?>
                                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Name</th>
                                                <th>Description</th>
                                                <th>Usage Count</th>
                                                <th>Created By</th>
                                                <th>Created At</th>
                                                <th>Updated By</th>
                                                <th>Updated At</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($activity_types)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No activity types found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($activity_types as $type): ?>
                                                <tr>
                                                    <td><?= $type['id'] ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($type['name']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($type['description'] ?? '-') ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-info text-info">
                                                            <?= number_format($type['usage_count']) ?> logs
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($type['created_by_name'] ?? 'System') ?></td>
                                                    <td><?= date('d M Y, h:i A', strtotime($type['created_at'])) ?></td>
                                                    <td><?= htmlspecialchars($type['updated_by_name'] ?? '-') ?></td>
                                                    <td><?= $type['updated_at'] ? date('d M Y, h:i A', strtotime($type['updated_at'])) : '-' ?></td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-soft-primary" 
                                                                    onclick="editActivityType(<?= htmlspecialchars(json_encode($type)) ?>)">
                                                                <i class="mdi mdi-pencil"></i>
                                                            </button>
                                                            <?php if ($type['usage_count'] == 0): ?>
                                                            <button type="button" class="btn btn-sm btn-soft-danger" 
                                                                    onclick="deleteActivityType(<?= $type['id'] ?>, '<?= htmlspecialchars($type['name']) ?>')">
                                                                <i class="mdi mdi-delete"></i>
                                                            </button>
                                                            <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-soft-secondary" 
                                                                    title="Cannot delete - in use" disabled>
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
                                            Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination justify-content-end">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>" tabindex="-1">Previous</a>
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
                                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Add Activity Type Modal -->
<div class="modal fade" id="addActivityTypeModal" tabindex="-1" aria-labelledby="addActivityTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addActivityTypeModalLabel">Add New Activity Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="activity-types.php" id="addActivityTypeForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Activity Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               placeholder="e.g., login, logout, create" required>
                        <small class="text-muted">Unique identifier for the activity</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Describe what this activity type represents"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="mdi mdi-information me-2"></i>
                        Common activity types: login, logout, create, update, delete, view, export, print
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Activity Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Activity Type Modal -->
<div class="modal fade" id="editActivityTypeModal" tabindex="-1" aria-labelledby="editActivityTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editActivityTypeModalLabel">Edit Activity Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="activity-types.php" id="editActivityTypeForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Activity Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Activity Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

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

    // Edit activity type function
    function editActivityType(type) {
        document.getElementById('edit_id').value = type.id;
        document.getElementById('edit_name').value = type.name;
        document.getElementById('edit_description').value = type.description || '';
        
        var modal = new bootstrap.Modal(document.getElementById('editActivityTypeModal'));
        modal.show();
    }

    // Delete activity type function
    function deleteActivityType(id, name) {
        Swal.fire({
            title: 'Delete Activity Type',
            text: `Are you sure you want to delete "${name}"? This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `activity-types.php?action=delete&id=${id}`;
            }
        });
    }

    // Form validation for add
    document.getElementById('addActivityTypeForm')?.addEventListener('submit', function(e) {
        const name = document.getElementById('name').value.trim();
        
        if (name === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Activity name is required',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
        
        // Check for spaces in name (optional - depending on your naming convention)
        if (name.includes(' ')) {
            if (!confirm('Activity name contains spaces. Are you sure you want to continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Form validation for edit
    document.getElementById('editActivityTypeForm')?.addEventListener('submit', function(e) {
        const name = document.getElementById('edit_name').value.trim();
        
        if (name === '') {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Activity name is required',
                confirmButtonColor: '#556ee6'
            });
            return false;
        }
    });

    // Export functionality
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        exportToCSV();
    });

    // Export to CSV function
    function exportToCSV() {
        const types = <?= json_encode($activity_types) ?>;
        
        if (types.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Data',
                text: 'No activity types to export',
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        // Create CSV content
        let csv = 'ID,Name,Description,Usage Count,Created By,Created At,Updated By,Updated At\n';
        
        types.forEach(type => {
            csv += `${type.id},"${type.name}","${type.description || ''}",${type.usage_count},"${type.created_by_name || 'System'}","${type.created_at}","${type.updated_by_name || ''}","${type.updated_at || ''}"\n`;
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `activity_types_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
        
        Swal.fire({
            icon: 'success',
            title: 'Exported',
            text: 'Activity types exported successfully',
            timer: 1500,
            showConfirmButton: false
        });
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            setTimeout(function() {
                bsAlert.close();
            }, 5000);
        });
    }, 100);
</script>

<?php
// Helper function to build pagination URL
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'activity-types.php?' . http_build_query($params);
}
?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .select2, .no-print {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 9pt;
    }
}

.table td {
    vertical-align: middle;
}

/* Button styles */
.btn-soft-primary, .btn-soft-success, .btn-soft-info, .btn-soft-warning, .btn-soft-danger {
    transition: all 0.3s;
}

.btn-soft-primary:hover, .btn-soft-success:hover, .btn-soft-info:hover, 
.btn-soft-warning:hover, .btn-soft-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

/* Usage count badge */
.badge.bg-soft-info {
    font-size: 0.8rem;
    padding: 6px 10px;
}

/* Modal styles */
.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

.modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table {
        font-size: 8pt;
    }
    .table td, .table th {
        padding: 0.5rem;
    }
    .btn-sm {
        padding: 0.2rem 0.4rem;
    }
}

/* SweetAlert2 customization */
.swal2-popup {
    font-family: inherit;
}

.swal2-title {
    font-size: 1.2rem;
}

.swal2-confirm {
    background-color: #556ee6 !important;
}

/* Disabled button styling */
.btn-soft-secondary[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-soft-secondary[disabled]:hover {
    transform: none;
    box-shadow: none;
}

/* Info alert styling */
.alert-info {
    background-color: rgba(80, 165, 241, 0.1);
    border-color: rgba(80, 165, 241, 0.2);
    color: #50a5f1;
}
</style>

</body>
</html>