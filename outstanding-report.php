<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get report parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'customer'; // customer, supplier, both
$as_on_date = isset($_GET['as_on_date']) ? $_GET['as_on_date'] : date('Y-m-d');
$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : '';
$aging_buckets = isset($_GET['aging_buckets']) ? $_GET['aging_buckets'] : '0-30,31-60,61-90,91-180,180+';
$min_amount = isset($_GET['min_amount']) ? floatval($_GET['min_amount']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, overdue, due
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Parse aging buckets
$buckets = explode(',', $aging_buckets);

// Handle AJAX request for customer details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'customer_details' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    
    try {
        $customer_id = intval($_GET['customer_id']);
        $as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');
        
        // Get customer details
        $customerStmt = $pdo->prepare("
            SELECT id, name, customer_code, phone, email, 
                   outstanding_balance, credit_limit, payment_terms
            FROM customers
            WHERE id = :id
        ");
        $customerStmt->execute([':id' => $customer_id]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }
        
        // Get aging details
        $aging = getCustomerAging($pdo, $customer_id, $as_on_date);
        
        // Get invoice details
        $invoiceStmt = $pdo->prepare("
            SELECT 
                id,
                invoice_number,
                invoice_date,
                due_date,
                total_amount,
                paid_amount,
                outstanding_amount,
                DATEDIFF(:as_on_date, due_date) as days_overdue,
                status
            FROM invoices
            WHERE customer_id = :customer_id
                AND status IN ('sent', 'partially_paid', 'overdue')
                AND outstanding_amount > 0
            ORDER BY due_date ASC
        ");
        $invoiceStmt->execute([
            ':as_on_date' => $as_on_date,
            ':customer_id' => $customer_id
        ]);
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payment history
        $paymentStmt = $pdo->prepare("
            SELECT 
                co.transaction_date,
                co.amount,
                co.balance_after,
                i.invoice_number
            FROM customer_outstanding co
            LEFT JOIN invoices i ON co.reference_id = i.id
            WHERE co.customer_id = :customer_id
                AND co.transaction_type = 'payment'
                AND co.transaction_date <= :as_on_date
            ORDER BY co.transaction_date DESC
            LIMIT 10
        ");
        $paymentStmt->execute([
            ':customer_id' => $customer_id,
            ':as_on_date' => $as_on_date
        ]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'aging' => $aging,
            'invoices' => $invoices,
            'payments' => $payments
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for supplier details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'supplier_details' && isset($_GET['supplier_id'])) {
    header('Content-Type: application/json');
    
    try {
        $supplier_id = intval($_GET['supplier_id']);
        $as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');
        
        // Get supplier details
        $supplierStmt = $pdo->prepare("
            SELECT id, name, supplier_code, company_name, phone, email, 
                   outstanding_balance, payment_terms
            FROM suppliers
            WHERE id = :id
        ");
        $supplierStmt->execute([':id' => $supplier_id]);
        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            echo json_encode(['success' => false, 'message' => 'Supplier not found']);
            exit();
        }
        
        // Get aging details
        $aging = getSupplierAging($pdo, $supplier_id, $as_on_date);
        
        // Get purchase order details
        $poStmt = $pdo->prepare("
            SELECT 
                po.id,
                po.po_number,
                po.order_date,
                DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY) as due_date,
                po.total_amount,
                po.status,
                DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) as days_overdue
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.supplier_id = :supplier_id
                AND po.status IN ('sent', 'confirmed', 'partially_received')
                AND po.total_amount > 0
            ORDER BY due_date ASC
        ");
        $poStmt->execute([
            ':as_on_date' => $as_on_date,
            ':supplier_id' => $supplier_id
        ]);
        $orders = $poStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'supplier' => $supplier,
            'aging' => $aging,
            'orders' => $orders
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Function to calculate aging for a customer
function getCustomerAging($pdo, $customer_id, $as_on_date) {
    try {
        $query = "
            SELECT 
                i.id,
                i.invoice_number,
                i.invoice_date,
                i.due_date,
                i.total_amount,
                i.paid_amount,
                i.outstanding_amount,
                DATEDIFF(:as_on_date, i.due_date) as days_overdue,
                CASE 
                    WHEN DATEDIFF(:as_on_date, i.due_date) <= 0 THEN 'current'
                    WHEN DATEDIFF(:as_on_date, i.due_date) BETWEEN 1 AND 30 THEN '1-30'
                    WHEN DATEDIFF(:as_on_date, i.due_date) BETWEEN 31 AND 60 THEN '31-60'
                    WHEN DATEDIFF(:as_on_date, i.due_date) BETWEEN 61 AND 90 THEN '61-90'
                    WHEN DATEDIFF(:as_on_date, i.due_date) BETWEEN 91 AND 180 THEN '91-180'
                    ELSE '180+'
                END as aging_bucket
            FROM invoices i
            WHERE i.customer_id = :customer_id
                AND i.status IN ('sent', 'partially_paid', 'overdue')
                AND i.outstanding_amount > 0
                AND i.invoice_date <= :as_on_date
            ORDER BY i.due_date ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':as_on_date' => $as_on_date,
            ':customer_id' => $customer_id
        ]);
        
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate aging buckets
        $aging = [
            'current' => 0,
            '1-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '91-180' => 0,
            '180+' => 0,
            'total' => 0,
            'invoices' => $invoices
        ];
        
        foreach ($invoices as $inv) {
            $amount = floatval($inv['outstanding_amount']);
            $aging['total'] += $amount;
            
            if ($inv['days_overdue'] <= 0) {
                $aging['current'] += $amount;
            } else {
                $bucket = $inv['aging_bucket'];
                if (isset($aging[$bucket])) {
                    $aging[$bucket] += $amount;
                }
            }
        }
        
        return $aging;
        
    } catch (Exception $e) {
        error_log("Customer aging error: " . $e->getMessage());
        return [
            'current' => 0,
            '1-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '91-180' => 0,
            '180+' => 0,
            'total' => 0,
            'invoices' => []
        ];
    }
}

// Function to calculate aging for a supplier
function getSupplierAging($pdo, $supplier_id, $as_on_date) {
    try {
        $query = "
            SELECT 
                po.id,
                po.po_number,
                po.order_date,
                DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY) as due_date,
                po.total_amount,
                po.status,
                DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) as days_overdue,
                CASE 
                    WHEN DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) <= 0 THEN 'current'
                    WHEN DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 1 AND 30 THEN '1-30'
                    WHEN DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 31 AND 60 THEN '31-60'
                    WHEN DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 61 AND 90 THEN '61-90'
                    WHEN DATEDIFF(:as_on_date, DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 91 AND 180 THEN '91-180'
                    ELSE '180+'
                END as aging_bucket
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.supplier_id = :supplier_id
                AND po.status IN ('sent', 'confirmed', 'partially_received')
                AND po.total_amount > 0
                AND po.order_date <= :as_on_date
            ORDER BY due_date ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':as_on_date' => $as_on_date,
            ':supplier_id' => $supplier_id
        ]);
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate aging buckets
        $aging = [
            'current' => 0,
            '1-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '91-180' => 0,
            '180+' => 0,
            'total' => 0,
            'orders' => $orders
        ];
        
        foreach ($orders as $order) {
            $amount = floatval($order['total_amount']);
            $aging['total'] += $amount;
            
            if ($order['days_overdue'] <= 0) {
                $aging['current'] += $amount;
            } else {
                $bucket = $order['aging_bucket'];
                if (isset($aging[$bucket])) {
                    $aging[$bucket] += $amount;
                }
            }
        }
        
        return $aging;
        
    } catch (Exception $e) {
        error_log("Supplier aging error: " . $e->getMessage());
        return [
            'current' => 0,
            '1-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '91-180' => 0,
            '180+' => 0,
            'total' => 0,
            'orders' => []
        ];
    }
}

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("
        SELECT id, name, customer_code, phone, outstanding_balance, credit_limit 
        FROM customers 
        WHERE is_active = 1 
        ORDER BY name
    ");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
}

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("
        SELECT id, name, supplier_code, company_name, phone, outstanding_balance 
        FROM suppliers 
        WHERE is_active = 1 
        ORDER BY name
    ");
    $suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get customer outstanding data
$customer_data = [];
$supplier_data = [];
$total_customer_outstanding = 0;
$total_supplier_outstanding = 0;
$customer_aging_summary = [
    'current' => 0,
    '1-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    '91-180' => 0,
    '180+' => 0
];
$supplier_aging_summary = [
    'current' => 0,
    '1-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    '91-180' => 0,
    '180+' => 0
];

try {
    if ($report_type == 'customer' || $report_type == 'both') {
        // Get all customers or specific customer
        $customer_query = "
            SELECT id, name, customer_code, phone, email, 
                   outstanding_balance, credit_limit, payment_terms
            FROM customers
            WHERE is_active = 1
        ";
        
        $customer_params = [];
        
        if (!empty($customer_id)) {
            $customer_query .= " AND id = :customer_id";
            $customer_params[':customer_id'] = $customer_id;
        }
        
        if (!empty($search) && $report_type == 'customer') {
            $customer_query .= " AND (name LIKE :search OR customer_code LIKE :search OR phone LIKE :search)";
            $customer_params[':search'] = '%' . $search . '%';
        }
        
        if ($min_amount > 0) {
            $customer_query .= " AND outstanding_balance >= :min_amount";
            $customer_params[':min_amount'] = $min_amount;
        }
        
        if ($status == 'overdue') {
            // Only customers with overdue invoices
            $customer_query .= " AND EXISTS (
                SELECT 1 FROM invoices 
                WHERE customer_id = customers.id 
                AND status IN ('sent', 'partially_paid', 'overdue')
                AND due_date < :as_on_date
                AND outstanding_amount > 0
            )";
            $customer_params[':as_on_date'] = $as_on_date;
        }
        
        $customer_query .= " ORDER BY outstanding_balance DESC";
        
        $customerStmt = $pdo->prepare($customer_query);
        $customerStmt->execute($customer_params);
        $customers_list = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($customers_list as $customer) {
            $aging = getCustomerAging($pdo, $customer['id'], $as_on_date);
            
            $customer_data[] = [
                'id' => $customer['id'],
                'name' => $customer['name'],
                'code' => $customer['customer_code'],
                'phone' => $customer['phone'],
                'email' => $customer['email'],
                'outstanding' => floatval($customer['outstanding_balance']),
                'credit_limit' => floatval($customer['credit_limit']),
                'payment_terms' => $customer['payment_terms'],
                'aging' => $aging,
                'credit_utilization' => floatval($customer['credit_limit']) > 0 
                    ? (floatval($customer['outstanding_balance']) / floatval($customer['credit_limit']) * 100) 
                    : 0
            ];
            
            $total_customer_outstanding += floatval($customer['outstanding_balance']);
            
            // Add to aging summary
            $customer_aging_summary['current'] += $aging['current'];
            $customer_aging_summary['1-30'] += $aging['1-30'];
            $customer_aging_summary['31-60'] += $aging['31-60'];
            $customer_aging_summary['61-90'] += $aging['61-90'];
            $customer_aging_summary['91-180'] += $aging['91-180'];
            $customer_aging_summary['180+'] += $aging['180+'];
        }
    }
    
    if ($report_type == 'supplier' || $report_type == 'both') {
        // Get all suppliers or specific supplier
        $supplier_query = "
            SELECT id, name, supplier_code, company_name, phone, email, 
                   outstanding_balance, payment_terms
            FROM suppliers
            WHERE is_active = 1
        ";
        
        $supplier_params = [];
        
        if (!empty($supplier_id)) {
            $supplier_query .= " AND id = :supplier_id";
            $supplier_params[':supplier_id'] = $supplier_id;
        }
        
        if (!empty($search) && $report_type == 'supplier') {
            $supplier_query .= " AND (name LIKE :search OR supplier_code LIKE :search OR company_name LIKE :search)";
            $supplier_params[':search'] = '%' . $search . '%';
        }
        
        if ($min_amount > 0) {
            $supplier_query .= " AND outstanding_balance >= :min_amount";
            $supplier_params[':min_amount'] = $min_amount;
        }
        
        $supplier_query .= " ORDER BY outstanding_balance DESC";
        
        $supplierStmt = $pdo->prepare($supplier_query);
        $supplierStmt->execute($supplier_params);
        $suppliers_list = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($suppliers_list as $supplier) {
            $aging = getSupplierAging($pdo, $supplier['id'], $as_on_date);
            
            $supplier_data[] = [
                'id' => $supplier['id'],
                'name' => $supplier['name'],
                'code' => $supplier['supplier_code'],
                'company' => $supplier['company_name'],
                'phone' => $supplier['phone'],
                'email' => $supplier['email'],
                'outstanding' => floatval($supplier['outstanding_balance']),
                'payment_terms' => $supplier['payment_terms'],
                'aging' => $aging
            ];
            
            $total_supplier_outstanding += floatval($supplier['outstanding_balance']);
            
            // Add to aging summary
            $supplier_aging_summary['current'] += $aging['current'];
            $supplier_aging_summary['1-30'] += $aging['1-30'];
            $supplier_aging_summary['31-60'] += $aging['31-60'];
            $supplier_aging_summary['61-90'] += $aging['61-90'];
            $supplier_aging_summary['91-180'] += $aging['91-180'];
            $supplier_aging_summary['180+'] += $aging['180+'];
        }
    }
    
    // Log activity
    if (isset($_SESSION['user_id'])) {
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (:user_id, 6, :description, :activity_data, :created_by)
        ");
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':description' => "Generated outstanding report as on " . $as_on_date,
            ':activity_data' => json_encode([
                'report_type' => $report_type,
                'as_on_date' => $as_on_date,
                'customer_outstanding' => $total_customer_outstanding,
                'supplier_outstanding' => $total_supplier_outstanding
            ]),
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
    }
    
} catch (Exception $e) {
    error_log("Outstanding report error: " . $e->getMessage());
    $error_message = "Error generating report: " . $e->getMessage();
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($customer_data, $supplier_data, $report_type, $as_on_date);
    exit();
}

