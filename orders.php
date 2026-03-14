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
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle order status update
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $order_id = (int)$_GET['id'];
    
    $allowed_actions = ['pending', 'confirmed', 'processing', 'delivered', 'cancelled'];
    if (in_array($action, $allowed_actions)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$action, $_SESSION['user_id'], $order_id]);
            
            // Get order details for logging
            $getStmt = $pdo->prepare("SELECT order_number, customer_id FROM orders WHERE id = ?");
            $getStmt->execute([$order_id]);
            $order = $getStmt->fetch();
            
            // Get customer name
            $custStmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $custStmt->execute([$order['customer_id']]);
            $customer = $custStmt->fetch();
            
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 4, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'order_id' => $order_id,
                'order_number' => $order['order_number'],
                'customer_name' => $customer['name'],
                'new_status' => $action
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Order #{$order['order_number']} status updated to $action",
                $activity_data
            ]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Order status updated successfully.";
            header("Location: orders.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to update order status: " . $e->getMessage();
            error_log("Order status update error: " . $e->getMessage());
        }
    }
}

// Handle delete order
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $order_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Check if order has items
        $checkItems = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
        $checkItems->execute([$order_id]);
        $itemCount = $checkItems->fetchColumn();
        
        // Get order details for logging
        $getStmt = $pdo->prepare("SELECT order_number, customer_id FROM orders WHERE id = ?");
        $getStmt->execute([$order_id]);
        $order = $getStmt->fetch();
        
        if (!$order) {
            throw new Exception("Order not found.");
        }
        
        // Get customer name
        $custStmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $custStmt->execute([$order['customer_id']]);
        $customer = $custStmt->fetch();
        
        if ($itemCount > 0) {
            // Delete order items first
            $delItems = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
            $delItems->execute([$order_id]);
        }
        
        // Delete the order
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $result = $stmt->execute([$order_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $activity_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_at)
                VALUES (?, 5, ?, ?, NOW())
            ");
            
            $activity_data = json_encode([
                'order_id' => $order_id,
                'order_number' => $order['order_number'],
                'customer_name' => $customer['name']
            ]);
            
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Order #{$order['order_number']} deleted",
                $activity_data
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Order deleted successfully.";
        }
        
        header("Location: orders.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Failed to delete order: " . $e->getMessage();
        header("Location: orders.php?" . http_build_query(['customer_id' => $customer_id, 'search' => $search, 'status' => $status, 'from_date' => $from_date, 'to_date' => $to_date, 'page' => $page]));
        exit();
    }
}

// Build the query
$query = "
    SELECT 
        o.*,
        c.name as customer_name,
        c.customer_code,
        c.phone as customer_phone,
        c.email as customer_email,
        c.city as customer_city,
        u.full_name as created_by_name,
        u2.full_name as updated_by_name,
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN users u ON o.created_by = u.id
    LEFT JOIN users u2 ON o.updated_by = u2.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE 1=1
";

$params = [];

if ($customer_id > 0) {
    $query .= " AND o.customer_id = ?";
    $countQuery .= " AND o.customer_id = ?";
    $params[] = $customer_id;
}

