<?php
date_default_timezone_set('Asia/Kolkata');


// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}



// Get dashboard statistics
try {
    // Total customers
    $customerStmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
    $totalCustomers = $customerStmt->fetch()['total'] ?? 0;
    
    // Total suppliers
    $supplierStmt = $pdo->query("SELECT COUNT(*) as total FROM suppliers");
    $totalSuppliers = $supplierStmt->fetch()['total'] ?? 0;
    
    // Total products
    $productStmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $totalProducts = $productStmt->fetch()['total'] ?? 0;
    
    // Total invoices
    $invoiceStmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as amount FROM invoices");
    $invoiceData = $invoiceStmt->fetch();
    $totalInvoices = $invoiceData['total'] ?? 0;
    $totalInvoiceAmount = $invoiceData['amount'] ?? 0;
    
    // Recent customers
    $recentCustomersStmt = $pdo->query("SELECT id, name, customer_code, created_at FROM customers ORDER BY created_at DESC LIMIT 5");
    $recentCustomers = $recentCustomersStmt->fetchAll();
    
    // Recent suppliers
    $recentSuppliersStmt = $pdo->query("SELECT id, name, supplier_code, created_at FROM suppliers ORDER BY created_at DESC LIMIT 5");
    $recentSuppliers = $recentSuppliersStmt->fetchAll();
    
    // Recent invoices
    $recentInvoicesStmt = $pdo->query("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status, c.name as customer_name 
        FROM invoices i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        ORDER BY i.created_at DESC 
        LIMIT 5
    ");
    $recentInvoices = $recentInvoicesStmt->fetchAll();
    
    // Low stock products
    $lowStockStmt = $pdo->query("
        SELECT id, name, current_stock, reorder_level 
        FROM products 
        WHERE current_stock <= reorder_level AND reorder_level > 0 
        ORDER BY current_stock ASC 
        LIMIT 5
    ");
    $lowStockProducts = $lowStockStmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // Set default values if queries fail
    $totalCustomers = 0;
    $totalSuppliers = 0;
    $totalProducts = 0;
    $totalInvoices = 0;
    $totalInvoiceAmount = 0;
    $recentCustomers = [];
    $recentSuppliers = [];
    $recentInvoices = [];
    $lowStockProducts = [];
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
                            <h4 class="mb-0 font-size-18">Dashboard</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Overview</li>
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
                                        <h4 class="mb-2">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>!</h4>
                                        <p class="mb-0">Here's what's happening with your business today.</p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <img src="assets/images/profile-img.png" alt="" height="80">
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
                                    <div id="total-customers-chart" data-colors='["--bs-primary"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalCustomers) ?></h4>
                                    <p class="text-muted mb-0">Total Customers</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 2.5%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-suppliers-chart" data-colors='["--bs-info"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalSuppliers) ?></h4>
                                    <p class="text-muted mb-0">Total Suppliers</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 1.8%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-products-chart" data-colors='["--bs-success"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalProducts) ?></h4>
                                    <p class="text-muted mb-0">Total Products</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-success me-1"><i class="mdi mdi-arrow-up-bold"></i> 3.2%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="float-end mt-2">
                                    <div id="total-invoices-chart" data-colors='["--bs-warning"]' class="apex-charts" dir="ltr"></div>
                                </div>
                                <div>
                                    <h4 class="mb-1 mt-1"><?= number_format($totalInvoices) ?></h4>
                                    <p class="text-muted mb-0">Total Invoices</p>
                                </div>
                                <p class="text-muted mt-3 mb-0">
                                    <span class="text-danger me-1"><i class="mdi mdi-arrow-down-bold"></i> 0.5%</span> since last month
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Revenue and Charts Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Revenue Overview</h4>
                                
                                <div class="row text-center mt-4">
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">₹<?= number_format($totalInvoiceAmount, 2) ?></h5>
                                        <p class="text-muted text-truncate">Total Revenue</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">₹<?= number_format($totalInvoiceAmount * 0.7, 2) ?></h5>
                                        <p class="text-muted text-truncate">This Month</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18"><?= number_format($totalCustomers) ?></h5>
                                        <p class="text-muted text-truncate">Active Customers</p>
                                    </div>
                                </div>

                                <div id="revenue-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Quick Actions</h4>

                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-customer.php" class="btn btn-primary btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-account-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Customer</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-supplier.php" class="btn btn-success btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-truck-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Supplier</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="add-product.php" class="btn btn-info btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-package-variant-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">Add Product</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center mb-4">
                                            <a href="create-invoice.php" class="btn btn-warning btn-lg rounded-circle" style="width: 60px; height: 60px; padding: 0; line-height: 60px;">
                                                <i class="mdi mdi-file-document-plus font-size-24"></i>
                                            </a>
                                            <p class="mt-2 mb-0">New Invoice</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Activities Row -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Customers</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Joined</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentCustomers)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No recent customers</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentCustomers as $customer): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($customer['name']) ?></td>
                                                    <td><?= htmlspecialchars($customer['customer_code']) ?></td>
                                                    <td><?= date('d M Y', strtotime($customer['created_at'])) ?></td>
                                                    <td>
                                                        <a href="view-customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-customers.php" class="text-primary">View all customers <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Suppliers</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Joined</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentSuppliers)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No recent suppliers</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentSuppliers as $supplier): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($supplier['name']) ?></td>
                                                    <td><?= htmlspecialchars($supplier['supplier_code']) ?></td>
                                                    <td><?= date('d M Y', strtotime($supplier['created_at'])) ?></td>
                                                    <td>
                                                        <a href="view-supplier.php?id=<?= $supplier['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-suppliers.php" class="text-primary">View all suppliers <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <!-- Recent Invoices and Low Stock Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Invoices</h4>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Customer</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recentInvoices)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No recent invoices</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recentInvoices as $invoice): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                    <td><?= htmlspecialchars($invoice['customer_name'] ?? 'N/A') ?></td>
                                                    <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                    <td>₹<?= number_format($invoice['total_amount'], 2) ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        switch($invoice['status']) {
                                                            case 'paid':
                                                                $statusClass = 'success';
                                                                break;
                                                            case 'partially_paid':
                                                                $statusClass = 'warning';
                                                                break;
                                                            case 'overdue':
                                                                $statusClass = 'danger';
                                                                break;
                                                            default:
                                                                $statusClass = 'secondary';
                                                        }
                                                        ?>
                                                        <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-soft-primary">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="manage-invoices.php" class="text-primary">View all invoices <i class="mdi mdi-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Low Stock Alert</h4>

                                <?php if (empty($lowStockProducts)): ?>
                                    <div class="text-center py-4">
                                        <i class="mdi mdi-check-circle text-success" style="font-size: 48px;"></i>
                                        <h5 class="mt-3">All products are well stocked!</h5>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Current Stock</th>
                                                    <th>Reorder Level</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lowStockProducts as $product): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($product['name']) ?></td>
                                                    <td class="text-danger font-weight-bold"><?= number_format($product['current_stock']) ?></td>
                                                    <td><?= number_format($product['reorder_level']) ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-danger text-danger">
                                                            <i class="mdi mdi-alert"></i> Low Stock
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center mt-3">
                                        <a href="manage-products.php" class="text-primary">Manage products <i class="mdi mdi-arrow-right"></i></a>
                                    </div>
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

<!-- JAVASCRIPT -->
<?php include('includes/scripts.php'); ?>

<!-- Chart JS -->
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Revenue Chart
    var options = {
        chart: {
            height: 280,
            type: 'area',
            toolbar: {
                show: false
            }
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            curve: 'smooth',
            width: 3
        },
        series: [{
            name: 'Revenue',
            data: [31, 40, 28, 51, 42, 109, 100]
        }],
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
        },
        colors: ['#556ee6'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.3
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#revenue-chart"), options);
    chart.render();

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

    // Generate mini charts for each stat card
    generateMiniChart('#total-customers-chart', '#556ee6', [20, 25, 30, 28, 32, 35, 40]);
    generateMiniChart('#total-suppliers-chart', '#34c38f', [15, 18, 20, 22, 25, 28, 30]);
    generateMiniChart('#total-products-chart', '#50a5f1', [35, 40, 38, 42, 45, 48, 52]);
    generateMiniChart('#total-invoices-chart', '#f46a6a', [25, 22, 28, 24, 30, 28, 32]);
</script>

</body>
</html>