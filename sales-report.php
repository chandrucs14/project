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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'daily'; // daily, monthly, yearly, product, customer, category
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, paid, pending, overdue
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle AJAX request for report data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_report') {
    header('Content-Type: application/json');
    
    try {
        $report_type = $_GET['report_type'] ?? 'daily';
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        $customer_id = $_GET['customer_id'] ?? '';
        $product_id = $_GET['product_id'] ?? '';
        $category_id = $_GET['category_id'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $search = $_GET['search'] ?? '';
        
        // Get report data based on type
        $report_data = [];
        $summary = [];
        $chart_data = [];
        
        switch ($report_type) {
            case 'daily':
                $result = getDailySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'monthly':
                $result = getMonthlySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'yearly':
                $result = getYearlySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'product':
                $result = getProductSalesReport($pdo, $date_from, $date_to, $product_id, $category_id, $status);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'customer':
                $result = getCustomerSalesReport($pdo, $date_from, $date_to, $customer_id, $status);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'category':
                $result = getCategorySalesReport($pdo, $date_from, $date_to, $category_id, $status);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
        }
        
        // Generate HTML for summary cards
        ob_start();
        ?>
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 me-3">
                                <div class="avatar-sm">
                                    <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                        <i class="mdi mdi-cash-multiple font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Sales</p>
                                <h4>₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= $summary['total_transactions'] ?? 0 ?> transactions</small>
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
                                        <i class="mdi mdi-cash font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Cash Sales</p>
                                <h4>₹<?= number_format($summary['cash_sales'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= $summary['cash_count'] ?? 0 ?> transactions</small>
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
                                    <span class="avatar-title bg-soft-info text-info rounded-circle">
                                        <i class="mdi mdi-credit-card font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Credit Sales</p>
                                <h4>₹<?= number_format($summary['credit_sales'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= $summary['credit_count'] ?? 0 ?> transactions</small>
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
                                        <i class="mdi mdi-trending-up font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Avg. Transaction</p>
                                <h4>₹<?= number_format($summary['avg_transaction'] ?? 0, 2) ?></h4>
                                <small class="text-muted">Per sale</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($report_type == 'daily' || $report_type == 'monthly' || $report_type == 'yearly'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales Trend</h4>
                        <div id="sales-trend-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'product'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Products</h4>
                        <div id="product-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'customer'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Customers</h4>
                        <div id="customer-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'category'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales by Category</h4>
                        <div id="category-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales Details</h4>
                        <div class="table-responsive">
                            <table class="table table-centered table-nowrap mb-0">
                                <thead class="thead-light">
                                    <?php if ($report_type == 'daily'): ?>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Sales</th>
                                        <th class="text-end">Credit Sales</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Sales</th>
                                    </tr>
                                    <?php elseif ($report_type == 'monthly'): ?>
                                    <tr>
                                        <th>Month</th>
                                        <th>Year</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Sales</th>
                                        <th class="text-end">Credit Sales</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Sales</th>
                                    </tr>
                                    <?php elseif ($report_type == 'yearly'): ?>
                                    <tr>
                                        <th>Year</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Sales</th>
                                        <th class="text-end">Credit Sales</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Sales</th>
                                    </tr>
                                    <?php elseif ($report_type == 'product'): ?>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Unit</th>
                                        <th class="text-end">Quantity Sold</th>
                                        <th class="text-end">Avg. Price</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Net Sales</th>
                                    </tr>
                                    <?php elseif ($report_type == 'customer'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Phone</th>
                                        <th class="text-end">Transactions</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Sales</th>
                                        <th class="text-end">Avg. per Transaction</th>
                                    </tr>
                                    <?php elseif ($report_type == 'category'): ?>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Products</th>
                                        <th class="text-end">Transactions</th>
                                        <th class="text-end">Quantity Sold</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Net Sales</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php if (empty($report_data)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                            <p class="mt-2">No sales data found for selected filters</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type == 'daily'): ?>
                                            <td><?= date('d M Y', strtotime($row['sale_date'])) ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'monthly'): ?>
                                            <td><?= DateTime::createFromFormat('!m', $row['sale_month'])->format('F') ?></td>
                                            <td><?= $row['sale_year'] ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'yearly'): ?>
                                            <td><?= $row['sale_year'] ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'product'): ?>
                                            <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($row['unit']) ?></td>
                                            <td class="text-end"><?= number_format($row['quantity_sold'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_price'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'customer'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code']) ?></td>
                                            <td><?= htmlspecialchars($row['phone']) ?></td>
                                            <td class="text-end"><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_transaction'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'category'): ?>
                                            <td><strong><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></strong></td>
                                            <td class="text-end"><?= $row['product_count'] ?></td>
                                            <td class="text-end"><?= $row['transaction_count'] ?></td>
                                            <td class="text-end"><?= number_format($row['quantity_sold'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                            <?php endif; ?>
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
        <?php
        $html = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'summary' => $summary,
            'chart_data' => $chart_data,
            'report_type' => $report_type
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Function to get daily sales report
function getDailySalesReport($pdo, $date_from, $date_to, $customer_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            DATE(i.invoice_date) as sale_date,
            COUNT(DISTINCT i.id) as transaction_count,
            SUM(CASE WHEN i.payment_type = 'cash' THEN i.total_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN i.payment_type IN ('credit', 'bank_transfer', 'cheque', 'online') THEN i.total_amount ELSE 0 END) as credit_sales,
            SUM(i.total_amount) as total_sales,
            SUM(i.gst_total) as total_gst,
            SUM(i.discount_amount) as total_discount,
            SUM(i.subtotal) as net_sales
        FROM invoices i
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND i.customer_id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    if (!empty($search)) {
        $query .= " AND (i.invoice_number LIKE :search OR i.notes LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY DATE(i.invoice_date) ORDER BY sale_date";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'cash_sales' => 0,
        'credit_sales' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_sales' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['cash_sales'] += $row['cash_sales'];
        $summary['credit_sales'] += $row['credit_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = date('d M', strtotime($row['sale_date']));
        $chart_data['cash'][] = $row['cash_sales'];
        $chart_data['credit'][] = $row['credit_sales'];
        $chart_data['total'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get monthly sales report
function getMonthlySalesReport($pdo, $date_from, $date_to, $customer_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            MONTH(i.invoice_date) as sale_month,
            YEAR(i.invoice_date) as sale_year,
            COUNT(DISTINCT i.id) as transaction_count,
            SUM(CASE WHEN i.payment_type = 'cash' THEN i.total_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN i.payment_type IN ('credit', 'bank_transfer', 'cheque', 'online') THEN i.total_amount ELSE 0 END) as credit_sales,
            SUM(i.total_amount) as total_sales,
            SUM(i.gst_total) as total_gst,
            SUM(i.discount_amount) as total_discount,
            SUM(i.subtotal) as net_sales
        FROM invoices i
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND i.customer_id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY YEAR(i.invoice_date), MONTH(i.invoice_date) ORDER BY sale_year, sale_month";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'cash_sales' => 0,
        'credit_sales' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_sales' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['cash_sales'] += $row['cash_sales'];
        $summary['credit_sales'] += $row['credit_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = DateTime::createFromFormat('!m', $row['sale_month'])->format('M') . ' ' . $row['sale_year'];
        $chart_data['cash'][] = $row['cash_sales'];
        $chart_data['credit'][] = $row['credit_sales'];
        $chart_data['total'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get yearly sales report
function getYearlySalesReport($pdo, $date_from, $date_to, $customer_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            YEAR(i.invoice_date) as sale_year,
            COUNT(DISTINCT i.id) as transaction_count,
            SUM(CASE WHEN i.payment_type = 'cash' THEN i.total_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN i.payment_type IN ('credit', 'bank_transfer', 'cheque', 'online') THEN i.total_amount ELSE 0 END) as credit_sales,
            SUM(i.total_amount) as total_sales,
            SUM(i.gst_total) as total_gst,
            SUM(i.discount_amount) as total_discount,
            SUM(i.subtotal) as net_sales
        FROM invoices i
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND i.customer_id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY YEAR(i.invoice_date) ORDER BY sale_year";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'cash_sales' => 0,
        'credit_sales' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_sales' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['cash_sales'] += $row['cash_sales'];
        $summary['credit_sales'] += $row['credit_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = $row['sale_year'];
        $chart_data['cash'][] = $row['cash_sales'];
        $chart_data['credit'][] = $row['credit_sales'];
        $chart_data['total'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get product sales report
function getProductSalesReport($pdo, $date_from, $date_to, $product_id = '', $category_id = '', $status = 'all') {
    $query = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            c.name as category_name,
            p.unit,
            COUNT(DISTINCT ii.invoice_id) as transaction_count,
            SUM(ii.quantity) as quantity_sold,
            AVG(ii.unit_price) as avg_price,
            SUM(ii.total_price) as total_sales,
            SUM(ii.gst_amount) as total_gst,
            SUM(ii.total_price) as net_sales
        FROM invoice_items ii
        JOIN products p ON ii.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($product_id)) {
        $query .= " AND p.id = :product_id";
        $params[':product_id'] = $product_id;
    }
    
    if (!empty($category_id)) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY p.id, p.name, c.name, p.unit ORDER BY total_sales DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'total_gst' => 0,
        'net_sales' => 0,
        'total_quantity' => 0,
        'total_transactions' => 0,
        'unique_products' => count($data),
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_quantity'] += $row['quantity_sold'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'products' => [],
        'sales' => []
    ];
    
    $top_products = array_slice($data, 0, 10);
    foreach ($top_products as $row) {
        $chart_data['products'][] = strlen($row['product_name']) > 20 ? substr($row['product_name'], 0, 20) . '...' : $row['product_name'];
        $chart_data['sales'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get customer sales report
function getCustomerSalesReport($pdo, $date_from, $date_to, $customer_id = '', $status = 'all') {
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            COUNT(DISTINCT i.id) as transaction_count,
            SUM(i.total_amount) as total_sales,
            SUM(i.gst_total) as total_gst,
            SUM(i.discount_amount) as total_discount,
            SUM(i.subtotal) as net_sales,
            AVG(i.total_amount) as avg_transaction,
            MAX(i.total_amount) as max_transaction,
            MIN(i.total_amount) as min_transaction
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone ORDER BY total_sales DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_sales' => 0,
        'total_transactions' => 0,
        'unique_customers' => count($data),
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'customers' => [],
        'sales' => []
    ];
    
    $top_customers = array_slice($data, 0, 10);
    foreach ($top_customers as $row) {
        $chart_data['customers'][] = strlen($row['customer_name']) > 20 ? substr($row['customer_name'], 0, 20) . '...' : $row['customer_name'];
        $chart_data['sales'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get category sales report
function getCategorySalesReport($pdo, $date_from, $date_to, $category_id = '', $status = 'all') {
    $query = "
        SELECT 
            c.id as category_id,
            c.name as category_name,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT ii.invoice_id) as transaction_count,
            SUM(ii.quantity) as quantity_sold,
            SUM(ii.total_price) as total_sales,
            SUM(ii.gst_amount) as total_gst,
            SUM(ii.total_price) as net_sales
        FROM invoice_items ii
        JOIN products p ON ii.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE i.invoice_date BETWEEN :date_from AND :date_to
        AND i.status != 'cancelled'
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($category_id)) {
        $query .= " AND c.id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND i.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY c.id, c.name ORDER BY total_sales DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_sales' => 0,
        'total_gst' => 0,
        'net_sales' => 0,
        'total_quantity' => 0,
        'total_transactions' => 0,
        'unique_categories' => count($data),
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['net_sales'] += $row['net_sales'];
        $summary['total_quantity'] += $row['quantity_sold'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_sales'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'sales' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = $row['category_name'] ?? 'Uncategorized';
        $chart_data['sales'][] = $row['total_sales'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Get customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
}

// Get products for dropdown
try {
    $productsStmt = $pdo->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
    error_log("Error fetching products: " . $e->getMessage());
}

// Get categories for dropdown
try {
    $categoriesStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
    error_log("Error fetching categories: " . $e->getMessage());
}

// Get initial data for page load
$report_data = [];
$summary = [];
$chart_data = [];

switch ($report_type) {
    case 'daily':
        $result = getDailySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'monthly':
        $result = getMonthlySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'yearly':
        $result = getYearlySalesReport($pdo, $date_from, $date_to, $customer_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'product':
        $result = getProductSalesReport($pdo, $date_from, $date_to, $product_id, $category_id, $status);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'customer':
        $result = getCustomerSalesReport($pdo, $date_from, $date_to, $customer_id, $status);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'category':
        $result = getCategorySalesReport($pdo, $date_from, $date_to, $category_id, $status);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($report_data, $report_type, $date_from, $date_to);
    exit();
}

// Export function
function exportToCSV($data, $report_type, $date_from, $date_to) {
    $filename = 'sales_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Sales Report - ' . ucfirst($report_type)]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    fputcsv($output, []);
    
    // Add headers based on report type
    if ($report_type == 'daily') {
        fputcsv($output, ['Date', 'Transactions', 'Cash Sales', 'Credit Sales', 'Total Sales', 'GST', 'Discount', 'Net Sales']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['sale_date'],
                $row['transaction_count'],
                number_format($row['cash_sales'], 2),
                number_format($row['credit_sales'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_sales'], 2)
            ]);
        }
    } elseif ($report_type == 'monthly') {
        fputcsv($output, ['Month', 'Year', 'Transactions', 'Cash Sales', 'Credit Sales', 'Total Sales', 'GST', 'Discount', 'Net Sales']);
        foreach ($data as $row) {
            fputcsv($output, [
                DateTime::createFromFormat('!m', $row['sale_month'])->format('F'),
                $row['sale_year'],
                $row['transaction_count'],
                number_format($row['cash_sales'], 2),
                number_format($row['credit_sales'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_sales'], 2)
            ]);
        }
    } elseif ($report_type == 'yearly') {
        fputcsv($output, ['Year', 'Transactions', 'Cash Sales', 'Credit Sales', 'Total Sales', 'GST', 'Discount', 'Net Sales']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['sale_year'],
                $row['transaction_count'],
                number_format($row['cash_sales'], 2),
                number_format($row['credit_sales'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_sales'], 2)
            ]);
        }
    } elseif ($report_type == 'product') {
        fputcsv($output, ['Product', 'Category', 'Unit', 'Quantity Sold', 'Avg Price', 'Total Sales', 'GST', 'Net Sales']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['unit'],
                number_format($row['quantity_sold'], 2),
                number_format($row['avg_price'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['net_sales'], 2)
            ]);
        }
    } elseif ($report_type == 'customer') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Transactions', 'Total Sales', 'GST', 'Discount', 'Net Sales', 'Avg per Transaction']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'],
                $row['customer_code'],
                $row['phone'],
                $row['transaction_count'],
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_sales'], 2),
                number_format($row['avg_transaction'], 2)
            ]);
        }
    } elseif ($report_type == 'category') {
        fputcsv($output, ['Category', 'Products', 'Transactions', 'Quantity Sold', 'Total Sales', 'GST', 'Net Sales']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['category_name'] ?? 'Uncategorized',
                $row['product_count'],
                $row['transaction_count'],
                number_format($row['quantity_sold'], 2),
                number_format($row['total_sales'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['net_sales'], 2)
            ]);
        }
    }
    
    fclose($output);
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
                            <h4 class="mb-0 font-size-18">Sales Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Sales Report</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-<?= $report_type == 'daily' ? 'primary' : 'outline-primary' ?>" id="btnDaily">
                                        <i class="mdi mdi-calendar-today"></i> Daily
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'monthly' ? 'primary' : 'outline-primary' ?>" id="btnMonthly">
                                        <i class="mdi mdi-calendar-month"></i> Monthly
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'yearly' ? 'primary' : 'outline-primary' ?>" id="btnYearly">
                                        <i class="mdi mdi-calendar-clock"></i> Yearly
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'product' ? 'primary' : 'outline-primary' ?>" id="btnProduct">
                                        <i class="mdi mdi-package-variant"></i> By Product
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'customer' ? 'primary' : 'outline-primary' ?>" id="btnCustomer">
                                        <i class="mdi mdi-account-group"></i> By Customer
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'category' ? 'primary' : 'outline-primary' ?>" id="btnCategory">
                                        <i class="mdi mdi-shape"></i> By Category
                                    </button>
                                    <button type="button" class="btn btn-success" id="btnExport">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Filter Report</h4>
                                <form method="GET" action="sales-report.php" class="row" id="filterForm">
                                    <input type="hidden" name="report_type" id="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="date_from" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="date_to" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2" id="customer_filter" style="<?= ($report_type == 'product' || $report_type == 'category') ? 'display: block;' : 'display: none;' ?>">
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
                                    
                                    <div class="col-md-2" id="product_filter" style="<?= ($report_type == 'customer' || $report_type == 'category') ? 'display: block;' : 'display: none;' ?>">
                                        <div class="mb-3">
                                            <label for="product_id" class="form-label">Product</label>
                                            <select class="form-control" id="product_id" name="product_id">
                                                <option value="">All Products</option>
                                                <?php foreach ($products as $prod): ?>
                                                <option value="<?= $prod['id'] ?>" <?= $product_id == $prod['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($prod['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2" id="category_filter" style="<?= ($report_type == 'product' || $report_type == 'customer') ? 'display: block;' : 'display: none;' ?>">
                                        <div class="mb-3">
                                            <label for="category_id" class="form-label">Category</label>
                                            <select class="form-control" id="category_id" name="category_id">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cat['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-control" id="status" name="status">
                                                <option value="all" <?= $status == 'all' ? 'selected' : '' ?>>All</option>
                                                <option value="paid" <?= $status == 'paid' ? 'selected' : '' ?>>Paid</option>
                                                <option value="sent" <?= $status == 'sent' ? 'selected' : '' ?>>Pending</option>
                                                <option value="overdue" <?= $status == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="Invoice #, Notes..." value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="button" class="btn btn-primary me-2" id="applyFilterBtn">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <button type="button" class="btn btn-secondary" id="resetFilterBtn">
                                                    <i class="mdi mdi-refresh"></i> Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading Indicator -->
                <div id="loadingIndicator" class="text-center py-4" style="display: none;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading report data...</p>
                </div>

                <!-- Report Content Container -->
                <div id="reportContent">
                    <!-- Summary Cards -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                    <i class="mdi mdi-cash-multiple font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Sales</p>
                                            <h4>₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= $summary['total_transactions'] ?? 0 ?> transactions</small>
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
                                                    <i class="mdi mdi-cash font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Cash Sales</p>
                                            <h4>₹<?= number_format($summary['cash_sales'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= $summary['cash_count'] ?? 0 ?> transactions</small>
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
                                                <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                    <i class="mdi mdi-credit-card font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Credit Sales</p>
                                            <h4>₹<?= number_format($summary['credit_sales'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= $summary['credit_count'] ?? 0 ?> transactions</small>
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
                                                    <i class="mdi mdi-trending-up font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Avg. Transaction</p>
                                            <h4>₹<?= number_format($summary['avg_transaction'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Per sale</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($report_type == 'daily' || $report_type == 'monthly' || $report_type == 'yearly'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Sales Trend</h4>
                                    <div id="sales-trend-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'product'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Products</h4>
                                    <div id="product-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'customer'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Customers</h4>
                                    <div id="customer-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'category'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Sales by Category</h4>
                                    <div id="category-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Sales Details</h4>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <?php if ($report_type == 'daily'): ?>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Sales</th>
                                                    <th class="text-end">Credit Sales</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Sales</th>
                                                </tr>
                                                <?php elseif ($report_type == 'monthly'): ?>
                                                <tr>
                                                    <th>Month</th>
                                                    <th>Year</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Sales</th>
                                                    <th class="text-end">Credit Sales</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Sales</th>
                                                </tr>
                                                <?php elseif ($report_type == 'yearly'): ?>
                                                <tr>
                                                    <th>Year</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Sales</th>
                                                    <th class="text-end">Credit Sales</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Sales</th>
                                                </tr>
                                                <?php elseif ($report_type == 'product'): ?>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Unit</th>
                                                    <th class="text-end">Quantity Sold</th>
                                                    <th class="text-end">Avg. Price</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Net Sales</th>
                                                </tr>
                                                <?php elseif ($report_type == 'customer'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Transactions</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Sales</th>
                                                    <th class="text-end">Avg. per Transaction</th>
                                                </tr>
                                                <?php elseif ($report_type == 'category'): ?>
                                                <tr>
                                                    <th>Category</th>
                                                    <th class="text-end">Products</th>
                                                    <th class="text-end">Transactions</th>
                                                    <th class="text-end">Quantity Sold</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Net Sales</th>
                                                </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($report_data)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                        <p class="mt-2">No sales data found for selected filters</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_data as $row): ?>
                                                    <tr>
                                                        <?php if ($report_type == 'daily'): ?>
                                                        <td><?= date('d M Y', strtotime($row['sale_date'])) ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'monthly'): ?>
                                                        <td><?= DateTime::createFromFormat('!m', $row['sale_month'])->format('F') ?></td>
                                                        <td><?= $row['sale_year'] ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'yearly'): ?>
                                                        <td><?= $row['sale_year'] ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_sales'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_sales'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'product'): ?>
                                                        <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                                                        <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                        <td><?= htmlspecialchars($row['unit']) ?></td>
                                                        <td class="text-end"><?= number_format($row['quantity_sold'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_price'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'customer'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name']) ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code']) ?></td>
                                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                                        <td class="text-end"><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_transaction'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'category'): ?>
                                                        <td><strong><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></strong></td>
                                                        <td class="text-end"><?= $row['product_count'] ?></td>
                                                        <td class="text-end"><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end"><?= number_format($row['quantity_sold'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_sales'], 2) ?></td>
                                                        <?php endif; ?>
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

    // Chart instances
    var salesTrendChart = null;
    var productChart = null;
    var customerChart = null;
    var categoryChart = null;

    // Initialize charts on page load
    <?php if ($report_type == 'daily' || $report_type == 'monthly' || $report_type == 'yearly'): ?>
    initSalesTrendChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'product'): ?>
    initProductChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'customer'): ?>
    initCustomerChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'category'): ?>
    initCategoryChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>

    // Initialize sales trend chart
    function initSalesTrendChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'line',
                toolbar: {
                    show: true
                },
                animations: {
                    enabled: true
                }
            },
            stroke: {
                curve: 'smooth',
                width: 3
            },
            series: [
                {
                    name: 'Cash Sales',
                    type: 'column',
                    data: data.cash || []
                },
                {
                    name: 'Credit Sales',
                    type: 'column',
                    data: data.credit || []
                },
                {
                    name: 'Total Sales',
                    type: 'line',
                    data: data.total || []
                }
            ],
            xaxis: {
                categories: data.categories || [],
                title: {
                    text: 'Period'
                }
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
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            },
            legend: {
                position: 'top'
            },
            colors: ['#34c38f', '#f46a6a', '#556ee6'],
            fill: {
                opacity: [1, 1, 1]
            },
            stroke: {
                width: [0, 0, 3]
            }
        };

        if (salesTrendChart) {
            salesTrendChart.destroy();
        }
        salesTrendChart = new ApexCharts(document.querySelector("#sales-trend-chart"), options);
        salesTrendChart.render();
    }

    // Initialize product chart
    function initProductChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'bar',
                toolbar: {
                    show: true
                }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '50%',
                    endingShape: 'rounded'
                },
            },
            dataLabels: {
                enabled: false
            },
            series: [{
                name: 'Sales',
                data: data.sales || []
            }],
            xaxis: {
                categories: data.products || [],
                title: {
                    text: 'Sales Amount (₹)'
                }
            },
            yaxis: {
                title: {
                    text: 'Product'
                }
            },
            colors: ['#556ee6'],
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };

        if (productChart) {
            productChart.destroy();
        }
        productChart = new ApexCharts(document.querySelector("#product-chart"), options);
        productChart.render();
    }

    // Initialize customer chart
    function initCustomerChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'bar',
                toolbar: {
                    show: true
                }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '50%',
                    endingShape: 'rounded'
                },
            },
            dataLabels: {
                enabled: false
            },
            series: [{
                name: 'Sales',
                data: data.sales || []
            }],
            xaxis: {
                categories: data.customers || [],
                title: {
                    text: 'Sales Amount (₹)'
                }
            },
            yaxis: {
                title: {
                    text: 'Customer'
                }
            },
            colors: ['#34c38f'],
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };

        if (customerChart) {
            customerChart.destroy();
        }
        customerChart = new ApexCharts(document.querySelector("#customer-chart"), options);
        customerChart.render();
    }

    // Initialize category chart
    function initCategoryChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'pie'
            },
            series: data.sales || [],
            labels: data.categories || [],
            colors: ['#556ee6', '#34c38f', '#f46a6a', '#50a5f1', '#f1b44c', '#5b73e8', '#34c38f', '#f46a6a', '#50a5f1', '#f1b44c'],
            legend: {
                position: 'bottom'
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };

        if (categoryChart) {
            categoryChart.destroy();
        }
        categoryChart = new ApexCharts(document.querySelector("#category-chart"), options);
        categoryChart.render();
    }

    // Report type buttons
    document.getElementById('btnDaily').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('daily');
    });

    document.getElementById('btnMonthly').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('monthly');
    });

    document.getElementById('btnYearly').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('yearly');
    });

    document.getElementById('btnProduct').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('product');
    });

    document.getElementById('btnCustomer').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('customer');
    });

    document.getElementById('btnCategory').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('category');
    });

    // Update report type and load data
    function updateReportType(type) {
        document.getElementById('report_type').value = type;
        
        // Update button styles
        const buttons = ['btnDaily', 'btnMonthly', 'btnYearly', 'btnProduct', 'btnCustomer', 'btnCategory'];
        buttons.forEach(btnId => {
            const btn = document.getElementById(btnId);
            if (btn) {
                btn.className = btnId === 'btn' + type.charAt(0).toUpperCase() + type.slice(1) ? 'btn btn-primary' : 'btn btn-outline-primary';
            }
        });
        
        // Show/hide filter containers based on report type
        document.getElementById('customer_filter').style.display = (type === 'product' || type === 'category') ? 'block' : 'none';
        document.getElementById('product_filter').style.display = (type === 'customer' || type === 'category') ? 'block' : 'none';
        document.getElementById('category_filter').style.display = (type === 'product' || type === 'customer') ? 'block' : 'none';
        
        // Clear product/customer/category selections when switching report types
        if (type === 'daily' || type === 'monthly' || type === 'yearly') {
            document.getElementById('customer_id').value = '';
            document.getElementById('product_id').value = '';
            document.getElementById('category_id').value = '';
        }
        
        // Load report data
        loadReportData();
    }

    // Apply filter button
    document.getElementById('applyFilterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        loadReportData();
    });

    // Reset filter button
    document.getElementById('resetFilterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        
        // Reset form fields
        document.getElementById('date_from').value = '<?= date('Y-m-01') ?>';
        document.getElementById('date_to').value = '<?= date('Y-m-d') ?>';
        document.getElementById('customer_id').value = '';
        document.getElementById('product_id').value = '';
        document.getElementById('category_id').value = '';
        document.getElementById('status').value = 'all';
        document.getElementById('search').value = '';
        
        // Load report data
        loadReportData();
    });

    // Export button
    document.getElementById('btnExport').addEventListener('click', function(e) {
        e.preventDefault();
        
        // Build export URL with current filters
        const params = new URLSearchParams();
        params.append('export', 'csv');
        params.append('report_type', document.getElementById('report_type').value);
        params.append('date_from', document.getElementById('date_from').value);
        params.append('date_to', document.getElementById('date_to').value);
        params.append('customer_id', document.getElementById('customer_id').value);
        params.append('product_id', document.getElementById('product_id').value);
        params.append('category_id', document.getElementById('category_id').value);
        params.append('status', document.getElementById('status').value);
        params.append('search', document.getElementById('search').value);
        
        window.location.href = 'sales-report.php?' + params.toString();
    });

    // Load report data via AJAX
    function loadReportData() {
        const reportType = document.getElementById('report_type').value;
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const customerId = document.getElementById('customer_id').value;
        const productId = document.getElementById('product_id').value;
        const categoryId = document.getElementById('category_id').value;
        const status = document.getElementById('status').value;
        const search = document.getElementById('search').value;
        
        // Show loading indicator
        document.getElementById('loadingIndicator').style.display = 'block';
        document.getElementById('reportContent').style.opacity = '0.5';
        
        // Build URL with parameters
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', 'load_report');
        url.searchParams.set('report_type', reportType);
        url.searchParams.set('date_from', dateFrom);
        url.searchParams.set('date_to', dateTo);
        url.searchParams.set('customer_id', customerId);
        url.searchParams.set('product_id', productId);
        url.searchParams.set('category_id', categoryId);
        url.searchParams.set('status', status);
        url.searchParams.set('search', search);
        
        fetch(url.toString())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update report content
                    document.getElementById('reportContent').innerHTML = data.html;
                    
                    // Reinitialize charts based on report type
                    if (data.report_type === 'daily' || data.report_type === 'monthly' || data.report_type === 'yearly') {
                        initSalesTrendChart(data.chart_data);
                    } else if (data.report_type === 'product') {
                        initProductChart(data.chart_data);
                    } else if (data.report_type === 'customer') {
                        initCustomerChart(data.chart_data);
                    } else if (data.report_type === 'category') {
                        initCategoryChart(data.chart_data);
                    }
                } else {
                    alert('Error loading report: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while loading the report');
            })
            .finally(() => {
                // Hide loading indicator
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('reportContent').style.opacity = '1';
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

/* Button styles */
.btn-soft-primary {
    transition: all 0.3s;
}

.btn-soft-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(85, 110, 230, 0.3);
}

/* Loading indicator */
#loadingIndicator {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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