<?php

session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Check if user is admin (optional - remove if you want all users to access)
// if ($_SESSION['user_role'] !== 'admin') {
//     header("Location: index.php?error=unauthorized");
//     exit();
// }

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : date('Y-m-d', strtotime('-30 days'));
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : date('Y-m-d');
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : '';
$filter_activity_type = isset($_GET['filter_activity_type']) ? $_GET['filter_activity_type'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get users for filter dropdown
try {
    $usersStmt = $pdo->query("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
    $users = $usersStmt->fetchAll();
} catch (Exception $e) {
    $users = [];
    error_log("Error fetching users: " . $e->getMessage());
}

// Get activity types for filter dropdown
try {
    $typesStmt = $pdo->query("SELECT id, name, description FROM activity_types ORDER BY name");
    $activity_types = $typesStmt->fetchAll();
} catch (Exception $e) {
    $activity_types = [];
    error_log("Error fetching activity types: " . $e->getMessage());
}

// Build query with filters
$query = "
    SELECT al.*, 
           u.username as user_username,
           u.full_name as user_full_name,
           at.name as activity_name,
           at.description as activity_description,
           cu.username as created_by_username,
           cu.full_name as created_by_full_name
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    LEFT JOIN activity_types at ON al.activity_type_id = at.id
    LEFT JOIN users cu ON al.created_by = cu.id
    WHERE 1=1
";

$count_query = "
    SELECT COUNT(*) as total 
    FROM activity_logs al
    WHERE 1=1
";

$params = [];

// Apply date filters
if (!empty($filter_date_from)) {
    $query .= " AND DATE(al.created_at) >= :date_from";
    $count_query .= " AND DATE(created_at) >= :date_from";
    $params[':date_from'] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $query .= " AND DATE(al.created_at) <= :date_to";
    $count_query .= " AND DATE(created_at) <= :date_to";
    $params[':date_to'] = $filter_date_to;
}

// Apply user filter
if (!empty($filter_user)) {
    $query .= " AND al.user_id = :user_id";
    $count_query .= " AND user_id = :user_id";
    $params[':user_id'] = $filter_user;
}

// Apply activity type filter
if (!empty($filter_activity_type)) {
    $query .= " AND al.activity_type_id = :activity_type_id";
    $count_query .= " AND activity_type_id = :activity_type_id";
    $params[':activity_type_id'] = $filter_activity_type;
}

// Apply search
if (!empty($search)) {
    $query .= " AND (al.description LIKE :search OR al.activity_data LIKE :search)";
    $count_query .= " AND (description LIKE :search OR activity_data LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Get total records for pagination
$countStmt = $pdo->prepare($count_query);
$countStmt->execute($params);
$total_records = $countStmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get logs for current page
$query .= " ORDER BY al.created_at DESC LIMIT :offset, :limit";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Get statistics
try {
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_logs,
            COUNT(DISTINCT user_id) as unique_users,
            COUNT(DISTINCT activity_type_id) as unique_activities,
            MIN(created_at) as oldest_log,
            MAX(created_at) as newest_log
        FROM activity_logs
        WHERE DATE(created_at) BETWEEN :date_from AND :date_to
    ");
    $statsStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $stats = $statsStmt->fetch();
    
    // Get activity breakdown for chart
    $breakdownStmt = $pdo->prepare("
        SELECT 
            at.name as activity_name,
            COUNT(*) as count
        FROM activity_logs al
        JOIN activity_types at ON al.activity_type_id = at.id
        WHERE DATE(al.created_at) BETWEEN :date_from AND :date_to
        GROUP BY al.activity_type_id, at.name
        ORDER BY count DESC
        LIMIT 5
    ");
    $breakdownStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $activity_breakdown = $breakdownStmt->fetchAll();
    
} catch (Exception $e) {
    $stats = [
        'total_logs' => 0,
        'unique_users' => 0,
        'unique_activities' => 0,
        'oldest_log' => null,
        'newest_log' => null
    ];
    $activity_breakdown = [];
}

// Get daily activity for trend chart
try {
    $trendStmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as log_date,
            COUNT(*) as count
        FROM activity_logs
        WHERE DATE(created_at) BETWEEN :date_from AND :date_to
        GROUP BY DATE(created_at)
        ORDER BY log_date ASC
    ");
    $trendStmt->execute([
        ':date_from' => $filter_date_from,
        ':date_to' => $filter_date_to
    ]);
    $trend_data = $trendStmt->fetchAll();
} catch (Exception $e) {
    $trend_data = [];
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- Chart JS -->
    <script src="assets/libs/apexcharts/apexcharts.min.js"></script>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                            <h4 class="mb-0 font-size-18">Activity Logs</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Audit</a></li>
                                    <li class="breadcrumb-item active">Activity Logs</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Welcome Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h4 class="mb-2">Activity Logs Overview</h4>
                                        <p class="mb-0">Track all user activities and system events.</p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="mdi mdi-history" style="font-size: 48px; opacity: 0.5;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end welcome row -->

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-logs-chart" data-colors='["--bs-primary"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($stats['total_logs'] ?? 0) ?></h4>
                                    <p class="text-muted mb-0">Total Logs</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-clock-outline"></i></span> Selected period
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="unique-users-chart" data-colors='["--bs-info"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($stats['unique_users'] ?? 0) ?></h4>
                                    <p class="text-muted mb-0">Active Users</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-info me-1"><i class="mdi mdi-account-group"></i></span> Unique users
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="activity-types-chart" data-colors='["--bs-success"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($stats['unique_activities'] ?? 0) ?></h4>
                                    <p class="text-muted mb-0">Activity Types</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-format-list-bulleted"></i></span> Different activities
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="date-range-chart" data-colors='["--bs-warning"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= date('d M', strtotime($filter_date_from)) ?> - <?= date('d M', strtotime($filter_date_to)) ?></h4>
                                    <p class="text-muted mb-0">Date Range</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-warning me-1"><i class="mdi mdi-calendar-range"></i></span> Selected period
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end statistics cards -->

                <!-- Charts Row -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Activity Breakdown</h4>
                                <div id="activity-breakdown-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Activity Trend</h4>
                                <div id="activity-trend-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end charts row -->

                <!-- Filter Row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Logs</h4>
                                <form method="GET" action="activity-logs.php" class="row">
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="filter_date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_user" class="form-label">User</label>
                                            <select class="form-control select2" id="filter_user" name="filter_user">
                                                <option value="">All Users</option>
                                                <?php foreach ($users as $user): ?>
                                                <option value="<?= $user['id'] ?>" <?= $filter_user == $user['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($user['full_name'] ?? '') ?> (<?= htmlspecialchars($user['username'] ?? '') ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="filter_activity_type" class="form-label">Activity Type</label>
                                            <select class="form-control select2" id="filter_activity_type" name="filter_activity_type">
                                                <option value="">All Activities</option>
                                                <?php foreach ($activity_types as $type): ?>
                                                <option value="<?= $type['id'] ?>" <?= $filter_activity_type == $type['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($type['name'] ?? '') ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="activity-logs.php" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i> Reset
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                                
                                <!-- Search Bar -->
                                <form method="GET" action="activity-logs.php" class="row mt-3">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Search in descriptions or data..." value="<?= htmlspecialchars($search ?? '') ?>">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="mdi mdi-magnify"></i> Search
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="per_page" class="form-select" onchange="this.form.submit()">
                                            <option value="20" <?= $records_per_page == 20 ? 'selected' : '' ?>>20 per page</option>
                                            <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                                            <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100 per page</option>
                                            <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200 per page</option>
                                        </select>
                                    </div>
                                    <?php 
                                    // Preserve other filter parameters
                                    foreach (['filter_date_from', 'filter_date_to', 'filter_user', 'filter_activity_type'] as $param):
                                        if (!empty($_GET[$param])):
                                    ?>
                                    <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars($_GET[$param] ?? '') ?>">
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-success" id="exportBtn">
                                        <i class="mdi mdi-export"></i> Export Logs
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                                    <button type="button" class="btn btn-danger" id="clearOldBtn">
                                        <i class="mdi mdi-delete-sweep"></i> Clear Old Logs
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Logs Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Activity Log Entries</h4>
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
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>User</th>
                                                <th>Activity</th>
                                                <th>Description</th>
                                                <th>Data</th>
                                                <th>Created By</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($logs)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No activity logs found for selected filters</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td>
                                                        <div class="font-size-14"><?= date('d M Y', strtotime($log['created_at'] ?? 'now')) ?></div>
                                                        <small class="text-muted"><?= date('h:i:s A', strtotime($log['created_at'] ?? 'now')) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($log['user_id'])): ?>
                                                            <h5 class="font-size-14 mb-1"><?= htmlspecialchars($log['user_full_name'] ?? $log['user_username'] ?? '') ?></h5>
                                                            <small class="text-muted">@<?= htmlspecialchars($log['user_username'] ?? '') ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">System</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $badgeClass = 'secondary';
                                                        $activity_name = $log['activity_name'] ?? '';
                                                        switch($activity_name) {
                                                            case 'login':
                                                                $badgeClass = 'success';
                                                                break;
                                                            case 'logout':
                                                                $badgeClass = 'info';
                                                                break;
                                                            case 'create':
                                                                $badgeClass = 'primary';
                                                                break;
                                                            case 'update':
                                                                $badgeClass = 'warning';
                                                                break;
                                                            case 'delete':
                                                                $badgeClass = 'danger';
                                                                break;
                                                            case 'view':
                                                                $badgeClass = 'secondary';
                                                                break;
                                                            case 'export':
                                                                $badgeClass = 'dark';
                                                                break;
                                                            case 'print':
                                                                $badgeClass = 'light';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $badgeClass ?> text-<?= $badgeClass ?>">
                                                            <?= htmlspecialchars($activity_name ?: 'Unknown') ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($log['description'] ?? '') ?>">
                                                            <?= htmlspecialchars($log['description'] ?? '') ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($log['activity_data'])): ?>
                                                            <button type="button" class="btn btn-sm btn-soft-info" onclick='viewData(<?= json_encode($log['activity_data']) ?>)'>
                                                                <i class="mdi mdi-code-json"></i> View
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($log['created_by'])): ?>
                                                            <small><?= htmlspecialchars($log['created_by_full_name'] ?? $log['created_by_username'] ?? '') ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">System</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-soft-primary" onclick='viewDetails(<?= json_encode($log) ?>)'>
                                                            <i class="mdi mdi-eye"></i>
                                                        </button>
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

<!-- View Data Modal -->
<div class="modal fade" id="viewDataModal" tabindex="-1" aria-labelledby="viewDataModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewDataModalLabel">Activity Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre id="jsonData" class="bg-light p-3 rounded" style="max-height: 400px; overflow: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="copyJson()">
                    <i class="mdi mdi-content-copy"></i> Copy
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewDetailsModalLabel">Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered">
                    <tr>
                        <th width="40%">ID</th>
                        <td id="detail_id"></td>
                    </tr>
                    <tr>
                        <th>Date & Time</th>
                        <td id="detail_datetime"></td>
                    </tr>
                    <tr>
                        <th>User</th>
                        <td id="detail_user"></td>
                    </tr>
                    <tr>
                        <th>Activity Type</th>
                        <td id="detail_activity"></td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td id="detail_description"></td>
                    </tr>
                    <tr>
                        <th>IP Address</th>
                        <td id="detail_ip"></td>
                    </tr>
                    <tr>
                        <th>User Agent</th>
                        <td id="detail_user_agent"></td>
                    </tr>
                    <tr>
                        <th>Created By</th>
                        <td id="detail_created_by"></td>
                    </tr>
                    <tr>
                        <th>Created At</th>
                        <td id="detail_created_at"></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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

    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select option'
    });

    // Mini charts for stat cards
    function generateMiniChart(elementId, color, data) {
        var options = {
            chart: {
                height: 40,
                width: 70,
                type: 'line',
                sparkline: {
                    enabled: true
                },
                toolbar: {
                    show: false
                }
            },
            colors: [color],
            series: [{
                data: data
            }],
            stroke: {
                curve: 'smooth',
                width: 2
            },
            fill: {
                opacity: 1
            }
        };
        
        var chart = new ApexCharts(document.querySelector(elementId), options);
        chart.render();
    }

    // Generate mini charts
    generateMiniChart('#total-logs-chart', '#556ee6', [20, 25, 30, 28, 32, 35, 40]);
    generateMiniChart('#unique-users-chart', '#34c38f', [15, 18, 20, 22, 25, 28, 30]);
    generateMiniChart('#activity-types-chart', '#50a5f1', [5, 7, 8, 8, 9, 10, 10]);
    generateMiniChart('#date-range-chart', '#f1b44c', [7, 7, 7, 7, 7, 7, 7]);

    // Activity Breakdown Chart
    <?php if (!empty($activity_breakdown)): ?>
    var breakdownData = <?= json_encode($activity_breakdown) ?>;
    var breakdownLabels = breakdownData.map(item => item.activity_name || 'Unknown');
    var breakdownCounts = breakdownData.map(item => parseInt(item.count) || 0);

    var breakdownOptions = {
        chart: {
            height: 300,
            type: 'pie'
        },
        series: breakdownCounts,
        labels: breakdownLabels,
        colors: ['#556ee6', '#34c38f', '#f46a6a', '#50a5f1', '#f1b44c'],
        legend: {
            position: 'bottom'
        },
        tooltip: {
            y: {
                formatter: function(val) {
                    return val + ' activities';
                }
            }
        }
    };

    var breakdownChart = new ApexCharts(document.querySelector("#activity-breakdown-chart"), breakdownOptions);
    breakdownChart.render();
    <?php endif; ?>

    // Activity Trend Chart
    <?php if (!empty($trend_data)): ?>
    var trendData = <?= json_encode($trend_data) ?>;
    var trendDates = trendData.map(item => {
        if (item.log_date) {
            var date = new Date(item.log_date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
        return '';
    });
    var trendCounts = trendData.map(item => parseInt(item.count) || 0);

    var trendOptions = {
        chart: {
            height: 300,
            type: 'area',
            toolbar: {
                show: true
            }
        },
        series: [{
            name: 'Activities',
            data: trendCounts
        }],
        xaxis: {
            categories: trendDates,
            title: {
                text: 'Date'
            }
        },
        yaxis: {
            title: {
                text: 'Number of Activities'
            },
            min: 0
        },
        colors: ['#556ee6'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.3
            }
        },
        tooltip: {
            y: {
                formatter: function(val) {
                    return val + ' activities';
                }
            }
        }
    };

    var trendChart = new ApexCharts(document.querySelector("#activity-trend-chart"), trendOptions);
    trendChart.render();
    <?php endif; ?>

    // View JSON data
    function viewData(data) {
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (e) {
                // Not valid JSON, keep as string
            }
        }
        
        var formattedJson = JSON.stringify(data, null, 2);
        document.getElementById('jsonData').textContent = formattedJson;
        
        var modal = new bootstrap.Modal(document.getElementById('viewDataModal'));
        modal.show();
    }

    // Copy JSON to clipboard
    function copyJson() {
        var jsonText = document.getElementById('jsonData').textContent;
        navigator.clipboard.writeText(jsonText).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'JSON data copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        }).catch(() => {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to copy to clipboard',
                confirmButtonColor: '#556ee6'
            });
        });
    }

    // View details
    function viewDetails(log) {
        document.getElementById('detail_id').textContent = log.id || '-';
        document.getElementById('detail_datetime').textContent = log.created_at ? new Date(log.created_at).toLocaleString() : '-';
        document.getElementById('detail_user').textContent = log.user_full_name || log.user_username || 'System';
        document.getElementById('detail_activity').textContent = log.activity_name || 'Unknown';
        document.getElementById('detail_description').textContent = log.description || '-';
        document.getElementById('detail_ip').textContent = log.ip_address || '-';
        document.getElementById('detail_user_agent').textContent = log.user_agent || '-';
        document.getElementById('detail_created_by').textContent = log.created_by_full_name || 'System';
        document.getElementById('detail_created_at').textContent = log.created_at ? new Date(log.created_at).toLocaleString() : '-';
        
        var modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
        modal.show();
    }

    // Export functionality
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        exportLogs();
    });

    function exportLogs() {
        var params = new URLSearchParams(window.location.search);
        params.append('export', 'csv');
        window.location.href = 'export-logs.php?' + params.toString();
    }

    // Clear old logs (admin only)
    document.getElementById('clearOldBtn')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Clear Old Logs',
            html: `
                <div class="mb-3 text-start">
                    <label class="form-label">Delete logs older than:</label>
                    <select id="clearDays" class="form-control">
                        <option value="30">30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                        <option value="180">6 months</option>
                        <option value="365">1 year</option>
                    </select>
                </div>
                <p class="text-danger small">This action cannot be undone!</p>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, clear logs!'
        }).then((result) => {
            if (result.isConfirmed) {
                var days = document.getElementById('clearDays').value;
                window.location.href = 'clear-logs.php?days=' + days;
            }
        });
    });

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
    return 'activity-logs.php?' . http_build_query($params);
}
?>

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .select2, .apex-charts {
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
    .badge {
        border: 1px solid #000;
        color: #000 !important;
        background: transparent !important;
    }
}

.table td {
    vertical-align: middle;
}

/* Hover effect on rows */
.table tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}

/* Select2 customization */
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

/* Modal styling */
.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

.modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}

/* Badge styling */
.badge {
    padding: 6px 10px;
    font-size: 11px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table {
        font-size: 8pt;
    }
    .table td, .table th {
        padding: 0.5rem;
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
</style>

</body>
</html>