if (!empty($search)) {
    $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $countQuery .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status !== 'all') {
    $query .= " AND o.status = ?";
    $countQuery .= " AND o.status = ?";
    $params[] = $status;
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(o.order_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(o.order_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$query .= " ORDER BY o.order_date DESC, o.id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(advance_paid), 0) as total_advance,
        COALESCE(SUM(balance_amount), 0) as total_balance
    FROM orders
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Get all customers for dropdown
$custStmt = $pdo->query("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $custStmt->fetchAll();

// Get selected customer details if customer_id is provided
$selectedCustomer = null;
if ($customer_id > 0) {
    $selCustStmt = $pdo->prepare("SELECT name, customer_code, phone, email, city FROM customers WHERE id = ?");
    $selCustStmt->execute([$customer_id]);
    $selectedCustomer = $selCustStmt->fetch();
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
                            <h4 class="mb-0 font-size-18">Manage Orders</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="manage-customers.php">Customers</a></li>
                                    <li class="breadcrumb-item active">Orders</li>
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
                                <h3 class="text-info mt-2"><?= number_format($stats['total_orders'] ?? 0) ?></h3>
                                Total Orders
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-warning mt-2"><?= number_format($stats['pending_orders'] ?? 0) ?></h3>
                                Pending
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-success mt-2"><?= number_format($stats['delivered_orders'] ?? 0) ?></h3>
                                Delivered
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
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2"><?= number_format($stats['confirmed_orders'] ?? 0) ?></h3>
                                Confirmed
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-secondary mt-2"><?= number_format($stats['processing_orders'] ?? 0) ?></h3>
                                Processing
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2"><?= number_format($stats['cancelled_orders'] ?? 0) ?></h3>
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
                                                        <label class="form-label">Customer</label>
                                                        <select name="customer_id" class="form-control">
                                                            <option value="0">All Customers</option>
                                                            <?php foreach ($customers as $cust): ?>
                                                                <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($cust['name']) ?> (<?= htmlspecialchars($cust['customer_code']) ?>)
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
                                                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                            <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                                                            <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Delivered</option>
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
                                                            <a href="orders.php" class="btn btn-secondary w-50">
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
                                                               placeholder="Search by order number, customer name, phone or email..." 
                                                               value="<?= htmlspecialchars($search) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                   
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end filter row -->

                <!-- Customer Details (if selected) -->
                <?php if ($selectedCustomer): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Customer</h6>
                                        <h5><?= htmlspecialchars($selectedCustomer['name']) ?></h5>
                                        <p class="mb-0">Code: <?= htmlspecialchars($selectedCustomer['customer_code']) ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Contact</h6>
                                        <p class="mb-1"><i class="mdi mdi-phone me-1"></i> <?= htmlspecialchars($selectedCustomer['phone']) ?></p>
                                        <p class="mb-0"><i class="mdi mdi-email me-1"></i> <?= htmlspecialchars($selectedCustomer['email'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-primary">Location</h6>
                                        <p class="mb-0"><?= htmlspecialchars($selectedCustomer['city'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Orders Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Orders List</h4>
                                
                                <?php if (empty($orders)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-cart-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No orders found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria</p>
                                       
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Order #</th>
                                                    <th>Customer</th>
                                                    <th>Date</th>
                                                    <th>Delivery Date</th>
                                                    <th>Items</th>
                                                    <th>Total Amount</th>
                                                    <th>Advance</th>
                                                    <th>Balance</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($orders as $order): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                                        </td>
                                                        <td>
                                                            <a href="view-customer.php?id=<?= $order['customer_id'] ?>" class="text-primary">
                                                                <?= htmlspecialchars($order['customer_name']) ?>
                                                            </a>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($order['customer_code']) ?></small>
                                                        </td>
                                                        <td><?= date('d-m-Y', strtotime($order['order_date'])) ?></td>
                                                        <td>
                                                            <?= $order['delivery_date'] ? date('d-m-Y', strtotime($order['delivery_date'])) : '-' ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info"><?= $order['item_count'] ?> items</span>
                                                        </td>
                                                        <td>₹<?= number_format($order['total_amount'], 2) ?></td>
                                                        <td class="text-success">₹<?= number_format($order['advance_paid'], 2) ?></td>
                                                        <td class="<?= $order['balance_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                                            ₹<?= number_format($order['balance_amount'], 2) ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = '';
                                                            $statusIcon = '';
                                                            switch($order['status']) {
                                                                case 'pending':
                                                                    $statusClass = 'warning';
                                                                    $statusIcon = 'clock-outline';
                                                                    break;
                                                                case 'confirmed':
                                                                    $statusClass = 'primary';
                                                                    $statusIcon = 'check-circle';
                                                                    break;
                                                                case 'processing':
                                                                    $statusClass = 'info';
                                                                    $statusIcon = 'cog';
                                                                    break;
                                                                case 'delivered':
                                                                    $statusClass = 'success';
                                                                    $statusIcon = 'truck-check';
                                                                    break;
                                                                case 'cancelled':
                                                                    $statusClass = 'danger';
                                                                    $statusIcon = 'close-circle';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                                <i class="mdi mdi-<?= $statusIcon ?> me-1"></i>
                                                                <?= ucfirst($order['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="view-order.php?id=<?= $order['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-primary" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="View Details">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </a>
                                                                <a href="edit-order.php?id=<?= $order['id'] ?>" 
                                                                   class="btn btn-sm btn-soft-success" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="Edit">
                                                                    <i class="mdi mdi-pencil"></i>
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
                                                                    <li><a class="dropdown-item <?= $order['status'] == 'pending' ? 'disabled' : '' ?>" 
                                                                           href="?action=pending&id=<?= $order['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-clock-outline text-warning me-2"></i> Pending</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $order['status'] == 'confirmed' ? 'disabled' : '' ?>" 
                                                                           href="?action=confirmed&id=<?= $order['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-check-circle text-primary me-2"></i> Confirmed</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $order['status'] == 'processing' ? 'disabled' : '' ?>" 
                                                                           href="?action=processing&id=<?= $order['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-cog text-info me-2"></i> Processing</a>
                                                                    </li>
                                                                    <li><a class="dropdown-item <?= $order['status'] == 'delivered' ? 'disabled' : '' ?>" 
                                                                           href="?action=delivered&id=<?= $order['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-truck-check text-success me-2"></i> Delivered</a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li><a class="dropdown-item <?= $order['status'] == 'cancelled' ? 'disabled' : '' ?> text-danger" 
                                                                           href="?action=cancelled&id=<?= $order['id'] ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&page=<?= $page ?>">
                                                                           <i class="mdi mdi-close-circle text-danger me-2"></i> Cancel Order</a>
                                                                    </li>
                                                                </ul>
                                                                <a href="javascript:void(0);" 
                                                                   onclick="confirmDelete(<?= $order['id'] ?>, '<?= htmlspecialchars(addslashes($order['order_number'])) ?>', '<?= htmlspecialchars(addslashes($search)) ?>', '<?= $customer_id ?>', '<?= $status ?>', '<?= $from_date ?>', '<?= $to_date ?>', <?= $page ?>)"
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
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&customer_id=<?= $customer_id ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">
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

                <!-- Financial Summary -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Order Value Summary</h5>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Order Value:</span>
                                        <strong>₹<?= number_format($stats['total_amount'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Advance Received:</span>
                                        <strong class="text-success">₹<?= number_format($stats['total_advance'] ?? 0, 2) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Total Balance Due:</span>
                                        <strong class="text-danger">₹<?= number_format($stats['total_balance'] ?? 0, 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Order Status Distribution</h5>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?= number_format($stats['pending_orders'] ?? 0) ?></h4>
                                            <p class="mb-0">Pending</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?= number_format(($stats['confirmed_orders'] ?? 0) + ($stats['processing_orders'] ?? 0)) ?></h4>
                                            <p class="mb-0">In Progress</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <h4 class="text-success"><?= number_format($stats['delivered_orders'] ?? 0) ?></h4>
                                            <p class="mb-0">Delivered</p>
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
    document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
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
    function confirmDelete(id, orderNumber, search, customerId, status, fromDate, toDate, page) {
        Swal.fire({
            title: 'Delete Order?',
            html: `Are you sure you want to delete order <strong>#${orderNumber}</strong>?<br><br>
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
                    text: 'Please wait while we delete the order',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                window.location.href = `orders.php?delete=1&id=${id}&customer_id=${customerId}&search=${encodeURIComponent(search)}&status=${status}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Confirm status change
    function confirmStatusChange(action, id, orderNumber, search, customerId, status, fromDate, toDate, page) {
        let title, text, icon, confirmButtonColor;
        
        switch(action) {
            case 'cancelled':
                title = 'Cancel Order?';
                text = `Are you sure you want to cancel order <strong>#${orderNumber}</strong>?`;
                icon = 'warning';
                confirmButtonColor = '#f46a6a';
                break;
            case 'delivered':
                title = 'Mark as Delivered?';
                text = `Are you sure you want to mark order <strong>#${orderNumber}</strong> as delivered?`;
                icon = 'success';
                confirmButtonColor = '#34c38f';
                break;
            default:
                title = 'Change Order Status?';
                text = `Are you sure you want to change the status of order <strong>#${orderNumber}</strong>?`;
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
                window.location.href = `orders.php?action=${action}&id=${id}&customer_id=${customerId}&search=${encodeURIComponent(search)}&status=${status}&from_date=${fromDate}&to_date=${toDate}&page=${page}`;
            }
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Alt + N for new order
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'create-order.php';
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