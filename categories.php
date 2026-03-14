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
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_category_id = !empty($_POST['parent_category_id']) ? (int)$_POST['parent_category_id'] : null;
    
    if (empty($name)) {
        $error = "Category name is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if category name already exists
            $checkStmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                throw new Exception("A category with this name already exists.");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO categories (name, description, parent_category_id, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([$name, $description ?: null, $parent_category_id, $_SESSION['user_id']]);
            
            if ($result) {
                $category_id = $pdo->lastInsertId();
                
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 3, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'category_id' => $category_id,
                    'category_name' => $name
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    'New category created: ' . $name,
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Category created successfully.";
                header("Location: categories.php");
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

// Handle edit category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $category_id = (int)$_POST['category_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_category_id = !empty($_POST['parent_category_id']) ? (int)$_POST['parent_category_id'] : null;
    
    if (empty($name)) {
        $error = "Category name is required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if category name already exists for another category
            $checkStmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $checkStmt->execute([$name, $category_id]);
            if ($checkStmt->fetch()) {
                throw new Exception("A category with this name already exists.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE categories SET name = ?, description = ?, parent_category_id = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([$name, $description ?: null, $parent_category_id, $_SESSION['user_id'], $category_id]);
            
            if ($result) {
                // Log activity
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                    VALUES (?, 4, ?, ?, NOW())
                ");
                
                $activity_data = json_encode([
                    'category_id' => $category_id,
                    'category_name' => $name
                ]);
                
                $activity_stmt->execute([
                    $_SESSION['user_id'],
                    'Category updated: ' . $name,
                    $activity_data
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Category updated successfully.";
                header("Location: categories.php");
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
    $category_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if category has subcategories
        $checkSubStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_category_id = ?");
        $checkSubStmt->execute([$category_id]);
        $subCount = $checkSubStmt->fetchColumn();
        
        // Check if category has products
        $checkProductStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $checkProductStmt->execute([$category_id]);
        $productCount = $checkProductStmt->fetchColumn();
        
        if ($subCount > 0 || $productCount > 0) {
            throw new Exception("Cannot delete category because it has subcategories or products.");
        }
        
        // Get category details for logging
        $getStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $getStmt->execute([$category_id]);
        $category = $getStmt->fetch();
        
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $result = $stmt->execute([$category_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'category_id' => $category_id,
                'category_name' => $category['name']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Category deleted: " . $category['name'],
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Category deleted successfully.";
        }
        
        header("Location: categories.php?" . http_build_query(['search' => $search, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: categories.php?" . http_build_query(['search' => $search, 'page' => $page]));
        exit();
    }
}

// Build query
$query = "SELECT c.*, p.name as parent_name, u.full_name as created_by_name 
          FROM categories c 
          LEFT JOIN categories p ON c.parent_category_id = p.id 
          LEFT JOIN users u ON c.created_by = u.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM categories WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (c.name LIKE ? OR c.description LIKE ?)";
    $countQuery .= " AND (name LIKE ? OR description LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$categories = $stmt->fetchAll();

// Get all categories for parent dropdown
$parentStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$parentCategories = $parentStmt->fetchAll();

// Get statistics
$statsStmt = $pdo->query("SELECT COUNT(*) as total, COUNT(DISTINCT parent_category_id) as main FROM categories");
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
                            <h4 class="mb-0 font-size-18">Manage Categories</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                                    <li class="breadcrumb-item active">Categories</li>
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
                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format($stats['total'] ?? 0) ?></h3>
                                Total Categories
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2"><?= number_format($stats['main'] ?? 0) ?></h3>
                                Main Categories
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
                                                <div class="col-md-8">
                                                    <div class="mb-3">
                                                        <label class="form-label">Search</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   name="search" 
                                                                   placeholder="Search by name or description..." 
                                                                   value="<?= htmlspecialchars($search) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-50">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                            <a href="categories.php" class="btn btn-secondary w-50">
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
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                                <i class="mdi mdi-plus me-1"></i> Add Category
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Categories Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Category List</h4>
                                
                                <?php if (empty($categories)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-tag-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No categories found</h5>
                                        <p class="text-muted">Click the button below to add your first category</p>
                                        <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="mdi mdi-plus me-1"></i> Add Category
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Name</th>
                                                    <th>Description</th>
                                                    <th>Parent Category</th>
                                                    <th>Created By</th>
                                                    <th>Created</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($categories as $category): ?>
                                                    <tr>
                                                        <td><?= $category['id'] ?></td>
                                                        <td><strong><?= htmlspecialchars($category['name']) ?></strong></td>
                                                        <td><?= htmlspecialchars($category['description'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <?php if ($category['parent_category_id']): ?>
                                                                <span class="badge bg-soft-info text-info">
                                                                    <?= htmlspecialchars($category['parent_name']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-success text-success">Main Category</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($category['created_by_name'] ?? 'N/A') ?></td>
                                                        <td><?= date('d-m-Y', strtotime($category['created_at'])) ?></td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-soft-primary" 
                                                                        onclick="editCategory(<?= htmlspecialchars(json_encode($category)) ?>)"
                                                                        data-bs-toggle="tooltip" 
                                                                        title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
                                                                </button>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $category['id'] ?>, '<?= htmlspecialchars(addslashes($category['name'])) ?>')"
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-tag-plus me-2"></i>Add Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Enter category name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Enter description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_category_id" class="form-control">
                            <option value="">None (Main Category)</option>
                            <?php foreach ($parentCategories as $pc): ?>
                                <option value="<?= $pc['id'] ?>"><?= htmlspecialchars($pc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-success">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="mdi mdi-tag-edit me-2"></i>Edit Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_category_id" id="edit_parent_id" class="form-control">
                            <option value="">None (Main Category)</option>
                            <?php foreach ($parentCategories as $pc): ?>
                                <option value="<?= $pc['id'] ?>"><?= htmlspecialchars($pc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_category" class="btn btn-primary">Update Category</button>
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

    // Edit category function
    function editCategory(category) {
        document.getElementById('edit_category_id').value = category.id;
        document.getElementById('edit_name').value = category.name;
        document.getElementById('edit_description').value = category.description || '';
        document.getElementById('edit_parent_id').value = category.parent_category_id || '';
        $('#editCategoryModal').modal('show');
    }

    // Confirm delete
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Delete Category?',
            html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>
                   <span class="text-danger">This action cannot be undone!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `categories.php?delete=1&id=${id}`;
            }
        });
    }

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
</script>

</body>
</html>