// Export function
function exportToCSV($customer_data, $supplier_data, $report_type, $as_on_date) {
    $filename = 'outstanding_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Outstanding Report as on ' . date('d M Y', strtotime($as_on_date))]);
    fputcsv($output, []);
    
    if ($report_type == 'customer' || $report_type == 'both') {
        fputcsv($output, ['CUSTOMER OUTSTANDING']);
        fputcsv($output, ['Customer', 'Code', 'Outstanding', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '91-180 Days', '180+ Days', 'Credit Limit', 'Utilization %']);
        
        foreach ($customer_data as $customer) {
            fputcsv($output, [
                $customer['name'],
                $customer['code'],
                number_format($customer['outstanding'], 2),
                number_format($customer['aging']['current'], 2),
                number_format($customer['aging']['1-30'], 2),
                number_format($customer['aging']['31-60'], 2),
                number_format($customer['aging']['61-90'], 2),
                number_format($customer['aging']['91-180'], 2),
                number_format($customer['aging']['180+'], 2),
                number_format($customer['credit_limit'], 2),
                number_format($customer['credit_utilization'], 2) . '%'
            ]);
        }
        fputcsv($output, []);
    }
    
    if ($report_type == 'supplier' || $report_type == 'both') {
        fputcsv($output, ['SUPPLIER OUTSTANDING']);
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Outstanding', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '91-180 Days', '180+ Days']);
        
        foreach ($supplier_data as $supplier) {
            fputcsv($output, [
                $supplier['name'],
                $supplier['code'],
                $supplier['company'],
                number_format($supplier['outstanding'], 2),
                number_format($supplier['aging']['current'], 2),
                number_format($supplier['aging']['1-30'], 2),
                number_format($supplier['aging']['31-60'], 2),
                number_format($supplier['aging']['61-90'], 2),
                number_format($supplier['aging']['91-180'], 2),
                number_format($supplier['aging']['180+'], 2)
            ]);
        }
    }
    
    fclose($output);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Chart JS -->
    <script src="assets/libs/apexcharts/apexcharts.min.js"></script>
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
                            <h4 class="mb-0 font-size-18">Outstanding Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Outstanding Report</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Error Message -->
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

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="?report_type=customer" class="btn btn-<?= $report_type == 'customer' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-account"></i> Customer Outstanding
                                    </a>
                                    <a href="?report_type=supplier" class="btn btn-<?= $report_type == 'supplier' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-truck"></i> Supplier Outstanding
                                    </a>
                                    <a href="?report_type=both" class="btn btn-<?= $report_type == 'both' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-chart-pie"></i> Both
                                    </a>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <?php if ($report_type == 'customer' || $report_type == 'both'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-account-group font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Customers</p>
                                        <h4><?= count($customer_data) ?></h4>
                                        <small class="text-muted">With outstanding</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="mdi mdi-currency-inr font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Customer Outstanding</p>
                                        <h4>₹<?= number_format($total_customer_outstanding, 2) ?></h4>
                                        <small class="text-muted">Total receivables</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type == 'supplier' || $report_type == 'both'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-truck-group font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Suppliers</p>
                                        <h4><?= count($supplier_data) ?></h4>
                                        <small class="text-muted">With outstanding</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-currency-inr font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Supplier Outstanding</p>
                                        <h4>₹<?= number_format($total_supplier_outstanding, 2) ?></h4>
                                        <small class="text-muted">Total payables</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type == 'both'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="mdi mdi-scale-balance font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Net Position</p>
                                        <h4 class="<?= ($total_customer_outstanding - $total_supplier_outstanding) >= 0 ? 'text-success' : 'text-danger' ?>">
                                            ₹<?= number_format($total_customer_outstanding - $total_supplier_outstanding, 2) ?>
                                        </h4>
                                        <small class="text-muted">Receivable - Payable</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Filter Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Report</h4>
                                <form method="GET" action="outstanding-report.php" class="row" id="filterForm">
                                    <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="as_on_date" class="form-label">As On Date</label>
                                            <input type="date" class="form-control" id="as_on_date" name="as_on_date" value="<?= htmlspecialchars($as_on_date) ?>">
                                        </div>
                                    </div>
                                    
                                    <?php if ($report_type == 'customer' || $report_type == 'both'): ?>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="customer_id" class="form-label">Customer</label>
                                            <select class="form-control" id="customer_id" name="customer_id">
                                                <option value="">All Customers</option>
                                                <?php foreach ($customers as $cust): ?>
                                                <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cust['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($report_type == 'supplier' || $report_type == 'both'): ?>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="supplier_id" class="form-label">Supplier</label>
                                            <select class="form-control" id="supplier_id" name="supplier_id">
                                                <option value="">All Suppliers</option>
                                                <?php foreach ($suppliers as $sup): ?>
                                                <option value="<?= $sup['id'] ?>" <?= $supplier_id == $sup['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sup['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-control" id="status" name="status">
                                                <option value="all" <?= $status == 'all' ? 'selected' : '' ?>>All</option>
                                                <option value="due" <?= $status == 'due' ? 'selected' : '' ?>>Due</option>
                                                <option value="overdue" <?= $status == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="min_amount" class="form-label">Min Amount (₹)</label>
                                            <input type="number" step="1000" class="form-control" id="min_amount" name="min_amount" value="<?= $min_amount > 0 ? $min_amount : '' ?>" placeholder="0">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, Code..." value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="outstanding-report.php?report_type=<?= $report_type ?>" class="btn btn-secondary">
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

                <!-- Aging Summary Chart -->
                <?php if ($report_type == 'customer' || $report_type == 'both'): ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Customer Aging Summary</h4>
                                <div id="customer-aging-chart" class="apex-charts" dir="ltr"></div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Bucket</th>
                                                <th class="text-end">Amount (₹)</th>
                                                <th class="text-end">% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $customer_total = $customer_aging_summary['current'] + 
                                                              $customer_aging_summary['1-30'] + 
                                                              $customer_aging_summary['31-60'] + 
                                                              $customer_aging_summary['61-90'] + 
                                                              $customer_aging_summary['91-180'] + 
                                                              $customer_aging_summary['180+'];
                                            ?>
                                            <tr>
                                                <td>Current</td>
                                                <td class="text-end">₹<?= number_format($customer_aging_summary['current'], 2) ?></td>
                                                <td class="text-end"><?= $customer_total > 0 ? number_format(($customer_aging_summary['current'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>1-30 Days</td>
                                                <td class="text-end">₹<?= number_format($customer_aging_summary['1-30'], 2) ?></td>
                                                <td class="text-end"><?= $customer_total > 0 ? number_format(($customer_aging_summary['1-30'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>31-60 Days</td>
                                                <td class="text-end">₹<?= number_format($customer_aging_summary['31-60'], 2) ?></td>
                                                <td class="text-end"><?= $customer_total > 0 ? number_format(($customer_aging_summary['31-60'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>61-90 Days</td>
                                                <td class="text-end">₹<?= number_format($customer_aging_summary['61-90'], 2) ?></td>
                                                <td class="text-end"><?= $customer_total > 0 ? number_format(($customer_aging_summary['61-90'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>91-180 Days</td>
                                                <td class="text-end">₹<?= number_format($customer_aging_summary['91-180'], 2) ?></td>
                                                <td class="text-end"><?= $customer_total > 0 ? number_format(($customer_aging_summary['91-180'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>180+ Days</td>
                                                <td class="text-end text-danger">₹<?= number_format($customer_aging_summary['180+'], 2) ?></td>
                                                <td class="text-end text-danger"><?= $customer_total > 0 ? number_format(($customer_aging_summary['180+'] / $customer_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($report_type == 'supplier' || $report_type == 'both'): ?>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Supplier Aging Summary</h4>
                                <div id="supplier-aging-chart" class="apex-charts" dir="ltr"></div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Bucket</th>
                                                <th class="text-end">Amount (₹)</th>
                                                <th class="text-end">% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $supplier_total = $supplier_aging_summary['current'] + 
                                                              $supplier_aging_summary['1-30'] + 
                                                              $supplier_aging_summary['31-60'] + 
                                                              $supplier_aging_summary['61-90'] + 
                                                              $supplier_aging_summary['91-180'] + 
                                                              $supplier_aging_summary['180+'];
                                            ?>
                                            <tr>
                                                <td>Current</td>
                                                <td class="text-end">₹<?= number_format($supplier_aging_summary['current'], 2) ?></td>
                                                <td class="text-end"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['current'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>1-30 Days</td>
                                                <td class="text-end">₹<?= number_format($supplier_aging_summary['1-30'], 2) ?></td>
                                                <td class="text-end"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['1-30'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>31-60 Days</td>
                                                <td class="text-end">₹<?= number_format($supplier_aging_summary['31-60'], 2) ?></td>
                                                <td class="text-end"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['31-60'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>61-90 Days</td>
                                                <td class="text-end">₹<?= number_format($supplier_aging_summary['61-90'], 2) ?></td>
                                                <td class="text-end"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['61-90'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>91-180 Days</td>
                                                <td class="text-end">₹<?= number_format($supplier_aging_summary['91-180'], 2) ?></td>
                                                <td class="text-end"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['91-180'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>180+ Days</td>
                                                <td class="text-end text-danger">₹<?= number_format($supplier_aging_summary['180+'], 2) ?></td>
                                                <td class="text-end text-danger"><?= $supplier_total > 0 ? number_format(($supplier_aging_summary['180+'] / $supplier_total * 100), 1) : 0 ?>%</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Customer Outstanding Table -->
                <?php if ($report_type == 'customer' || $report_type == 'both'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Customer Outstanding Details</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Customer</th>
                                                <th>Code</th>
                                                <th>Contact</th>
                                                <th class="text-end">Outstanding</th>
                                                <th class="text-end">Current</th>
                                                <th class="text-end">1-30</th>
                                                <th class="text-end">31-60</th>
                                                <th class="text-end">61-90</th>
                                                <th class="text-end">91-180</th>
                                                <th class="text-end">180+</th>
                                                <th class="text-end">Credit Limit</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($customer_data)): ?>
                                            <tr>
                                                <td colspan="13" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No customer outstanding found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($customer_data as $customer): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($customer['name']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($customer['code']) ?></td>
                                                    <td>
                                                        <small><?= htmlspecialchars($customer['phone']) ?></small>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong>₹<?= number_format($customer['outstanding'], 2) ?></strong>
                                                    </td>
                                                    <td class="text-end">₹<?= number_format($customer['aging']['current'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($customer['aging']['1-30'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($customer['aging']['31-60'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($customer['aging']['61-90'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($customer['aging']['91-180'], 2) ?></td>
                                                    <td class="text-end text-danger">₹<?= number_format($customer['aging']['180+'], 2) ?></td>
                                                    <td class="text-end">
                                                        ₹<?= number_format($customer['credit_limit'], 2) ?>
                                                        <?php if ($customer['credit_limit'] > 0): ?>
                                                        <br>
                                                        <small class="<?= $customer['credit_utilization'] > 80 ? 'text-danger' : 'text-success' ?>">
                                                            <?= number_format($customer['credit_utilization'], 1) ?>%
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $overdue_total = $customer['aging']['1-30'] + 
                                                                        $customer['aging']['31-60'] + 
                                                                        $customer['aging']['61-90'] + 
                                                                        $customer['aging']['91-180'] + 
                                                                        $customer['aging']['180+'];
                                                        
                                                        if ($overdue_total > 0):
                                                        ?>
                                                            <span class="badge bg-soft-danger text-danger">Overdue</span>
                                                        <?php elseif ($customer['outstanding'] > 0): ?>
                                                            <span class="badge bg-soft-warning text-warning">Due</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-success text-success">Clear</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="view-customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-soft-primary" title="View Customer Details">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Supplier Outstanding Table -->
                <?php if ($report_type == 'supplier' || $report_type == 'both'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Supplier Outstanding Details</h4>
                                
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Supplier</th>
                                                <th>Code</th>
                                                <th>Company</th>
                                                <th>Contact</th>
                                                <th class="text-end">Outstanding</th>
                                                <th class="text-end">Current</th>
                                                <th class="text-end">1-30</th>
                                                <th class="text-end">31-60</th>
                                                <th class="text-end">61-90</th>
                                                <th class="text-end">91-180</th>
                                                <th class="text-end">180+</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($supplier_data)): ?>
                                            <tr>
                                                <td colspan="13" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No supplier outstanding found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($supplier_data as $supplier): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($supplier['name']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($supplier['code']) ?></td>
                                                    <td><?= htmlspecialchars($supplier['company']) ?></td>
                                                    <td>
                                                        <small><?= htmlspecialchars($supplier['phone']) ?></small>
                                                    </td>
                                                    <td class="text-end">
                                                        <strong>₹<?= number_format($supplier['outstanding'], 2) ?></strong>
                                                    </td>
                                                    <td class="text-end">₹<?= number_format($supplier['aging']['current'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($supplier['aging']['1-30'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($supplier['aging']['31-60'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($supplier['aging']['61-90'], 2) ?></td>
                                                    <td class="text-end">₹<?= number_format($supplier['aging']['91-180'], 2) ?></td>
                                                    <td class="text-end text-danger">₹<?= number_format($supplier['aging']['180+'], 2) ?></td>
                                                    <td>
                                                        <?php
                                                        $overdue_total = $supplier['aging']['1-30'] + 
                                                                        $supplier['aging']['31-60'] + 
                                                                        $supplier['aging']['61-90'] + 
                                                                        $supplier['aging']['91-180'] + 
                                                                        $supplier['aging']['180+'];
                                                        
                                                        if ($overdue_total > 0):
                                                        ?>
                                                            <span class="badge bg-soft-danger text-danger">Overdue</span>
                                                        <?php elseif ($supplier['outstanding'] > 0): ?>
                                                            <span class="badge bg-soft-warning text-warning">Due</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-soft-success text-success">Clear</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="view-supplier.php?id=<?= $supplier['id'] ?>" class="btn btn-sm btn-soft-primary" title="View Supplier Details">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Customer Aging Chart
    <?php if ($report_type == 'customer' || $report_type == 'both'): ?>
    var customerAgingData = {
        current: <?= $customer_aging_summary['current'] ?>,
        days1_30: <?= $customer_aging_summary['1-30'] ?>,
        days31_60: <?= $customer_aging_summary['31-60'] ?>,
        days61_90: <?= $customer_aging_summary['61-90'] ?>,
        days91_180: <?= $customer_aging_summary['91-180'] ?>,
        days180: <?= $customer_aging_summary['180+'] ?>
    };

    var customerOptions = {
        chart: {
            height: 300,
            type: 'bar',
            toolbar: {
                show: false
            }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
                endingShape: 'rounded'
            },
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            show: true,
            width: 2,
            colors: ['transparent']
        },
        series: [{
            name: 'Outstanding Amount',
            data: [
                customerAgingData.current,
                customerAgingData.days1_30,
                customerAgingData.days31_60,
                customerAgingData.days61_90,
                customerAgingData.days91_180,
                customerAgingData.days180
            ]
        }],
        xaxis: {
            categories: ['Current', '1-30 Days', '31-60 Days', '61-90 Days', '91-180 Days', '180+ Days'],
        },
        yaxis: {
            title: {
                text: 'Amount (₹)'
            },
            labels: {
                formatter: function(val) {
                    return '₹' + val.toFixed(0);
                }
            }
        },
        fill: {
            opacity: 1,
            colors: ['#34c38f', '#50a5f1', '#f1b44c', '#f46a6a', '#f46a6a', '#f46a6a']
        },
        tooltip: {
            y: {
                formatter: function(val) {
                    return '₹' + val.toFixed(2);
                }
            }
        }
    };

    var customerChart = new ApexCharts(document.querySelector("#customer-aging-chart"), customerOptions);
    customerChart.render();
    <?php endif; ?>

    // Supplier Aging Chart
    <?php if ($report_type == 'supplier' || $report_type == 'both'): ?>
    var supplierAgingData = {
        current: <?= $supplier_aging_summary['current'] ?>,
        days1_30: <?= $supplier_aging_summary['1-30'] ?>,
        days31_60: <?= $supplier_aging_summary['31-60'] ?>,
        days61_90: <?= $supplier_aging_summary['61-90'] ?>,
        days91_180: <?= $supplier_aging_summary['91-180'] ?>,
        days180: <?= $supplier_aging_summary['180+'] ?>
    };

    var supplierOptions = {
        chart: {
            height: 300,
            type: 'bar',
            toolbar: {
                show: false
            }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
                endingShape: 'rounded'
            },
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            show: true,
            width: 2,
            colors: ['transparent']
        },
        series: [{
            name: 'Outstanding Amount',
            data: [
                supplierAgingData.current,
                supplierAgingData.days1_30,
                supplierAgingData.days31_60,
                supplierAgingData.days61_90,
                supplierAgingData.days91_180,
                supplierAgingData.days180
            ]
        }],
        xaxis: {
            categories: ['Current', '1-30 Days', '31-60 Days', '61-90 Days', '91-180 Days', '180+ Days'],
        },
        yaxis: {
            title: {
                text: 'Amount (₹)'
            },
            labels: {
                formatter: function(val) {
                    return '₹' + val.toFixed(0);
                }
            }
        },
        fill: {
            opacity: 1,
            colors: ['#34c38f', '#50a5f1', '#f1b44c', '#f46a6a', '#f46a6a', '#f46a6a']
        },
        tooltip: {
            y: {
                formatter: function(val) {
                    return '₹' + val.toFixed(2);
                }
            }
        }
    };

    var supplierChart = new ApexCharts(document.querySelector("#supplier-aging-chart"), supplierOptions);
    supplierChart.render();
    <?php endif; ?>

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

<style>
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, .apex-charts {
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

/* Aging bucket colors */
.bucket-current {
    background-color: #d4edda;
}
.bucket-30 {
    background-color: #fff3cd;
}
.bucket-60 {
    background-color: #ffe5b4;
}
.bucket-90 {
    background-color: #ffc107;
}
.bucket-180 {
    background-color: #fd7e14;
}
.bucket-180plus {
    background-color: #dc3545;
    color: white;
}

/* Button styles */
.btn-soft-primary {
    transition: all 0.3s;
}

.btn-soft-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(85, 110, 230, 0.3);
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
</style>

</body>
</html>