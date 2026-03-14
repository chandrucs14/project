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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'daily'; // daily, monthly, yearly, supplier, product, category
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : '';
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';
$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, completed, pending
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle AJAX request for report data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_report') {
    header('Content-Type: application/json');
    
    try {
        $report_type = $_GET['report_type'] ?? 'daily';
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        $supplier_id = $_GET['supplier_id'] ?? '';
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
                $result = getDailyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'monthly':
                $result = getMonthlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'yearly':
                $result = getYearlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'supplier':
                $result = getSupplierPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $status);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'product':
                $result = getProductPurchaseReport($pdo, $date_from, $date_to, $product_id, $category_id, $status);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'category':
                $result = getCategoryPurchaseReport($pdo, $date_from, $date_to, $category_id, $status);
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
                                        <i class="mdi mdi-cart-outline font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Purchases</p>
                                <h4>₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h4>
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
                                        <i class="mdi mdi-truck font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Cash Purchases</p>
                                <h4>₹<?= number_format($summary['cash_purchases'] ?? 0, 2) ?></h4>
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
                                <p class="text-muted mb-2">Credit Purchases</p>
                                <h4>₹<?= number_format($summary['credit_purchases'] ?? 0, 2) ?></h4>
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
                                        <i class="mdi mdi-package-variant font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Items</p>
                                <h4><?= number_format($summary['total_quantity'] ?? 0, 2) ?></h4>
                                <small class="text-muted">Quantity purchased</small>
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
                        <h4 class="card-title mb-4">Purchase Trend</h4>
                        <div id="purchase-trend-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'supplier'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Suppliers</h4>
                        <div id="supplier-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'product'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Products Purchased</h4>
                        <div id="product-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'category'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Purchases by Category</h4>
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
                        <h4 class="card-title mb-4">Purchase Details</h4>
                        <div class="table-responsive">
                            <table class="table table-centered table-nowrap mb-0">
                                <thead class="thead-light">
                                    <?php if ($report_type == 'daily'): ?>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Purchases</th>
                                        <th class="text-end">Credit Purchases</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Purchases</th>
                                    </tr>
                                    <?php elseif ($report_type == 'monthly'): ?>
                                    <tr>
                                        <th>Month</th>
                                        <th>Year</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Purchases</th>
                                        <th class="text-end">Credit Purchases</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Purchases</th>
                                    </tr>
                                    <?php elseif ($report_type == 'yearly'): ?>
                                    <tr>
                                        <th>Year</th>
                                        <th>Transactions</th>
                                        <th class="text-end">Cash Purchases</th>
                                        <th class="text-end">Credit Purchases</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Purchases</th>
                                    </tr>
                                    <?php elseif ($report_type == 'supplier'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th class="text-end">Transactions</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Net Purchases</th>
                                        <th class="text-end">Avg. per Transaction</th>
                                    </tr>
                                    <?php elseif ($report_type == 'product'): ?>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Unit</th>
                                        <th class="text-end">Quantity Purchased</th>
                                        <th class="text-end">Avg. Cost</th>
                                        <th class="text-end">Total Purchase</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Net Purchase</th>
                                    </tr>
                                    <?php elseif ($report_type == 'category'): ?>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Products</th>
                                        <th class="text-end">Transactions</th>
                                        <th class="text-end">Quantity Purchased</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">GST</th>
                                        <th class="text-end">Net Purchases</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php if (empty($report_data)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                            <p class="mt-2">No purchase data found for selected filters</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type == 'daily'): ?>
                                            <td><?= date('d M Y', strtotime($row['purchase_date'])) ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'monthly'): ?>
                                            <td><?= DateTime::createFromFormat('!m', $row['purchase_month'])->format('F') ?></td>
                                            <td><?= $row['purchase_year'] ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'yearly'): ?>
                                            <td><?= $row['purchase_year'] ?></td>
                                            <td><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'supplier'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code']) ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td class="text-end"><?= $row['transaction_count'] ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_transaction'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'product'): ?>
                                            <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($row['unit']) ?></td>
                                            <td class="text-end"><?= number_format($row['quantity_purchased'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_cost'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                            
                                            <?php elseif ($report_type == 'category'): ?>
                                            <td><strong><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></strong></td>
                                            <td class="text-end"><?= $row['product_count'] ?></td>
                                            <td class="text-end"><?= $row['transaction_count'] ?></td>
                                            <td class="text-end"><?= number_format($row['quantity_purchased'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
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

// Function to get daily purchase report
function getDailyPurchaseReport($pdo, $date_from, $date_to, $supplier_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            DATE(po.order_date) as purchase_date,
            COUNT(DISTINCT po.id) as transaction_count,
            SUM(po.total_amount) as total_purchases,
            SUM(po.gst_total) as total_gst,
            SUM(po.discount_amount) as total_discount,
            SUM(po.subtotal) as net_purchases
        FROM purchase_orders po
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND po.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    if (!empty($search)) {
        $query .= " AND (po.po_number LIKE :search OR po.notes LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY DATE(po.order_date) ORDER BY purchase_date";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cash/credit breakdown from daywise_amounts
    $cash_credit_query = "
        SELECT 
            amount_date,
            cash_purchases,
            credit_purchases
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
    ";
    
    $ccStmt = $pdo->prepare($cash_credit_query);
    $ccStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $cc_data = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge cash/credit data with purchase data
    $cc_map = [];
    foreach ($cc_data as $cc) {
        $cc_map[$cc['amount_date']] = $cc;
    }
    
    foreach ($data as &$row) {
        $date = $row['purchase_date'];
        if (isset($cc_map[$date])) {
            $row['cash_purchases'] = $cc_map[$date]['cash_purchases'];
            $row['credit_purchases'] = $cc_map[$date]['credit_purchases'];
        } else {
            $row['cash_purchases'] = 0;
            $row['credit_purchases'] = 0;
        }
    }
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'cash_purchases' => 0,
        'credit_purchases' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_purchases' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'total_quantity' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['cash_purchases'] += $row['cash_purchases'];
        $summary['credit_purchases'] += $row['credit_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_transactions'] += $row['transaction_count'];
        
        if ($row['cash_purchases'] > 0) $summary['cash_count']++;
        if ($row['credit_purchases'] > 0) $summary['credit_count']++;
    }
    
    // Get total quantity from purchase order items
    $qty_query = "
        SELECT SUM(quantity) as total_qty
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $qty_params = [':date_from' => $date_from, ':date_to' => $date_to];
    
    if (!empty($supplier_id)) {
        $qty_query .= " AND po.supplier_id = :supplier_id";
        $qty_params[':supplier_id'] = $supplier_id;
    }
    
    $qtyStmt = $pdo->prepare($qty_query);
    $qtyStmt->execute($qty_params);
    $qty_result = $qtyStmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_quantity'] = $qty_result['total_qty'] ?? 0;
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = date('d M', strtotime($row['purchase_date']));
        $chart_data['cash'][] = $row['cash_purchases'];
        $chart_data['credit'][] = $row['credit_purchases'];
        $chart_data['total'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get monthly purchase report
function getMonthlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            MONTH(po.order_date) as purchase_month,
            YEAR(po.order_date) as purchase_year,
            COUNT(DISTINCT po.id) as transaction_count,
            SUM(po.total_amount) as total_purchases,
            SUM(po.gst_total) as total_gst,
            SUM(po.discount_amount) as total_discount,
            SUM(po.subtotal) as net_purchases
        FROM purchase_orders po
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND po.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY YEAR(po.order_date), MONTH(po.order_date) ORDER BY purchase_year, purchase_month";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cash/credit breakdown from daywise_amounts
    $cash_credit_query = "
        SELECT 
            MONTH(amount_date) as month,
            YEAR(amount_date) as year,
            SUM(cash_purchases) as cash_purchases,
            SUM(credit_purchases) as credit_purchases
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
        GROUP BY YEAR(amount_date), MONTH(amount_date)
    ";
    
    $ccStmt = $pdo->prepare($cash_credit_query);
    $ccStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $cc_data = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge cash/credit data with purchase data
    $cc_map = [];
    foreach ($cc_data as $cc) {
        $cc_map[$cc['year'] . '-' . $cc['month']] = $cc;
    }
    
    foreach ($data as &$row) {
        $key = $row['purchase_year'] . '-' . $row['purchase_month'];
        if (isset($cc_map[$key])) {
            $row['cash_purchases'] = $cc_map[$key]['cash_purchases'];
            $row['credit_purchases'] = $cc_map[$key]['credit_purchases'];
        } else {
            $row['cash_purchases'] = 0;
            $row['credit_purchases'] = 0;
        }
    }
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'cash_purchases' => 0,
        'credit_purchases' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_purchases' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'total_quantity' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['cash_purchases'] += $row['cash_purchases'];
        $summary['credit_purchases'] += $row['credit_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_transactions'] += $row['transaction_count'];
        
        if ($row['cash_purchases'] > 0) $summary['cash_count']++;
        if ($row['credit_purchases'] > 0) $summary['credit_count']++;
    }
    
    // Get total quantity from purchase order items
    $qty_query = "
        SELECT SUM(quantity) as total_qty
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $qty_params = [':date_from' => $date_from, ':date_to' => $date_to];
    
    if (!empty($supplier_id)) {
        $qty_query .= " AND po.supplier_id = :supplier_id";
        $qty_params[':supplier_id'] = $supplier_id;
    }
    
    $qtyStmt = $pdo->prepare($qty_query);
    $qtyStmt->execute($qty_params);
    $qty_result = $qtyStmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_quantity'] = $qty_result['total_qty'] ?? 0;
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = DateTime::createFromFormat('!m', $row['purchase_month'])->format('M') . ' ' . $row['purchase_year'];
        $chart_data['cash'][] = $row['cash_purchases'];
        $chart_data['credit'][] = $row['credit_purchases'];
        $chart_data['total'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get yearly purchase report
function getYearlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id = '', $product_id = '', $category_id = '', $status = 'all', $search = '') {
    $query = "
        SELECT 
            YEAR(po.order_date) as purchase_year,
            COUNT(DISTINCT po.id) as transaction_count,
            SUM(po.total_amount) as total_purchases,
            SUM(po.gst_total) as total_gst,
            SUM(po.discount_amount) as total_discount,
            SUM(po.subtotal) as net_purchases
        FROM purchase_orders po
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND po.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY YEAR(po.order_date) ORDER BY purchase_year";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cash/credit breakdown from daywise_amounts
    $cash_credit_query = "
        SELECT 
            YEAR(amount_date) as year,
            SUM(cash_purchases) as cash_purchases,
            SUM(credit_purchases) as credit_purchases
        FROM daywise_amounts
        WHERE amount_date BETWEEN :date_from AND :date_to
        GROUP BY YEAR(amount_date)
    ";
    
    $ccStmt = $pdo->prepare($cash_credit_query);
    $ccStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $cc_data = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge cash/credit data with purchase data
    $cc_map = [];
    foreach ($cc_data as $cc) {
        $cc_map[$cc['year']] = $cc;
    }
    
    foreach ($data as &$row) {
        $year = $row['purchase_year'];
        if (isset($cc_map[$year])) {
            $row['cash_purchases'] = $cc_map[$year]['cash_purchases'];
            $row['credit_purchases'] = $cc_map[$year]['credit_purchases'];
        } else {
            $row['cash_purchases'] = 0;
            $row['credit_purchases'] = 0;
        }
    }
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'cash_purchases' => 0,
        'credit_purchases' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_purchases' => 0,
        'total_transactions' => 0,
        'cash_count' => 0,
        'credit_count' => 0,
        'total_quantity' => 0,
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['cash_purchases'] += $row['cash_purchases'];
        $summary['credit_purchases'] += $row['credit_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_transactions'] += $row['transaction_count'];
        
        if ($row['cash_purchases'] > 0) $summary['cash_count']++;
        if ($row['credit_purchases'] > 0) $summary['credit_count']++;
    }
    
    // Get total quantity from purchase order items
    $qty_query = "
        SELECT SUM(quantity) as total_qty
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $qty_params = [':date_from' => $date_from, ':date_to' => $date_to];
    
    if (!empty($supplier_id)) {
        $qty_query .= " AND po.supplier_id = :supplier_id";
        $qty_params[':supplier_id'] = $supplier_id;
    }
    
    $qtyStmt = $pdo->prepare($qty_query);
    $qtyStmt->execute($qty_params);
    $qty_result = $qtyStmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_quantity'] = $qty_result['total_qty'] ?? 0;
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'cash' => [],
        'credit' => [],
        'total' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = $row['purchase_year'];
        $chart_data['cash'][] = $row['cash_purchases'];
        $chart_data['credit'][] = $row['credit_purchases'];
        $chart_data['total'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get supplier purchase report
function getSupplierPurchaseReport($pdo, $date_from, $date_to, $supplier_id = '', $status = 'all') {
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            COUNT(DISTINCT po.id) as transaction_count,
            SUM(po.total_amount) as total_purchases,
            SUM(po.gst_total) as total_gst,
            SUM(po.discount_amount) as total_discount,
            SUM(po.subtotal) as net_purchases,
            AVG(po.total_amount) as avg_transaction,
            MAX(po.total_amount) as max_transaction,
            MIN(po.total_amount) as min_transaction
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($status) && $status != 'all') {
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name ORDER BY total_purchases DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'total_gst' => 0,
        'total_discount' => 0,
        'net_purchases' => 0,
        'total_transactions' => 0,
        'unique_suppliers' => count($data),
        'avg_transaction' => 0,
        'total_quantity' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['total_discount'] += $row['total_discount'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Get total quantity
    $qty_query = "
        SELECT SUM(poi.quantity) as total_qty
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
    ";
    
    $qty_params = [':date_from' => $date_from, ':date_to' => $date_to];
    
    if (!empty($supplier_id)) {
        $qty_query .= " AND po.supplier_id = :supplier_id";
        $qty_params[':supplier_id'] = $supplier_id;
    }
    
    $qtyStmt = $pdo->prepare($qty_query);
    $qtyStmt->execute($qty_params);
    $qty_result = $qtyStmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_quantity'] = $qty_result['total_qty'] ?? 0;
    
    // Prepare chart data
    $chart_data = [
        'suppliers' => [],
        'purchases' => []
    ];
    
    $top_suppliers = array_slice($data, 0, 10);
    foreach ($top_suppliers as $row) {
        $chart_data['suppliers'][] = strlen($row['supplier_name']) > 20 ? substr($row['supplier_name'], 0, 20) . '...' : $row['supplier_name'];
        $chart_data['purchases'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get product purchase report
function getProductPurchaseReport($pdo, $date_from, $date_to, $product_id = '', $category_id = '', $status = 'all') {
    $query = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            c.name as category_name,
            p.unit,
            COUNT(DISTINCT poi.purchase_order_id) as transaction_count,
            SUM(poi.quantity) as quantity_purchased,
            AVG(poi.unit_price) as avg_cost,
            SUM(poi.total_price) as total_purchases,
            SUM(poi.gst_amount) as total_gst,
            SUM(poi.total_price) as net_purchases
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
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
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY p.id, p.name, c.name, p.unit ORDER BY total_purchases DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'total_gst' => 0,
        'net_purchases' => 0,
        'total_quantity' => 0,
        'total_transactions' => 0,
        'unique_products' => count($data),
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_quantity'] += $row['quantity_purchased'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'products' => [],
        'purchases' => []
    ];
    
    $top_products = array_slice($data, 0, 10);
    foreach ($top_products as $row) {
        $chart_data['products'][] = strlen($row['product_name']) > 20 ? substr($row['product_name'], 0, 20) . '...' : $row['product_name'];
        $chart_data['purchases'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get category purchase report
function getCategoryPurchaseReport($pdo, $date_from, $date_to, $category_id = '', $status = 'all') {
    $query = "
        SELECT 
            c.id as category_id,
            c.name as category_name,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT poi.purchase_order_id) as transaction_count,
            SUM(poi.quantity) as quantity_purchased,
            SUM(poi.total_price) as total_purchases,
            SUM(poi.gst_amount) as total_gst,
            SUM(poi.total_price) as net_purchases
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.order_date BETWEEN :date_from AND :date_to
        AND po.status NOT IN ('cancelled', 'draft')
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
        $query .= " AND po.status = :status";
        $params[':status'] = $status;
    }
    
    $query .= " GROUP BY c.id, c.name ORDER BY total_purchases DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_purchases' => 0,
        'total_gst' => 0,
        'net_purchases' => 0,
        'total_quantity' => 0,
        'total_transactions' => 0,
        'unique_categories' => count($data),
        'avg_transaction' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'];
        $summary['total_gst'] += $row['total_gst'];
        $summary['net_purchases'] += $row['net_purchases'];
        $summary['total_quantity'] += $row['quantity_purchased'];
        $summary['total_transactions'] += $row['transaction_count'];
    }
    
    if ($summary['total_transactions'] > 0) {
        $summary['avg_transaction'] = $summary['total_purchases'] / $summary['total_transactions'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => [],
        'purchases' => []
    ];
    
    foreach ($data as $row) {
        $chart_data['categories'][] = $row['category_name'] ?? 'Uncategorized';
        $chart_data['purchases'][] = $row['total_purchases'];
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, supplier_code FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
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
        $result = getDailyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'monthly':
        $result = getMonthlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'yearly':
        $result = getYearlyPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $product_id, $category_id, $status, $search);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'supplier':
        $result = getSupplierPurchaseReport($pdo, $date_from, $date_to, $supplier_id, $status);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'product':
        $result = getProductPurchaseReport($pdo, $date_from, $date_to, $product_id, $category_id, $status);
        $report_data = $result['data'];
        $summary = $result['summary'];
        $chart_data = $result['chart_data'];
        break;
        
    case 'category':
        $result = getCategoryPurchaseReport($pdo, $date_from, $date_to, $category_id, $status);
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
    $filename = 'purchase_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Purchase Report - ' . ucfirst($report_type)]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    fputcsv($output, []);
    
    // Add headers based on report type
    if ($report_type == 'daily') {
        fputcsv($output, ['Date', 'Transactions', 'Cash Purchases', 'Credit Purchases', 'Total Purchases', 'GST', 'Discount', 'Net Purchases']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['purchase_date'],
                $row['transaction_count'],
                number_format($row['cash_purchases'], 2),
                number_format($row['credit_purchases'], 2),
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_purchases'], 2)
            ]);
        }
    } elseif ($report_type == 'monthly') {
        fputcsv($output, ['Month', 'Year', 'Transactions', 'Cash Purchases', 'Credit Purchases', 'Total Purchases', 'GST', 'Discount', 'Net Purchases']);
        foreach ($data as $row) {
            fputcsv($output, [
                DateTime::createFromFormat('!m', $row['purchase_month'])->format('F'),
                $row['purchase_year'],
                $row['transaction_count'],
                number_format($row['cash_purchases'], 2),
                number_format($row['credit_purchases'], 2),
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_purchases'], 2)
            ]);
        }
    } elseif ($report_type == 'yearly') {
        fputcsv($output, ['Year', 'Transactions', 'Cash Purchases', 'Credit Purchases', 'Total Purchases', 'GST', 'Discount', 'Net Purchases']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['purchase_year'],
                $row['transaction_count'],
                number_format($row['cash_purchases'], 2),
                number_format($row['credit_purchases'], 2),
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_purchases'], 2)
            ]);
        }
    } elseif ($report_type == 'supplier') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Transactions', 'Total Purchases', 'GST', 'Discount', 'Net Purchases', 'Avg per Transaction']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'],
                $row['supplier_code'],
                $row['company_name'] ?? '',
                $row['transaction_count'],
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['total_discount'], 2),
                number_format($row['net_purchases'], 2),
                number_format($row['avg_transaction'], 2)
            ]);
        }
    } elseif ($report_type == 'product') {
        fputcsv($output, ['Product', 'Category', 'Unit', 'Quantity Purchased', 'Avg Cost', 'Total Purchases', 'GST', 'Net Purchases']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['product_name'],
                $row['category_name'] ?? 'N/A',
                $row['unit'],
                number_format($row['quantity_purchased'], 2),
                number_format($row['avg_cost'], 2),
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['net_purchases'], 2)
            ]);
        }
    } elseif ($report_type == 'category') {
        fputcsv($output, ['Category', 'Products', 'Transactions', 'Quantity Purchased', 'Total Purchases', 'GST', 'Net Purchases']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['category_name'] ?? 'Uncategorized',
                $row['product_count'],
                $row['transaction_count'],
                number_format($row['quantity_purchased'], 2),
                number_format($row['total_purchases'], 2),
                number_format($row['total_gst'], 2),
                number_format($row['net_purchases'], 2)
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
                            <h4 class="mb-0 font-size-18">Purchase Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Purchase Report</li>
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
                                    <button type="button" class="btn btn-<?= $report_type == 'supplier' ? 'primary' : 'outline-primary' ?>" id="btnSupplier">
                                        <i class="mdi mdi-truck"></i> By Supplier
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'product' ? 'primary' : 'outline-primary' ?>" id="btnProduct">
                                        <i class="mdi mdi-package-variant"></i> By Product
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
                                <form method="GET" action="purchase-report.php" class="row" id="filterForm">
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
                                    
                                    <div class="col-md-2" id="supplier_filter" style="<?= ($report_type == 'product' || $report_type == 'category') ? 'display: block;' : 'display: none;' ?>">
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
                                    
                                    <div class="col-md-2" id="product_filter" style="<?= ($report_type == 'supplier' || $report_type == 'category') ? 'display: block;' : 'display: none;' ?>">
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
                                    
                                    <div class="col-md-2" id="category_filter" style="<?= ($report_type == 'product' || $report_type == 'supplier') ? 'display: block;' : 'display: none;' ?>">
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
                                                <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="sent" <?= $status == 'sent' ? 'selected' : '' ?>>Pending</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="PO #, Notes..." value="<?= htmlspecialchars($search) ?>">
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
                                                    <i class="mdi mdi-cart-outline font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Purchases</p>
                                            <h4>₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h4>
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
                                                    <i class="mdi mdi-truck font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Cash Purchases</p>
                                            <h4>₹<?= number_format($summary['cash_purchases'] ?? 0, 2) ?></h4>
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
                                            <p class="text-muted mb-2">Credit Purchases</p>
                                            <h4>₹<?= number_format($summary['credit_purchases'] ?? 0, 2) ?></h4>
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
                                                    <i class="mdi mdi-package-variant font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Items</p>
                                            <h4><?= number_format($summary['total_quantity'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Quantity purchased</small>
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
                                    <h4 class="card-title mb-4">Purchase Trend</h4>
                                    <div id="purchase-trend-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'supplier'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Suppliers</h4>
                                    <div id="supplier-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'product'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Products Purchased</h4>
                                    <div id="product-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'category'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Purchases by Category</h4>
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
                                    <h4 class="card-title mb-4">Purchase Details</h4>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <?php if ($report_type == 'daily'): ?>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Purchases</th>
                                                    <th class="text-end">Credit Purchases</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Purchases</th>
                                                </tr>
                                                <?php elseif ($report_type == 'monthly'): ?>
                                                <tr>
                                                    <th>Month</th>
                                                    <th>Year</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Purchases</th>
                                                    <th class="text-end">Credit Purchases</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Purchases</th>
                                                </tr>
                                                <?php elseif ($report_type == 'yearly'): ?>
                                                <tr>
                                                    <th>Year</th>
                                                    <th>Transactions</th>
                                                    <th class="text-end">Cash Purchases</th>
                                                    <th class="text-end">Credit Purchases</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Purchases</th>
                                                </tr>
                                                <?php elseif ($report_type == 'supplier'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th class="text-end">Transactions</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Discount</th>
                                                    <th class="text-end">Net Purchases</th>
                                                    <th class="text-end">Avg. per Transaction</th>
                                                </tr>
                                                <?php elseif ($report_type == 'product'): ?>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Category</th>
                                                    <th>Unit</th>
                                                    <th class="text-end">Quantity Purchased</th>
                                                    <th class="text-end">Avg. Cost</th>
                                                    <th class="text-end">Total Purchase</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Net Purchase</th>
                                                </tr>
                                                <?php elseif ($report_type == 'category'): ?>
                                                <tr>
                                                    <th>Category</th>
                                                    <th class="text-end">Products</th>
                                                    <th class="text-end">Transactions</th>
                                                    <th class="text-end">Quantity Purchased</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">GST</th>
                                                    <th class="text-end">Net Purchases</th>
                                                </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($report_data)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                        <p class="mt-2">No purchase data found for selected filters</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_data as $row): ?>
                                                    <tr>
                                                        <?php if ($report_type == 'daily'): ?>
                                                        <td><?= date('d M Y', strtotime($row['purchase_date'])) ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'monthly'): ?>
                                                        <td><?= DateTime::createFromFormat('!m', $row['purchase_month'])->format('F') ?></td>
                                                        <td><?= $row['purchase_year'] ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'yearly'): ?>
                                                        <td><?= $row['purchase_year'] ?></td>
                                                        <td><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['cash_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['credit_purchases'], 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_purchases'], 2) ?></strong></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'supplier'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name']) ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code']) ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td class="text-end"><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_discount'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_transaction'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'product'): ?>
                                                        <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                                                        <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                                                        <td><?= htmlspecialchars($row['unit']) ?></td>
                                                        <td class="text-end"><?= number_format($row['quantity_purchased'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_cost'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
                                                        
                                                        <?php elseif ($report_type == 'category'): ?>
                                                        <td><strong><?= htmlspecialchars($row['category_name'] ?? 'Uncategorized') ?></strong></td>
                                                        <td class="text-end"><?= $row['product_count'] ?></td>
                                                        <td class="text-end"><?= $row['transaction_count'] ?></td>
                                                        <td class="text-end"><?= number_format($row['quantity_purchased'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_gst'], 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['net_purchases'], 2) ?></td>
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
    var purchaseTrendChart = null;
    var supplierChart = null;
    var productChart = null;
    var categoryChart = null;

    // Initialize charts on page load
    <?php if ($report_type == 'daily' || $report_type == 'monthly' || $report_type == 'yearly'): ?>
    initPurchaseTrendChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'supplier'): ?>
    initSupplierChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'product'): ?>
    initProductChart(<?= json_encode($chart_data) ?>);
    <?php elseif ($report_type == 'category'): ?>
    initCategoryChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>

    // Initialize purchase trend chart
    function initPurchaseTrendChart(data) {
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
                    name: 'Cash Purchases',
                    type: 'column',
                    data: data.cash || []
                },
                {
                    name: 'Credit Purchases',
                    type: 'column',
                    data: data.credit || []
                },
                {
                    name: 'Total Purchases',
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

        if (purchaseTrendChart) {
            purchaseTrendChart.destroy();
        }
        purchaseTrendChart = new ApexCharts(document.querySelector("#purchase-trend-chart"), options);
        purchaseTrendChart.render();
    }

    // Initialize supplier chart
    function initSupplierChart(data) {
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
                name: 'Purchases',
                data: data.purchases || []
            }],
            xaxis: {
                categories: data.suppliers || [],
                title: {
                    text: 'Purchase Amount (₹)'
                }
            },
            yaxis: {
                title: {
                    text: 'Supplier'
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

        if (supplierChart) {
            supplierChart.destroy();
        }
        supplierChart = new ApexCharts(document.querySelector("#supplier-chart"), options);
        supplierChart.render();
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
                name: 'Purchases',
                data: data.purchases || []
            }],
            xaxis: {
                categories: data.products || [],
                title: {
                    text: 'Purchase Amount (₹)'
                }
            },
            yaxis: {
                title: {
                    text: 'Product'
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

        if (productChart) {
            productChart.destroy();
        }
        productChart = new ApexCharts(document.querySelector("#product-chart"), options);
        productChart.render();
    }

    // Initialize category chart
    function initCategoryChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'pie'
            },
            series: data.purchases || [],
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

    document.getElementById('btnSupplier').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('supplier');
    });

    document.getElementById('btnProduct').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('product');
    });

    document.getElementById('btnCategory').addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('category');
    });

    // Update report type and load data
    function updateReportType(type) {
        document.getElementById('report_type').value = type;
        
        // Update button styles
        const buttons = ['btnDaily', 'btnMonthly', 'btnYearly', 'btnSupplier', 'btnProduct', 'btnCategory'];
        buttons.forEach(btnId => {
            const btn = document.getElementById(btnId);
            if (btn) {
                btn.className = btnId === 'btn' + type.charAt(0).toUpperCase() + type.slice(1) ? 'btn btn-primary' : 'btn btn-outline-primary';
            }
        });
        
        // Show/hide filter containers based on report type
        document.getElementById('supplier_filter').style.display = (type === 'product' || type === 'category') ? 'block' : 'none';
        document.getElementById('product_filter').style.display = (type === 'supplier' || type === 'category') ? 'block' : 'none';
        document.getElementById('category_filter').style.display = (type === 'product' || type === 'supplier') ? 'block' : 'none';
        
        // Clear supplier/product/category selections when switching report types
        if (type === 'daily' || type === 'monthly' || type === 'yearly') {
            document.getElementById('supplier_id').value = '';
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
        document.getElementById('supplier_id').value = '';
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
        params.append('supplier_id', document.getElementById('supplier_id').value);
        params.append('product_id', document.getElementById('product_id').value);
        params.append('category_id', document.getElementById('category_id').value);
        params.append('status', document.getElementById('status').value);
        params.append('search', document.getElementById('search').value);
        
        window.location.href = 'purchase-report.php?' + params.toString();
    });

    // Load report data via AJAX
    function loadReportData() {
        const reportType = document.getElementById('report_type').value;
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const supplierId = document.getElementById('supplier_id').value;
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
        url.searchParams.set('supplier_id', supplierId);
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
                        initPurchaseTrendChart(data.chart_data);
                    } else if (data.report_type === 'supplier') {
                        initSupplierChart(data.chart_data);
                    } else if (data.report_type === 'product') {
                        initProductChart(data.chart_data);
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