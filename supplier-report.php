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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary'; // summary, detailed, aging, performance, inactive
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : '';
$min_purchases = isset($_GET['min_purchases']) ? floatval($_GET['min_purchases']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, active, inactive
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'purchases_desc'; // purchases_desc, name_asc, outstanding_desc
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle AJAX request for supplier details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'supplier_details' && isset($_GET['supplier_id'])) {
    header('Content-Type: application/json');
    
    try {
        $supplier_id = intval($_GET['supplier_id']);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        // Get supplier details
        $supplierStmt = $pdo->prepare("
            SELECT s.*, 
                   COUNT(DISTINCT po.id) as total_orders,
                   COALESCE(SUM(po.total_amount), 0) as total_purchases,
                   s.outstanding_balance as current_outstanding,
                   MAX(po.order_date) as last_purchase_date,
                   MIN(po.order_date) as first_purchase_date
            FROM suppliers s
            LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.status NOT IN ('cancelled', 'draft')
            WHERE s.id = :supplier_id
            GROUP BY s.id
        ");
        $supplierStmt->execute([':supplier_id' => $supplier_id]);
        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            echo json_encode(['success' => false, 'message' => 'Supplier not found']);
            exit();
        }
        
        // Get recent purchase orders
        $poStmt = $pdo->prepare("
            SELECT 
                po_number,
                order_date,
                expected_delivery,
                total_amount,
                status
            FROM purchase_orders
            WHERE supplier_id = :supplier_id
            AND order_date BETWEEN :date_from AND :date_to
            ORDER BY order_date DESC
            LIMIT 20
        ");
        $poStmt->execute([
            ':supplier_id' => $supplier_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $orders = $poStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payment history
        $paymentStmt = $pdo->prepare("
            SELECT 
                so.transaction_date,
                so.amount,
                so.balance_after,
                po.po_number,
                so.reference_id
            FROM supplier_outstanding so
            LEFT JOIN purchase_orders po ON so.reference_id = po.id
            WHERE so.supplier_id = :supplier_id
            AND so.transaction_type = 'payment'
            AND so.transaction_date BETWEEN :date_from AND :date_to
            ORDER BY so.transaction_date DESC
            LIMIT 20
        ");
        $paymentStmt->execute([
            ':supplier_id' => $supplier_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get aging analysis
        $agingStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(order_date, INTERVAL COALESCE(:payment_terms, 30) DAY)) <= 0 THEN total_amount ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(order_date, INTERVAL COALESCE(:payment_terms2, 30) DAY)) BETWEEN 1 AND 30 THEN total_amount ELSE 0 END), 0) as days_1_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(order_date, INTERVAL COALESCE(:payment_terms3, 30) DAY)) BETWEEN 31 AND 60 THEN total_amount ELSE 0 END), 0) as days_31_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(order_date, INTERVAL COALESCE(:payment_terms4, 30) DAY)) BETWEEN 61 AND 90 THEN total_amount ELSE 0 END), 0) as days_61_90,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(order_date, INTERVAL COALESCE(:payment_terms5, 30) DAY)) > 90 THEN total_amount ELSE 0 END), 0) as days_90_plus
            FROM purchase_orders
            WHERE supplier_id = :supplier_id
            AND status IN ('sent', 'confirmed', 'partially_received')
            AND total_amount > 0
        ");
        
        $payment_terms = $supplier['payment_terms'] ?? 30;
        $agingStmt->execute([
            ':supplier_id' => $supplier_id,
            ':payment_terms' => $payment_terms,
            ':payment_terms2' => $payment_terms,
            ':payment_terms3' => $payment_terms,
            ':payment_terms4' => $payment_terms,
            ':payment_terms5' => $payment_terms
        ]);
        $aging = $agingStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'supplier' => $supplier,
            'orders' => $orders,
            'payments' => $payments,
            'aging' => $aging
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for report data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_report') {
    header('Content-Type: application/json');
    
    try {
        $report_type = $_GET['report_type'] ?? 'summary';
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        $supplier_id = $_GET['supplier_id'] ?? '';
        $min_purchases = isset($_GET['min_purchases']) ? floatval($_GET['min_purchases']) : 0;
        $status = $_GET['status'] ?? 'all';
        $sort_by = $_GET['sort_by'] ?? 'purchases_desc';
        $search = $_GET['search'] ?? '';
        
        // Get report data based on type
        $report_data = [];
        $summary = [];
        $chart_data = [];
        
        switch ($report_type) {
            case 'summary':
                $result = getSupplierSummaryReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'detailed':
                $result = getSupplierDetailedReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'aging':
                $result = getSupplierAgingReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'performance':
                $result = getSupplierPerformanceReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'inactive':
                $result = getSupplierInactiveReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
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
                                        <i class="mdi mdi-truck-group font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Suppliers</p>
                                <h4><?= number_format($summary['total_suppliers'] ?? 0) ?></h4>
                                <small class="text-muted">Active: <?= number_format($summary['active_suppliers'] ?? 0) ?></small>
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
                                        <i class="mdi mdi-cart-arrow-down font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Purchases</p>
                                <h4>₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= number_format($summary['total_orders'] ?? 0) ?> orders</small>
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
                                        <i class="mdi mdi-bank font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Outstanding</p>
                                <h4>₹<?= number_format($summary['total_outstanding'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= number_format($summary['overdue_count'] ?? 0) ?> overdue</small>
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
                                <p class="text-muted mb-2">Avg. per Supplier</p>
                                <h4>₹<?= number_format($summary['avg_per_supplier'] ?? 0, 2) ?></h4>
                                <small class="text-muted">Average purchase</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($report_type == 'summary' && !empty($chart_data['suppliers'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Suppliers by Purchases</h4>
                        <div id="top-suppliers-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'aging' && !empty($chart_data['buckets'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Aging Summary</h4>
                        <div id="aging-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($report_type == 'performance' && !empty($chart_data['metrics'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Performance Metrics</h4>
                        <div id="performance-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Supplier Details</h4>
                        <div class="table-responsive">
                            <table class="table table-centered table-nowrap mb-0">
                                <thead class="thead-light">
                                    <?php if ($report_type == 'summary'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th>Phone</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">Orders</th>
                                        <th class="text-end">Avg. per Order</th>
                                        <th class="text-end">Outstanding</th>
                                        <th>Last Purchase</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'detailed'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">Outstanding</th>
                                        <th class="text-end">Orders</th>
                                        <th>First Purchase</th>
                                        <th>Last Purchase</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'aging'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th>Phone</th>
                                        <th class="text-end">Current</th>
                                        <th class="text-end">1-30 Days</th>
                                        <th class="text-end">31-60 Days</th>
                                        <th class="text-end">61-90 Days</th>
                                        <th class="text-end">90+ Days</th>
                                        <th class="text-end">Total</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'performance'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th class="text-end">Total Orders</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">Avg. Order Value</th>
                                        <th class="text-end">On-Time Rate</th>
                                        <th class="text-end">Avg. Delivery Days</th>
                                        <th>Reliability</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'inactive'): ?>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Code</th>
                                        <th>Company</th>
                                        <th>Phone</th>
                                        <th class="text-end">Last Purchase</th>
                                        <th class="text-end">Days Inactive</th>
                                        <th class="text-end">Total Purchases</th>
                                        <th class="text-end">Orders</th>
                                        <th class="text-end">Outstanding</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php if (empty($report_data)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                            <p class="mt-2">No supplier data found for selected filters</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type == 'summary'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_order'] ?? 0, 2) ?></td>
                                            <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                            </td>
                                            <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td>
                                                <?php if (($row['outstanding'] ?? 0) > 0): ?>
                                                    <span class="badge bg-soft-warning text-warning">Due</span>
                                                <?php else: ?>
                                                    <span class="badge bg-soft-success text-success">Clear</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                        data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                        data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'detailed'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                            <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                            </td>
                                            <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                            <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                            <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                        data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                        data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'aging'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['current'] ?? 0, 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['days_1_30'] ?? 0, 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['days_31_60'] ?? 0, 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['days_61_90'] ?? 0, 2) ?></td>
                                            <td class="text-end text-danger">₹<?= number_format($row['days_90_plus'] ?? 0, 2) ?></td>
                                            <td class="text-end"><strong>₹<?= number_format($row['total_outstanding'] ?? 0, 2) ?></strong></td>
                                            <td>
                                                <?php if (($row['days_90_plus'] ?? 0) > 0): ?>
                                                    <span class="badge bg-soft-danger text-danger">Critical</span>
                                                <?php elseif (($row['days_61_90'] ?? 0) > 0): ?>
                                                    <span class="badge bg-soft-warning text-warning">Warning</span>
                                                <?php elseif (($row['total_outstanding'] ?? 0) > 0): ?>
                                                    <span class="badge bg-soft-info text-info">Due</span>
                                                <?php else: ?>
                                                    <span class="badge bg-soft-success text-success">Clear</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                        data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                        data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'performance'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_order_value'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= number_format($row['on_time_rate'] ?? 0, 1) ?>%</td>
                                            <td class="text-end"><?= number_format($row['avg_delivery_days'] ?? 0, 1) ?></td>
                                            <td>
                                                <?php
                                                $reliability = 'Good';
                                                $rate = $row['on_time_rate'] ?? 0;
                                                if ($rate >= 90) $reliability = 'Excellent';
                                                elseif ($rate >= 75) $reliability = 'Good';
                                                elseif ($rate >= 50) $reliability = 'Average';
                                                else $reliability = 'Poor';
                                                
                                                $badgeClass = $reliability == 'Excellent' ? 'success' : ($reliability == 'Good' ? 'info' : ($reliability == 'Average' ? 'warning' : 'danger'));
                                                ?>
                                                <span class="badge bg-soft-<?= $badgeClass ?> text-<?= $badgeClass ?>"><?= $reliability ?></span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                        data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                        data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'inactive'): ?>
                                            <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end"><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td class="text-end text-danger"><?= $row['days_inactive'] ?? 'N/A' ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                            <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                            </td>
                                            <td>
                                                <?php if (($row['outstanding'] ?? 0) > 0): ?>
                                                    <span class="badge bg-soft-danger text-danger">Has Dues</span>
                                                <?php else: ?>
                                                    <span class="badge bg-soft-secondary text-secondary">No Dues</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                        data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                        data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
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

// Function to get supplier summary report
function getSupplierSummaryReport($pdo, $date_from, $date_to, $supplier_id = '', $min_purchases = 0, $status = 'all', $sort_by = 'purchases_desc', $search = '') {
    
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            s.phone,
            s.email,
            s.outstanding_balance as outstanding,
            COUNT(DISTINCT po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_purchases,
            COALESCE(AVG(po.total_amount), 0) as avg_order,
            MAX(po.order_date) as last_purchase,
            MIN(po.order_date) as first_purchase
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
            AND po.order_date BETWEEN :date_from AND :date_to
            AND po.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE :search OR s.supplier_code LIKE :search OR s.company_name LIKE :search OR s.phone LIKE :search OR s.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name, s.phone, s.email, s.outstanding_balance";
    
    // Apply having clause for min_purchases
    if ($min_purchases > 0) {
        $query .= " HAVING total_purchases >= :min_purchases";
        $params[':min_purchases'] = $min_purchases;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'purchases_desc':
            $query .= " ORDER BY total_purchases DESC";
            break;
        case 'purchases_asc':
            $query .= " ORDER BY total_purchases ASC";
            break;
        case 'name_asc':
            $query .= " ORDER BY s.name ASC";
            break;
        case 'outstanding_desc':
            $query .= " ORDER BY outstanding DESC";
            break;
        case 'last_purchase_desc':
            $query .= " ORDER BY last_purchase DESC";
            break;
        default:
            $query .= " ORDER BY total_purchases DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_suppliers' => 0,
        'active_suppliers' => 0,
        'total_purchases' => 0,
        'total_orders' => 0,
        'total_outstanding' => 0,
        'overdue_count' => 0,
        'avg_per_supplier' => 0
    ];
    
    $suppliers_with_purchases = 0;
    
    foreach ($data as $row) {
        $summary['total_suppliers']++;
        if (($row['total_purchases'] ?? 0) > 0) {
            $suppliers_with_purchases++;
            $summary['active_suppliers']++;
        }
        $summary['total_purchases'] += $row['total_purchases'] ?? 0;
        $summary['total_orders'] += $row['order_count'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        
        // Check for overdue (simplified)
        if (($row['outstanding'] ?? 0) > 0) {
            $summary['overdue_count']++;
        }
    }
    
    if ($suppliers_with_purchases > 0) {
        $summary['avg_per_supplier'] = $summary['total_purchases'] / $suppliers_with_purchases;
    }
    
    // Prepare chart data
    $chart_data = [
        'suppliers' => [],
        'purchases' => []
    ];
    
    $top_suppliers = array_slice($data, 0, 10);
    foreach ($top_suppliers as $row) {
        if (($row['total_purchases'] ?? 0) > 0) {
            $chart_data['suppliers'][] = strlen($row['supplier_name'] ?? '') > 20 ? substr($row['supplier_name'] ?? '', 0, 20) . '...' : ($row['supplier_name'] ?? '');
            $chart_data['purchases'][] = $row['total_purchases'] ?? 0;
        }
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get supplier detailed report
function getSupplierDetailedReport($pdo, $date_from, $date_to, $supplier_id = '', $min_purchases = 0, $status = 'all', $sort_by = 'purchases_desc', $search = '') {
    
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            s.phone,
            s.email,
            s.address,
            s.city,
            s.state,
            s.gst_number,
            s.pan_number,
            s.outstanding_balance as outstanding,
            COUNT(DISTINCT po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_purchases,
            MAX(po.order_date) as last_purchase,
            MIN(po.order_date) as first_purchase,
            COALESCE(SUM(po.discount_amount), 0) as total_discount,
            COALESCE(SUM(po.gst_total), 0) as total_gst
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
            AND po.order_date BETWEEN :date_from AND :date_to
            AND po.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE :search OR s.supplier_code LIKE :search OR s.company_name LIKE :search OR s.phone LIKE :search OR s.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name, s.phone, s.email, s.address, s.city, s.state, s.gst_number, s.pan_number, s.outstanding_balance";
    
    // Apply having clause for min_purchases
    if ($min_purchases > 0) {
        $query .= " HAVING total_purchases >= :min_purchases";
        $params[':min_purchases'] = $min_purchases;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'purchases_desc':
            $query .= " ORDER BY total_purchases DESC";
            break;
        case 'name_asc':
            $query .= " ORDER BY s.name ASC";
            break;
        case 'outstanding_desc':
            $query .= " ORDER BY outstanding DESC";
            break;
        default:
            $query .= " ORDER BY total_purchases DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_suppliers' => count($data),
        'total_purchases' => 0,
        'total_outstanding' => 0,
        'total_orders' => 0,
        'total_discount' => 0,
        'total_gst' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        $summary['total_orders'] += $row['order_count'] ?? 0;
        $summary['total_discount'] += $row['total_discount'] ?? 0;
        $summary['total_gst'] += $row['total_gst'] ?? 0;
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => []];
}

// Function to get supplier aging report
function getSupplierAgingReport($pdo, $date_from, $date_to, $supplier_id = '', $min_purchases = 0, $status = 'all', $sort_by = 'outstanding_desc', $search = '') {
    
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            s.phone,
            s.payment_terms,
            s.outstanding_balance as total_outstanding,
            COALESCE(SUM(CASE 
                WHEN DATEDIFF(CURDATE(), DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) <= 0 
                THEN po.total_amount ELSE 0 END), 0) as current,
            COALESCE(SUM(CASE 
                WHEN DATEDIFF(CURDATE(), DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 1 AND 30 
                THEN po.total_amount ELSE 0 END), 0) as days_1_30,
            COALESCE(SUM(CASE 
                WHEN DATEDIFF(CURDATE(), DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 31 AND 60 
                THEN po.total_amount ELSE 0 END), 0) as days_31_60,
            COALESCE(SUM(CASE 
                WHEN DATEDIFF(CURDATE(), DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) BETWEEN 61 AND 90 
                THEN po.total_amount ELSE 0 END), 0) as days_61_90,
            COALESCE(SUM(CASE 
                WHEN DATEDIFF(CURDATE(), DATE_ADD(po.order_date, INTERVAL COALESCE(s.payment_terms, 30) DAY)) > 90 
                THEN po.total_amount ELSE 0 END), 0) as days_90_plus
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
            AND po.status IN ('sent', 'confirmed', 'partially_received')
            AND po.total_amount > 0
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE :search OR s.supplier_code LIKE :search OR s.company_name LIKE :search OR s.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name, s.phone, s.payment_terms, s.outstanding_balance";
    
    // Apply having clause for min outstanding
    if ($min_purchases > 0) {
        $query .= " HAVING total_outstanding >= :min_outstanding";
        $params[':min_outstanding'] = $min_purchases;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'outstanding_desc':
            $query .= " ORDER BY total_outstanding DESC";
            break;
        case 'outstanding_asc':
            $query .= " ORDER BY total_outstanding ASC";
            break;
        case 'days_90_desc':
            $query .= " ORDER BY days_90_plus DESC";
            break;
        default:
            $query .= " ORDER BY total_outstanding DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_suppliers' => 0,
        'total_outstanding' => 0,
        'current' => 0,
        'days_1_30' => 0,
        'days_31_60' => 0,
        'days_61_90' => 0,
        'days_90_plus' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_suppliers']++;
        $summary['total_outstanding'] += $row['total_outstanding'] ?? 0;
        $summary['current'] += $row['current'] ?? 0;
        $summary['days_1_30'] += $row['days_1_30'] ?? 0;
        $summary['days_31_60'] += $row['days_31_60'] ?? 0;
        $summary['days_61_90'] += $row['days_61_90'] ?? 0;
        $summary['days_90_plus'] += $row['days_90_plus'] ?? 0;
    }
    
    // Prepare chart data
    $chart_data = [
        'buckets' => ['Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days'],
        'amounts' => [
            $summary['current'],
            $summary['days_1_30'],
            $summary['days_31_60'],
            $summary['days_61_90'],
            $summary['days_90_plus']
        ]
    ];
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get supplier performance report
function getSupplierPerformanceReport($pdo, $date_from, $date_to, $supplier_id = '', $min_purchases = 0, $status = 'all', $sort_by = 'purchases_desc', $search = '') {
    
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            COUNT(DISTINCT po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_purchases,
            COALESCE(AVG(po.total_amount), 0) as avg_order_value,
            COALESCE(AVG(CASE 
                WHEN po.expected_delivery IS NOT NULL AND po.status = 'completed' 
                THEN DATEDIFF(po.updated_at, po.order_date) 
                ELSE NULL 
            END), 0) as avg_delivery_days,
            COALESCE(SUM(CASE 
                WHEN po.expected_delivery IS NOT NULL 
                AND po.updated_at <= po.expected_delivery 
                AND po.status = 'completed' 
                THEN 1 ELSE 0 
            END), 0) as on_time_deliveries
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
            AND po.order_date BETWEEN :date_from AND :date_to
            AND po.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE :search OR s.supplier_code LIKE :search OR s.company_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name";
    
    // Apply having clause for min_purchases
    if ($min_purchases > 0) {
        $query .= " HAVING total_purchases >= :min_purchases";
        $params[':min_purchases'] = $min_purchases;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'purchases_desc':
            $query .= " ORDER BY total_purchases DESC";
            break;
        case 'on_time_desc':
            $query .= " ORDER BY on_time_rate DESC";
            break;
        case 'delivery_asc':
            $query .= " ORDER BY avg_delivery_days ASC";
            break;
        default:
            $query .= " ORDER BY total_purchases DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate on-time rate and summary
    $summary = [
        'total_suppliers' => count($data),
        'total_purchases' => 0,
        'total_orders' => 0,
        'avg_on_time_rate' => 0,
        'avg_delivery_days' => 0
    ];
    
    $total_rate = 0;
    $total_delivery = 0;
    
    foreach ($data as &$row) {
        $on_time_rate = ($row['order_count'] ?? 0) > 0 
            ? (($row['on_time_deliveries'] ?? 0) / ($row['order_count'] ?? 1)) * 100 
            : 0;
        $row['on_time_rate'] = $on_time_rate;
        
        $summary['total_purchases'] += $row['total_purchases'] ?? 0;
        $summary['total_orders'] += $row['order_count'] ?? 0;
        $total_rate += $on_time_rate;
        $total_delivery += $row['avg_delivery_days'] ?? 0;
    }
    
    if ($summary['total_suppliers'] > 0) {
        $summary['avg_on_time_rate'] = $total_rate / $summary['total_suppliers'];
        $summary['avg_delivery_days'] = $total_delivery / $summary['total_suppliers'];
    }
    
    // Prepare chart data
    $chart_data = [
        'metrics' => [
            'Total Orders' => $summary['total_orders'],
            'Total Purchases' => $summary['total_purchases'],
            'Avg On-Time Rate' => round($summary['avg_on_time_rate'], 1),
            'Avg Delivery Days' => round($summary['avg_delivery_days'], 1)
        ]
    ];
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get supplier inactive report
function getSupplierInactiveReport($pdo, $date_from, $date_to, $supplier_id = '', $min_purchases = 0, $status = 'all', $sort_by = 'last_purchase_asc', $search = '') {
    
    $query = "
        SELECT 
            s.id as supplier_id,
            s.name as supplier_name,
            s.supplier_code,
            s.company_name,
            s.phone,
            s.email,
            s.outstanding_balance as outstanding,
            COUNT(DISTINCT po.id) as order_count,
            COALESCE(SUM(po.total_amount), 0) as total_purchases,
            MAX(po.order_date) as last_purchase,
            DATEDIFF(CURDATE(), COALESCE(MAX(po.order_date), s.created_at)) as days_inactive
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
            AND po.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($supplier_id)) {
        $query .= " AND s.id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE :search OR s.supplier_code LIKE :search OR s.company_name LIKE :search OR s.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY s.id, s.name, s.supplier_code, s.company_name, s.phone, s.email, s.outstanding_balance, s.created_at";
    $query .= " HAVING days_inactive > 30 OR days_inactive IS NULL";
    
    // Apply sorting
    switch ($sort_by) {
        case 'last_purchase_asc':
            $query .= " ORDER BY last_purchase ASC";
            break;
        case 'last_purchase_desc':
            $query .= " ORDER BY last_purchase DESC";
            break;
        case 'days_inactive_desc':
            $query .= " ORDER BY days_inactive DESC";
            break;
        case 'days_inactive_asc':
            $query .= " ORDER BY days_inactive ASC";
            break;
        default:
            $query .= " ORDER BY days_inactive DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_suppliers' => count($data),
        'total_purchases' => 0,
        'total_outstanding' => 0,
        'total_orders' => 0,
        'avg_days_inactive' => 0
    ];
    
    $total_days = 0;
    
    foreach ($data as $row) {
        $summary['total_purchases'] += $row['total_purchases'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        $summary['total_orders'] += $row['order_count'] ?? 0;
        $total_days += $row['days_inactive'] ?? 0;
    }
    
    if ($summary['total_suppliers'] > 0) {
        $summary['avg_days_inactive'] = ceil($total_days / $summary['total_suppliers']);
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => []];
}

// Get all suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, supplier_code FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get initial data for page load
$report_data = [];
$summary = [];
$chart_data = [];

try {
    switch ($report_type) {
        case 'summary':
            $result = getSupplierSummaryReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'detailed':
            $result = getSupplierDetailedReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'aging':
            $result = getSupplierAgingReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'performance':
            $result = getSupplierPerformanceReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'inactive':
            $result = getSupplierInactiveReport($pdo, $date_from, $date_to, $supplier_id, $min_purchases, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
    }
} catch (Exception $e) {
    error_log("Error loading initial report: " . $e->getMessage());
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($report_data, $report_type, $date_from, $date_to);
    exit();
}

// Export function
function exportToCSV($data, $report_type, $date_from, $date_to) {
    $filename = 'supplier_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Supplier Report - ' . ucfirst(str_replace('_', ' ', $report_type))]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    fputcsv($output, []);
    
    // Add headers based on report type
    if ($report_type == 'summary') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Phone', 'Total Purchases', 'Orders', 'Avg per Order', 'Outstanding', 'Last Purchase']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'] ?? '',
                $row['supplier_code'] ?? '',
                $row['company_name'] ?? '',
                $row['phone'] ?? '',
                number_format($row['total_purchases'] ?? 0, 2),
                $row['order_count'] ?? 0,
                number_format($row['avg_order'] ?? 0, 2),
                number_format($row['outstanding'] ?? 0, 2),
                $row['last_purchase'] ?? 'Never'
            ]);
        }
    } elseif ($report_type == 'aging') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Phone', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total Outstanding']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'] ?? '',
                $row['supplier_code'] ?? '',
                $row['company_name'] ?? '',
                $row['phone'] ?? '',
                number_format($row['current'] ?? 0, 2),
                number_format($row['days_1_30'] ?? 0, 2),
                number_format($row['days_31_60'] ?? 0, 2),
                number_format($row['days_61_90'] ?? 0, 2),
                number_format($row['days_90_plus'] ?? 0, 2),
                number_format($row['total_outstanding'] ?? 0, 2)
            ]);
        }
    } elseif ($report_type == 'performance') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Total Orders', 'Total Purchases', 'Avg Order Value', 'On-Time Rate %', 'Avg Delivery Days']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'] ?? '',
                $row['supplier_code'] ?? '',
                $row['company_name'] ?? '',
                $row['order_count'] ?? 0,
                number_format($row['total_purchases'] ?? 0, 2),
                number_format($row['avg_order_value'] ?? 0, 2),
                number_format($row['on_time_rate'] ?? 0, 1),
                number_format($row['avg_delivery_days'] ?? 0, 1)
            ]);
        }
    } elseif ($report_type == 'inactive') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Phone', 'Last Purchase', 'Days Inactive', 'Total Purchases', 'Orders', 'Outstanding']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'] ?? '',
                $row['supplier_code'] ?? '',
                $row['company_name'] ?? '',
                $row['phone'] ?? '',
                $row['last_purchase'] ?? 'Never',
                $row['days_inactive'] ?? 'N/A',
                number_format($row['total_purchases'] ?? 0, 2),
                $row['order_count'] ?? 0,
                number_format($row['outstanding'] ?? 0, 2)
            ]);
        }
    } elseif ($report_type == 'detailed') {
        fputcsv($output, ['Supplier', 'Code', 'Company', 'Phone', 'Email', 'Total Purchases', 'Outstanding', 'Orders', 'First Purchase', 'Last Purchase']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_name'] ?? '',
                $row['supplier_code'] ?? '',
                $row['company_name'] ?? '',
                $row['phone'] ?? '',
                $row['email'] ?? '',
                number_format($row['total_purchases'] ?? 0, 2),
                number_format($row['outstanding'] ?? 0, 2),
                $row['order_count'] ?? 0,
                $row['first_purchase'] ?? 'Never',
                $row['last_purchase'] ?? 'Never'
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
                            <h4 class="mb-0 font-size-18">Supplier Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Supplier Report</li>
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
                                    <button type="button" class="btn btn-<?= $report_type == 'summary' ? 'primary' : 'outline-primary' ?>" id="btnSummary">
                                        <i class="mdi mdi-chart-bar"></i> Summary
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'detailed' ? 'primary' : 'outline-primary' ?>" id="btnDetailed">
                                        <i class="mdi mdi-format-list-bulleted"></i> Detailed
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'aging' ? 'primary' : 'outline-primary' ?>" id="btnAging">
                                        <i class="mdi mdi-clock-outline"></i> Aging
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'performance' ? 'primary' : 'outline-primary' ?>" id="btnPerformance">
                                        <i class="mdi mdi-chart-line"></i> Performance
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'inactive' ? 'primary' : 'outline-primary' ?>" id="btnInactive">
                                        <i class="mdi mdi-sleep"></i> Inactive
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
                                <form method="GET" action="supplier-report.php" class="row" id="filterForm">
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
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="min_purchases" class="form-label">Min Purchases (₹)</label>
                                            <input type="number" step="1000" class="form-control" id="min_purchases" name="min_purchases" value="<?= $min_purchases > 0 ? $min_purchases : '' ?>" placeholder="0">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="sort_by" class="form-label">Sort By</label>
                                            <select class="form-control" id="sort_by" name="sort_by">
                                                <option value="purchases_desc" <?= $sort_by == 'purchases_desc' ? 'selected' : '' ?>>Highest Purchases</option>
                                                <option value="purchases_asc" <?= $sort_by == 'purchases_asc' ? 'selected' : '' ?>>Lowest Purchases</option>
                                                <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                                                <option value="outstanding_desc" <?= $sort_by == 'outstanding_desc' ? 'selected' : '' ?>>Highest Outstanding</option>
                                                <option value="last_purchase_desc" <?= $sort_by == 'last_purchase_desc' ? 'selected' : '' ?>>Recent Purchase</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, Code, Company..." value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <button type="button" class="btn btn-primary me-2" id="applyFilterBtn">
                                                <i class="mdi mdi-filter"></i> Apply Filters
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="resetFilterBtn">
                                                <i class="mdi mdi-refresh"></i> Reset
                                            </button>
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
                                                    <i class="mdi mdi-truck-group font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Suppliers</p>
                                            <h4><?= number_format($summary['total_suppliers'] ?? 0) ?></h4>
                                            <small class="text-muted">Active: <?= number_format($summary['active_suppliers'] ?? 0) ?></small>
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
                                                    <i class="mdi mdi-cart-arrow-down font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Purchases</p>
                                            <h4>₹<?= number_format($summary['total_purchases'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= number_format($summary['total_orders'] ?? 0) ?> orders</small>
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
                                                    <i class="mdi mdi-bank font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Outstanding</p>
                                            <h4>₹<?= number_format($summary['total_outstanding'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= number_format($summary['overdue_count'] ?? 0) ?> overdue</small>
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
                                            <p class="text-muted mb-2">Avg. per Supplier</p>
                                            <h4>₹<?= number_format($summary['avg_per_supplier'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Average purchase</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($report_type == 'summary' && !empty($chart_data['suppliers'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Suppliers by Purchases</h4>
                                    <div id="top-suppliers-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'aging' && !empty($chart_data['buckets'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Aging Summary</h4>
                                    <div id="aging-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($report_type == 'performance' && !empty($chart_data['metrics'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Performance Metrics</h4>
                                    <div id="performance-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Supplier Details</h4>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <?php if ($report_type == 'summary'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">Orders</th>
                                                    <th class="text-end">Avg. per Order</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th>Last Purchase</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'detailed'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th>Phone</th>
                                                    <th>Email</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th class="text-end">Orders</th>
                                                    <th>First Purchase</th>
                                                    <th>Last Purchase</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'aging'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Current</th>
                                                    <th class="text-end">1-30 Days</th>
                                                    <th class="text-end">31-60 Days</th>
                                                    <th class="text-end">61-90 Days</th>
                                                    <th class="text-end">90+ Days</th>
                                                    <th class="text-end">Total</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'performance'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th class="text-end">Total Orders</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">Avg. Order Value</th>
                                                    <th class="text-end">On-Time Rate</th>
                                                    <th class="text-end">Avg. Delivery Days</th>
                                                    <th>Reliability</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'inactive'): ?>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Code</th>
                                                    <th>Company</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Last Purchase</th>
                                                    <th class="text-end">Days Inactive</th>
                                                    <th class="text-end">Total Purchases</th>
                                                    <th class="text-end">Orders</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($report_data)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                        <p class="mt-2">No supplier data found for selected filters</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_data as $row): ?>
                                                    <tr>
                                                        <?php if ($report_type == 'summary'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_order'] ?? 0, 2) ?></td>
                                                        <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                            ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                                        </td>
                                                        <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td>
                                                            <?php if (($row['outstanding'] ?? 0) > 0): ?>
                                                                <span class="badge bg-soft-warning text-warning">Due</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-success text-success">Clear</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                                    data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                                    data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'detailed'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                                        <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                            ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                                        </td>
                                                        <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                                        <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                                        <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                                    data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                                    data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'aging'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['current'] ?? 0, 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['days_1_30'] ?? 0, 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['days_31_60'] ?? 0, 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['days_61_90'] ?? 0, 2) ?></td>
                                                        <td class="text-end text-danger">₹<?= number_format($row['days_90_plus'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><strong>₹<?= number_format($row['total_outstanding'] ?? 0, 2) ?></strong></td>
                                                        <td>
                                                            <?php if (($row['days_90_plus'] ?? 0) > 0): ?>
                                                                <span class="badge bg-soft-danger text-danger">Critical</span>
                                                            <?php elseif (($row['days_61_90'] ?? 0) > 0): ?>
                                                                <span class="badge bg-soft-warning text-warning">Warning</span>
                                                            <?php elseif (($row['total_outstanding'] ?? 0) > 0): ?>
                                                                <span class="badge bg-soft-info text-info">Due</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-success text-success">Clear</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                                    data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                                    data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'performance'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_order_value'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= number_format($row['on_time_rate'] ?? 0, 1) ?>%</td>
                                                        <td class="text-end"><?= number_format($row['avg_delivery_days'] ?? 0, 1) ?></td>
                                                        <td>
                                                            <?php
                                                            $reliability = 'Good';
                                                            $rate = $row['on_time_rate'] ?? 0;
                                                            if ($rate >= 90) $reliability = 'Excellent';
                                                            elseif ($rate >= 75) $reliability = 'Good';
                                                            elseif ($rate >= 50) $reliability = 'Average';
                                                            else $reliability = 'Poor';
                                                            
                                                            $badgeClass = $reliability == 'Excellent' ? 'success' : ($reliability == 'Good' ? 'info' : ($reliability == 'Average' ? 'warning' : 'danger'));
                                                            ?>
                                                            <span class="badge bg-soft-<?= $badgeClass ?> text-<?= $badgeClass ?>"><?= $reliability ?></span>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                                    data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                                    data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'inactive'): ?>
                                                        <td><strong><?= htmlspecialchars($row['supplier_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['supplier_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end"><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td class="text-end text-danger"><?= $row['days_inactive'] ?? 'N/A' ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_purchases'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= $row['order_count'] ?? 0 ?></td>
                                                        <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                            ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                                        </td>
                                                        <td>
                                                            <?php if (($row['outstanding'] ?? 0) > 0): ?>
                                                                <span class="badge bg-soft-danger text-danger">Has Dues</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-secondary text-secondary">No Dues</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-supplier-btn" 
                                                                    data-supplier-id="<?= $row['supplier_id'] ?? '' ?>"
                                                                    data-supplier-name="<?= htmlspecialchars($row['supplier_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
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

<!-- Supplier Details Modal -->
<div class="modal fade" id="supplierDetailsModal" tabindex="-1" aria-labelledby="supplierDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierDetailsModalLabel">Supplier Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="supplierDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading supplier details...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" class="btn btn-primary" id="viewSupplierFullBtn" target="_blank">View Full Profile</a>
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

<!-- Chart JS -->
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Chart instances
    var topSuppliersChart = null;
    var agingChart = null;
    var performanceChart = null;

    // Initialize charts on page load
    <?php if ($report_type == 'summary' && !empty($chart_data['suppliers'])): ?>
    initTopSuppliersChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>
    
    <?php if ($report_type == 'aging' && !empty($chart_data['buckets'])): ?>
    initAgingChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>
    
    <?php if ($report_type == 'performance' && !empty($chart_data['metrics'])): ?>
    initPerformanceChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>

    // Initialize top suppliers chart
    function initTopSuppliersChart(data) {
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
                },
                labels: {
                    style: {
                        fontSize: '11px'
                    }
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

        if (topSuppliersChart) {
            topSuppliersChart.destroy();
        }
        topSuppliersChart = new ApexCharts(document.querySelector("#top-suppliers-chart"), options);
        topSuppliersChart.render();
    }

    // Initialize aging chart
    function initAgingChart(data) {
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
                    horizontal: false,
                    columnWidth: '55%',
                    endingShape: 'rounded'
                },
            },
            dataLabels: {
                enabled: false
            },
            series: [{
                name: 'Outstanding Amount',
                data: data.amounts || []
            }],
            xaxis: {
                categories: data.buckets || [],
                title: {
                    text: 'Aging Bucket'
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
            colors: ['#34c38f', '#50a5f1', '#f1b44c', '#f46a6a', '#f46a6a'],
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };

        if (agingChart) {
            agingChart.destroy();
        }
        agingChart = new ApexCharts(document.querySelector("#aging-chart"), options);
        agingChart.render();
    }

    // Initialize performance chart
    function initPerformanceChart(data) {
        const metrics = data.metrics || {};
        const options = {
            chart: {
                height: 350,
                type: 'radar',
                toolbar: {
                    show: true
                }
            },
            series: [{
                name: 'Performance Metrics',
                data: [
                    metrics['Total Orders'] || 0,
                    metrics['Total Purchases'] ? metrics['Total Purchases'] / 100000 : 0, // Scale down for display
                    metrics['Avg On-Time Rate'] || 0,
                    metrics['Avg Delivery Days'] ? 30 - (metrics['Avg Delivery Days'] || 0) : 30 // Invert for better visualization
                ]
            }],
            xaxis: {
                categories: ['Orders', 'Purchases (Lakhs)', 'On-Time %', 'Delivery Speed']
            },
            yaxis: {
                show: false
            },
            colors: ['#556ee6'],
            tooltip: {
                y: {
                    formatter: function(val, { seriesIndex, dataPointIndex }) {
                        const labels = ['Total Orders', 'Total Purchases', 'Avg On-Time Rate', 'Avg Delivery Days'];
                        const values = [
                            metrics['Total Orders'] || 0,
                            '₹' + (metrics['Total Purchases'] || 0).toFixed(2),
                            (metrics['Avg On-Time Rate'] || 0).toFixed(1) + '%',
                            (metrics['Avg Delivery Days'] || 0).toFixed(1) + ' days'
                        ];
                        return labels[dataPointIndex] + ': ' + values[dataPointIndex];
                    }
                }
            }
        };

        if (performanceChart) {
            performanceChart.destroy();
        }
        performanceChart = new ApexCharts(document.querySelector("#performance-chart"), options);
        performanceChart.render();
    }

    // Report type buttons
    document.getElementById('btnSummary')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('summary');
    });

    document.getElementById('btnDetailed')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('detailed');
    });

    document.getElementById('btnAging')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('aging');
    });

    document.getElementById('btnPerformance')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('performance');
    });

    document.getElementById('btnInactive')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('inactive');
    });

    // Update report type and load data
    function updateReportType(type) {
        document.getElementById('report_type').value = type;
        
        // Update button styles
        const buttons = [
            { id: 'btnSummary', type: 'summary' },
            { id: 'btnDetailed', type: 'detailed' },
            { id: 'btnAging', type: 'aging' },
            { id: 'btnPerformance', type: 'performance' },
            { id: 'btnInactive', type: 'inactive' }
        ];
        
        buttons.forEach(btn => {
            const element = document.getElementById(btn.id);
            if (element) {
                if (btn.type === type) {
                    element.className = 'btn btn-primary';
                } else {
                    element.className = 'btn btn-outline-primary';
                }
            }
        });
        
        // Load report data
        loadReportData();
    }

    // Apply filter button
    document.getElementById('applyFilterBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        loadReportData();
    });

    // Reset filter button
    document.getElementById('resetFilterBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Reset form fields
        document.getElementById('date_from').value = '<?= date('Y-m-01') ?>';
        document.getElementById('date_to').value = '<?= date('Y-m-d') ?>';
        document.getElementById('supplier_id').value = '';
        document.getElementById('min_purchases').value = '';
        document.getElementById('sort_by').value = 'purchases_desc';
        document.getElementById('search').value = '';
        
        // Load report data
        loadReportData();
    });

    // Export button
    document.getElementById('btnExport')?.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Build export URL with current filters
        const params = new URLSearchParams();
        params.append('export', 'csv');
        params.append('report_type', document.getElementById('report_type').value);
        params.append('date_from', document.getElementById('date_from').value);
        params.append('date_to', document.getElementById('date_to').value);
        params.append('supplier_id', document.getElementById('supplier_id').value);
        params.append('min_purchases', document.getElementById('min_purchases').value);
        params.append('sort_by', document.getElementById('sort_by').value);
        params.append('search', document.getElementById('search').value);
        
        window.location.href = 'supplier-report.php?' + params.toString();
    });

    // Load report data via AJAX
    function loadReportData() {
        const reportType = document.getElementById('report_type').value;
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const supplierId = document.getElementById('supplier_id').value;
        const minPurchases = document.getElementById('min_purchases').value;
        const sortBy = document.getElementById('sort_by').value;
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
        url.searchParams.set('min_purchases', minPurchases);
        url.searchParams.set('sort_by', sortBy);
        url.searchParams.set('search', search);
        
        fetch(url.toString())
            .then(response => response.json())
            .then(data => {
                // Hide loading indicator
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('reportContent').style.opacity = '1';
                
                if (data.success) {
                    // Update report content
                    document.getElementById('reportContent').innerHTML = data.html;
                    
                    // Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Report loaded successfully!',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    // Reinitialize charts based on report type
                    setTimeout(function() {
                        if (data.report_type === 'summary' && data.chart_data && data.chart_data.suppliers && data.chart_data.suppliers.length > 0) {
                            initTopSuppliersChart(data.chart_data);
                        } else if (data.report_type === 'aging' && data.chart_data && data.chart_data.buckets && data.chart_data.buckets.length > 0) {
                            initAgingChart(data.chart_data);
                        } else if (data.report_type === 'performance' && data.chart_data && data.chart_data.metrics) {
                            initPerformanceChart(data.chart_data);
                        }
                        
                        // Reattach view buttons
                        attachViewButtons();
                    }, 100);
                } else {
                    // Show error message
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to load report data',
                        confirmButtonColor: '#556ee6'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                
                // Hide loading indicator
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('reportContent').style.opacity = '1';
                
                // Show error message
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while loading the report',
                    confirmButtonColor: '#556ee6'
                });
            });
    }

    // Attach view buttons
    function attachViewButtons() {
        document.querySelectorAll('.view-supplier-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const supplierId = this.dataset.supplierId;
                const supplierName = this.dataset.supplierName;
                const dateFrom = document.getElementById('date_from').value;
                const dateTo = document.getElementById('date_to').value;
                
                if (supplierId) {
                    showSupplierDetails(supplierId, supplierName, dateFrom, dateTo);
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Warning',
                        text: 'Invalid supplier ID',
                        confirmButtonColor: '#556ee6'
                    });
                }
            });
        });
    }

    // Show supplier details
    function showSupplierDetails(supplierId, supplierName, dateFrom, dateTo) {
        const modal = new bootstrap.Modal(document.getElementById('supplierDetailsModal'));
        const modalTitle = document.getElementById('supplierDetailsModalLabel');
        const modalContent = document.getElementById('supplierDetailsContent');
        const viewFullBtn = document.getElementById('viewSupplierFullBtn');
        
        modalTitle.textContent = `Supplier Details - ${supplierName || 'Unknown'}`;
        viewFullBtn.href = `view-supplier.php?id=${supplierId}`;
        modalContent.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading supplier details...</p>
            </div>
        `;
        
        modal.show();
        
        fetch(`supplier-report.php?ajax=supplier_details&supplier_id=${supplierId}&date_from=${dateFrom}&date_to=${dateTo}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySupplierDetails(data);
                } else {
                    modalContent.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalContent.innerHTML = '<div class="alert alert-danger">An error occurred while loading supplier details</div>';
            });
    }

    // Display supplier details
    function displaySupplierDetails(data) {
        const supplier = data.supplier || {};
        const orders = data.orders || [];
        const payments = data.payments || [];
        const aging = data.aging || {};
        
        let html = `
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Supplier Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Name:</strong></td>
                                    <td>${escapeHtml(supplier.name || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Code:</strong></td>
                                    <td>${escapeHtml(supplier.supplier_code || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Company:</strong></td>
                                    <td>${escapeHtml(supplier.company_name || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Phone:</strong></td>
                                    <td>${escapeHtml(supplier.phone || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Email:</strong></td>
                                    <td>${escapeHtml(supplier.email || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>GST:</strong></td>
                                    <td>${escapeHtml(supplier.gst_number || 'N/A')}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Purchase Summary</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Total Orders:</strong></td>
                                    <td>${supplier.total_orders || 0}</td>
                                </tr>
                                <tr>
                                    <td><strong>Total Purchases:</strong></td>
                                    <td>₹${parseFloat(supplier.total_purchases || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>Outstanding:</strong></td>
                                    <td class="text-danger">₹${parseFloat(supplier.current_outstanding || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>First Purchase:</strong></td>
                                    <td>${supplier.first_purchase_date ? new Date(supplier.first_purchase_date).toLocaleDateString() : 'Never'}</td>
                                </tr>
                                <tr>
                                    <td><strong>Last Purchase:</strong></td>
                                    <td>${supplier.last_purchase_date ? new Date(supplier.last_purchase_date).toLocaleDateString() : 'Never'}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Aging Analysis</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Current:</strong></td>
                                    <td>₹${parseFloat(aging.current || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>1-30 Days:</strong></td>
                                    <td>₹${parseFloat(aging.days_1_30 || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>31-60 Days:</strong></td>
                                    <td>₹${parseFloat(aging.days_31_60 || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>61-90 Days:</strong></td>
                                    <td>₹${parseFloat(aging.days_61_90 || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>90+ Days:</strong></td>
                                    <td class="text-danger">₹${parseFloat(aging.days_90_plus || 0).toFixed(2)}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <h6 class="mb-3">Recent Purchase Orders</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>PO #</th>
                            <th>Date</th>
                            <th>Expected Delivery</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        if (orders.length > 0) {
            orders.forEach(order => {
                const statusClass = order.status === 'completed' ? 'success' : (order.status === 'cancelled' ? 'danger' : 'warning');
                html += `
                    <tr>
                        <td>${escapeHtml(order.po_number || '')}</td>
                        <td>${order.order_date ? new Date(order.order_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${order.expected_delivery ? new Date(order.expected_delivery).toLocaleDateString() : 'N/A'}</td>
                        <td class="text-end">₹${parseFloat(order.total_amount || 0).toFixed(2)}</td>
                        <td><span class="badge bg-soft-${statusClass} text-${statusClass}">${order.status || 'N/A'}</span></td>
                    </tr>
                `;
            });
        } else {
            html += `<tr><td colspan="5" class="text-center">No purchase orders found</td></tr>`;
        }
        
        html += `
                    </tbody>
                </table>
            </div>
            
            <h6 class="mb-3 mt-4">Recent Payments</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>PO #</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Balance After</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        if (payments.length > 0) {
            payments.forEach(payment => {
                html += `
                    <tr>
                        <td>${payment.transaction_date ? new Date(payment.transaction_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${escapeHtml(payment.po_number || 'N/A')}</td>
                        <td class="text-end text-success">₹${parseFloat(payment.amount || 0).toFixed(2)}</td>
                        <td class="text-end">₹${parseFloat(payment.balance_after || 0).toFixed(2)}</td>
                    </tr>
                `;
            });
        } else {
            html += `<tr><td colspan="4" class="text-center">No payment history found</td></tr>`;
        }
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        document.getElementById('supplierDetailsContent').innerHTML = html;
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initial attachment of view buttons
    setTimeout(function() {
        attachViewButtons();
    }, 500);
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

/* Aging bucket colors */
.aging-critical {
    background-color: #f8d7da;
}
.aging-warning {
    background-color: #fff3cd;
}
.aging-current {
    background-color: #d4edda;
}

/* Modal styles */
.modal-xl {
    max-width: 95%;
}

@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
}

/* SweetAlert2 customization */
.swal2-popup {
    font-family: inherit;
}
</style>

</body>
</html>