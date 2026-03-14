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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary'; // summary, detailed, aging, loyalty, inactive
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$min_sales = isset($_GET['min_sales']) ? floatval($_GET['min_sales']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, active, inactive
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'sales_desc'; // sales_desc, name_asc, outstanding_desc
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle AJAX request for customer details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'customer_details' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    
    try {
        $customer_id = intval($_GET['customer_id']);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        // Get customer details
        $customerStmt = $pdo->prepare("
            SELECT c.*, 
                   COUNT(DISTINCT i.id) as total_invoices,
                   COALESCE(SUM(i.total_amount), 0) as total_sales,
                   COALESCE(SUM(i.paid_amount), 0) as total_paid,
                   c.outstanding_balance as current_outstanding,
                   MAX(i.invoice_date) as last_purchase_date,
                   MIN(i.invoice_date) as first_purchase_date
            FROM customers c
            LEFT JOIN invoices i ON c.id = i.customer_id AND i.status != 'cancelled'
            WHERE c.id = :customer_id
            GROUP BY c.id
        ");
        $customerStmt->execute([':customer_id' => $customer_id]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }
        
        // Get recent invoices
        $invoiceStmt = $pdo->prepare("
            SELECT 
                invoice_number,
                invoice_date,
                due_date,
                total_amount,
                paid_amount,
                outstanding_amount,
                status,
                DATEDIFF(CURDATE(), due_date) as days_overdue
            FROM invoices
            WHERE customer_id = :customer_id
            AND invoice_date BETWEEN :date_from AND :date_to
            ORDER BY invoice_date DESC
            LIMIT 20
        ");
        $invoiceStmt->execute([
            ':customer_id' => $customer_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payment history
        $paymentStmt = $pdo->prepare("
            SELECT 
                co.transaction_date,
                co.amount,
                co.balance_after,
                i.invoice_number,
                co.reference_id
            FROM customer_outstanding co
            LEFT JOIN invoices i ON co.reference_id = i.id
            WHERE co.customer_id = :customer_id
            AND co.transaction_type = 'payment'
            AND co.transaction_date BETWEEN :date_from AND :date_to
            ORDER BY co.transaction_date DESC
            LIMIT 20
        ");
        $paymentStmt->execute([
            ':customer_id' => $customer_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get aging analysis
        $agingStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 0 THEN outstanding_amount ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN outstanding_amount ELSE 0 END), 0) as days_1_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN outstanding_amount ELSE 0 END), 0) as days_31_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN outstanding_amount ELSE 0 END), 0) as days_61_90,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN outstanding_amount ELSE 0 END), 0) as days_90_plus
            FROM invoices
            WHERE customer_id = :customer_id
            AND status IN ('sent', 'partially_paid', 'overdue')
            AND outstanding_amount > 0
        ");
        $agingStmt->execute([':customer_id' => $customer_id]);
        $aging = $agingStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customer' => $customer,
            'invoices' => $invoices,
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
        $customer_id = $_GET['customer_id'] ?? '';
        $min_sales = isset($_GET['min_sales']) ? floatval($_GET['min_sales']) : 0;
        $status = $_GET['status'] ?? 'all';
        $sort_by = $_GET['sort_by'] ?? 'sales_desc';
        $search = $_GET['search'] ?? '';
        
        // Get report data based on type
        $report_data = [];
        $summary = [];
        $chart_data = [];
        
        switch ($report_type) {
            case 'summary':
                $result = getCustomerSummaryReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'detailed':
                $result = getCustomerDetailedReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'aging':
                $result = getCustomerAgingReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'loyalty':
                $result = getCustomerLoyaltyReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
                $report_data = $result['data'];
                $summary = $result['summary'];
                $chart_data = $result['chart_data'];
                break;
                
            case 'inactive':
                $result = getCustomerInactiveReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
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
                                        <i class="mdi mdi-account-group font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Customers</p>
                                <h4><?= number_format($summary['total_customers'] ?? 0) ?></h4>
                                <small class="text-muted">Active: <?= number_format($summary['active_customers'] ?? 0) ?></small>
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
                                        <i class="mdi mdi-cash-multiple font-size-24"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <p class="text-muted mb-2">Total Sales</p>
                                <h4>₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h4>
                                <small class="text-muted"><?= number_format($summary['total_invoices'] ?? 0) ?> invoices</small>
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
                                <p class="text-muted mb-2">Avg. per Customer</p>
                                <h4>₹<?= number_format($summary['avg_per_customer'] ?? 0, 2) ?></h4>
                                <small class="text-muted">Average sale</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($report_type == 'summary' && !empty($chart_data['customers'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Top Customers by Sales</h4>
                        <div id="top-customers-chart" class="apex-charts" dir="ltr"></div>
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
        <?php elseif ($report_type == 'loyalty' && !empty($chart_data['categories'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Customer Loyalty Distribution</h4>
                        <div id="loyalty-chart" class="apex-charts" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Customer Details</h4>
                        <div class="table-responsive">
                            <table class="table table-centered table-nowrap mb-0">
                                <thead class="thead-light">
                                    <?php if ($report_type == 'summary'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Phone</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">Invoices</th>
                                        <th class="text-end">Avg. per Invoice</th>
                                        <th class="text-end">Outstanding</th>
                                        <th>Last Purchase</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'detailed'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Outstanding</th>
                                        <th class="text-end">Invoices</th>
                                        <th>First Purchase</th>
                                        <th>Last Purchase</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'aging'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
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
                                    <?php elseif ($report_type == 'loyalty'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Phone</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">Invoices</th>
                                        <th class="text-end">Avg. Value</th>
                                        <th>First Purchase</th>
                                        <th>Last Purchase</th>
                                        <th>Days Active</th>
                                        <th>Frequency</th>
                                        <th>Action</th>
                                    </tr>
                                    <?php elseif ($report_type == 'inactive'): ?>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Phone</th>
                                        <th class="text-end">Last Sale</th>
                                        <th class="text-end">Days Inactive</th>
                                        <th class="text-end">Total Sales</th>
                                        <th class="text-end">Invoices</th>
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
                                            <p class="mt-2">No customer data found for selected filters</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php if ($report_type == 'summary'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_invoice'] ?? 0, 2) ?></td>
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
                                                <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                        data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'detailed'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_paid'] ?? 0, 2) ?></td>
                                            <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                            </td>
                                            <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                            <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                            <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                        data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'aging'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
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
                                                <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                        data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'loyalty'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                            <td class="text-end">₹<?= number_format($row['avg_invoice'] ?? 0, 2) ?></td>
                                            <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                            <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td><?= $row['days_active'] ?? 'New' ?></td>
                                            <td>
                                                <?php
                                                $frequency = 'New';
                                                $inv_count = $row['invoice_count'] ?? 0;
                                                if ($inv_count >= 20) $frequency = 'Very High';
                                                elseif ($inv_count >= 10) $frequency = 'High';
                                                elseif ($inv_count >= 5) $frequency = 'Medium';
                                                elseif ($inv_count >= 2) $frequency = 'Low';
                                                ?>
                                                <span class="badge bg-soft-<?= $frequency == 'Very High' ? 'success' : ($frequency == 'High' ? 'info' : ($frequency == 'Medium' ? 'warning' : 'secondary')) ?>">
                                                    <?= $frequency ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                        data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </td>
                                            
                                            <?php elseif ($report_type == 'inactive'): ?>
                                            <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                            <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                            <td class="text-end"><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                            <td class="text-end text-danger"><?= $row['days_inactive'] ?? 'N/A' ?></td>
                                            <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                            <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
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
                                                <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                        data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                        data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
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

// Function to get customer summary report
function getCustomerSummaryReport($pdo, $date_from, $date_to, $customer_id = '', $min_sales = 0, $status = 'all', $sort_by = 'sales_desc', $search = '') {
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            c.email,
            c.outstanding_balance as outstanding,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            COALESCE(AVG(i.total_amount), 0) as avg_invoice,
            MAX(i.invoice_date) as last_purchase,
            MIN(i.invoice_date) as first_purchase
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id 
            AND i.invoice_date BETWEEN :date_from AND :date_to
            AND i.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone, c.email, c.outstanding_balance";
    
    // Apply having clause for min_sales
    if ($min_sales > 0) {
        $query .= " HAVING total_sales >= :min_sales";
        $params[':min_sales'] = $min_sales;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'sales_desc':
            $query .= " ORDER BY total_sales DESC";
            break;
        case 'sales_asc':
            $query .= " ORDER BY total_sales ASC";
            break;
        case 'name_asc':
            $query .= " ORDER BY c.name ASC";
            break;
        case 'outstanding_desc':
            $query .= " ORDER BY outstanding DESC";
            break;
        case 'last_purchase_desc':
            $query .= " ORDER BY last_purchase DESC";
            break;
        default:
            $query .= " ORDER BY total_sales DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_customers' => 0,
        'active_customers' => 0,
        'total_sales' => 0,
        'total_invoices' => 0,
        'total_outstanding' => 0,
        'overdue_count' => 0,
        'avg_per_customer' => 0
    ];
    
    $customers_with_sales = 0;
    
    foreach ($data as $row) {
        $summary['total_customers']++;
        if (($row['total_sales'] ?? 0) > 0) {
            $customers_with_sales++;
            $summary['active_customers']++;
        }
        $summary['total_sales'] += $row['total_sales'] ?? 0;
        $summary['total_invoices'] += $row['invoice_count'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        
        // Check for overdue (simplified - in real scenario would check invoices)
        if (($row['outstanding'] ?? 0) > 0) {
            $summary['overdue_count']++;
        }
    }
    
    if ($customers_with_sales > 0) {
        $summary['avg_per_customer'] = $summary['total_sales'] / $customers_with_sales;
    }
    
    // Prepare chart data
    $chart_data = [
        'customers' => [],
        'sales' => []
    ];
    
    $top_customers = array_slice($data, 0, 10);
    foreach ($top_customers as $row) {
        if (($row['total_sales'] ?? 0) > 0) {
            $chart_data['customers'][] = strlen($row['customer_name'] ?? '') > 20 ? substr($row['customer_name'] ?? '', 0, 20) . '...' : ($row['customer_name'] ?? '');
            $chart_data['sales'][] = $row['total_sales'] ?? 0;
        }
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get customer detailed report
function getCustomerDetailedReport($pdo, $date_from, $date_to, $customer_id = '', $min_sales = 0, $status = 'all', $sort_by = 'sales_desc', $search = '') {
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            c.email,
            c.address,
            c.city,
            c.state,
            c.gst_number,
            c.outstanding_balance as outstanding,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            COALESCE(SUM(i.paid_amount), 0) as total_paid,
            MAX(i.invoice_date) as last_purchase,
            MIN(i.invoice_date) as first_purchase,
            COALESCE(SUM(i.discount_amount), 0) as total_discount,
            COALESCE(SUM(i.gst_total), 0) as total_gst
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id 
            AND i.invoice_date BETWEEN :date_from AND :date_to
            AND i.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone, c.email, c.address, c.city, c.state, c.gst_number, c.outstanding_balance";
    
    // Apply having clause for min_sales
    if ($min_sales > 0) {
        $query .= " HAVING total_sales >= :min_sales";
        $params[':min_sales'] = $min_sales;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'sales_desc':
            $query .= " ORDER BY total_sales DESC";
            break;
        case 'name_asc':
            $query .= " ORDER BY c.name ASC";
            break;
        case 'outstanding_desc':
            $query .= " ORDER BY outstanding DESC";
            break;
        default:
            $query .= " ORDER BY total_sales DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_customers' => count($data),
        'total_sales' => 0,
        'total_paid' => 0,
        'total_outstanding' => 0,
        'total_invoices' => 0,
        'total_discount' => 0,
        'total_gst' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'] ?? 0;
        $summary['total_paid'] += $row['total_paid'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        $summary['total_invoices'] += $row['invoice_count'] ?? 0;
        $summary['total_discount'] += $row['total_discount'] ?? 0;
        $summary['total_gst'] += $row['total_gst'] ?? 0;
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => []];
}

// Function to get customer aging report
function getCustomerAgingReport($pdo, $date_from, $date_to, $customer_id = '', $min_sales = 0, $status = 'all', $sort_by = 'outstanding_desc', $search = '') {
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            c.outstanding_balance as total_outstanding,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 0 THEN i.outstanding_amount ELSE 0 END), 0) as current,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.outstanding_amount ELSE 0 END), 0) as days_1_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.outstanding_amount ELSE 0 END), 0) as days_31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.outstanding_amount ELSE 0 END), 0) as days_61_90,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.outstanding_amount ELSE 0 END), 0) as days_90_plus
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id 
            AND i.status IN ('sent', 'partially_paid', 'overdue')
            AND i.outstanding_amount > 0
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone, c.outstanding_balance";
    
    // Apply having clause for min outstanding
    if ($min_sales > 0) {
        $query .= " HAVING total_outstanding >= :min_outstanding";
        $params[':min_outstanding'] = $min_sales;
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
        'total_customers' => 0,
        'total_outstanding' => 0,
        'current' => 0,
        'days_1_30' => 0,
        'days_31_60' => 0,
        'days_61_90' => 0,
        'days_90_plus' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_customers']++;
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

// Function to get customer loyalty report
function getCustomerLoyaltyReport($pdo, $date_from, $date_to, $customer_id = '', $min_sales = 0, $status = 'all', $sort_by = 'invoice_count_desc', $search = '') {
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            COALESCE(AVG(i.total_amount), 0) as avg_invoice,
            MAX(i.invoice_date) as last_purchase,
            MIN(i.invoice_date) as first_purchase,
            DATEDIFF(COALESCE(MAX(i.invoice_date), CURDATE()), COALESCE(MIN(i.invoice_date), CURDATE())) as active_days,
            COALESCE(SUM(i.discount_amount), 0) as total_discount
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id 
            AND i.invoice_date BETWEEN :date_from AND :date_to
            AND i.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to
    ];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone";
    
    // Apply having clause for min sales
    if ($min_sales > 0) {
        $query .= " HAVING total_sales >= :min_sales";
        $params[':min_sales'] = $min_sales;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'invoice_count_desc':
            $query .= " ORDER BY invoice_count DESC";
            break;
        case 'invoice_count_asc':
            $query .= " ORDER BY invoice_count ASC";
            break;
        case 'sales_desc':
            $query .= " ORDER BY total_sales DESC";
            break;
        case 'last_purchase_desc':
            $query .= " ORDER BY last_purchase DESC";
            break;
        default:
            $query .= " ORDER BY invoice_count DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add derived fields
    foreach ($data as &$row) {
        if (($row['active_days'] ?? 0) > 0 && ($row['invoice_count'] ?? 0) > 0) {
            $row['days_active'] = ceil(($row['active_days'] ?? 0) / 30) . ' months';
        } else {
            $row['days_active'] = 'New';
        }
    }
    
    // Calculate summary
    $summary = [
        'total_customers' => count($data),
        'total_sales' => 0,
        'total_invoices' => 0,
        'avg_sales' => 0,
        'avg_invoices' => 0,
        'repeat_customers' => 0,
        'one_time_customers' => 0
    ];
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'] ?? 0;
        $summary['total_invoices'] += $row['invoice_count'] ?? 0;
        
        if (($row['invoice_count'] ?? 0) > 1) {
            $summary['repeat_customers']++;
        } else {
            $summary['one_time_customers']++;
        }
    }
    
    if ($summary['total_customers'] > 0) {
        $summary['avg_sales'] = $summary['total_sales'] / $summary['total_customers'];
        $summary['avg_invoices'] = $summary['total_invoices'] / $summary['total_customers'];
    }
    
    // Prepare chart data
    $chart_data = [
        'categories' => ['One-time', 'Repeat (2-5)', 'Regular (6-10)', 'Frequent (11-20)', 'VIP (20+)'],
        'counts' => [0, 0, 0, 0, 0]
    ];
    
    foreach ($data as $row) {
        $inv_count = $row['invoice_count'] ?? 0;
        if ($inv_count == 1) {
            $chart_data['counts'][0]++;
        } elseif ($inv_count <= 5) {
            $chart_data['counts'][1]++;
        } elseif ($inv_count <= 10) {
            $chart_data['counts'][2]++;
        } elseif ($inv_count <= 20) {
            $chart_data['counts'][3]++;
        } else {
            $chart_data['counts'][4]++;
        }
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => $chart_data];
}

// Function to get customer inactive report
function getCustomerInactiveReport($pdo, $date_from, $date_to, $customer_id = '', $min_sales = 0, $status = 'all', $sort_by = 'last_purchase_asc', $search = '') {
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.customer_code,
            c.phone,
            c.email,
            c.outstanding_balance as outstanding,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_sales,
            MAX(i.invoice_date) as last_purchase,
            DATEDIFF(CURDATE(), COALESCE(MAX(i.invoice_date), c.created_at)) as days_inactive
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id 
            AND i.status NOT IN ('cancelled', 'draft')
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($customer_id)) {
        $query .= " AND c.id = :customer_id";
        $params[':customer_id'] = $customer_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (c.name LIKE :search OR c.customer_code LIKE :search OR c.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY c.id, c.name, c.customer_code, c.phone, c.email, c.outstanding_balance, c.created_at";
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
        'total_customers' => count($data),
        'total_sales' => 0,
        'total_outstanding' => 0,
        'total_invoices' => 0,
        'avg_days_inactive' => 0
    ];
    
    $total_days = 0;
    
    foreach ($data as $row) {
        $summary['total_sales'] += $row['total_sales'] ?? 0;
        $summary['total_outstanding'] += $row['outstanding'] ?? 0;
        $summary['total_invoices'] += $row['invoice_count'] ?? 0;
        $total_days += $row['days_inactive'] ?? 0;
    }
    
    if ($summary['total_customers'] > 0) {
        $summary['avg_days_inactive'] = ceil($total_days / $summary['total_customers']);
    }
    
    return ['data' => $data, 'summary' => $summary, 'chart_data' => []];
}

// Get all customers for dropdown
try {
    $customersStmt = $pdo->query("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
    error_log("Error fetching customers: " . $e->getMessage());
}

// Get initial data for page load
$report_data = [];
$summary = [];
$chart_data = [];

try {
    switch ($report_type) {
        case 'summary':
            $result = getCustomerSummaryReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'detailed':
            $result = getCustomerDetailedReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'aging':
            $result = getCustomerAgingReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'loyalty':
            $result = getCustomerLoyaltyReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
            $report_data = $result['data'];
            $summary = $result['summary'];
            $chart_data = $result['chart_data'];
            break;
            
        case 'inactive':
            $result = getCustomerInactiveReport($pdo, $date_from, $date_to, $customer_id, $min_sales, $status, $sort_by, $search);
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
    $filename = 'customer_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Customer Report - ' . ucfirst(str_replace('_', ' ', $report_type))]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    fputcsv($output, []);
    
    // Add headers based on report type
    if ($report_type == 'summary') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Total Sales', 'Invoices', 'Avg per Invoice', 'Outstanding', 'Last Purchase']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'] ?? '',
                $row['customer_code'] ?? '',
                $row['phone'] ?? '',
                number_format($row['total_sales'] ?? 0, 2),
                $row['invoice_count'] ?? 0,
                number_format($row['avg_invoice'] ?? 0, 2),
                number_format($row['outstanding'] ?? 0, 2),
                $row['last_purchase'] ?? 'Never'
            ]);
        }
    } elseif ($report_type == 'aging') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total Outstanding']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'] ?? '',
                $row['customer_code'] ?? '',
                $row['phone'] ?? '',
                number_format($row['current'] ?? 0, 2),
                number_format($row['days_1_30'] ?? 0, 2),
                number_format($row['days_31_60'] ?? 0, 2),
                number_format($row['days_61_90'] ?? 0, 2),
                number_format($row['days_90_plus'] ?? 0, 2),
                number_format($row['total_outstanding'] ?? 0, 2)
            ]);
        }
    } elseif ($report_type == 'loyalty') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Total Sales', 'Invoices', 'Avg Value', 'First Purchase', 'Last Purchase', 'Days Active']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'] ?? '',
                $row['customer_code'] ?? '',
                $row['phone'] ?? '',
                number_format($row['total_sales'] ?? 0, 2),
                $row['invoice_count'] ?? 0,
                number_format($row['avg_invoice'] ?? 0, 2),
                $row['first_purchase'] ?? 'Never',
                $row['last_purchase'] ?? 'Never',
                $row['days_active'] ?? 'New'
            ]);
        }
    } elseif ($report_type == 'inactive') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Last Sale', 'Days Inactive', 'Total Sales', 'Invoices', 'Outstanding']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'] ?? '',
                $row['customer_code'] ?? '',
                $row['phone'] ?? '',
                $row['last_purchase'] ?? 'Never',
                $row['days_inactive'] ?? 'N/A',
                number_format($row['total_sales'] ?? 0, 2),
                $row['invoice_count'] ?? 0,
                number_format($row['outstanding'] ?? 0, 2)
            ]);
        }
    } elseif ($report_type == 'detailed') {
        fputcsv($output, ['Customer', 'Code', 'Phone', 'Email', 'Total Sales', 'Paid', 'Outstanding', 'Invoices', 'First Purchase', 'Last Purchase']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'] ?? '',
                $row['customer_code'] ?? '',
                $row['phone'] ?? '',
                $row['email'] ?? '',
                number_format($row['total_sales'] ?? 0, 2),
                number_format($row['total_paid'] ?? 0, 2),
                number_format($row['outstanding'] ?? 0, 2),
                $row['invoice_count'] ?? 0,
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
                            <h4 class="mb-0 font-size-18">Customer Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Customer Report</li>
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
                                    <button type="button" class="btn btn-<?= $report_type == 'loyalty' ? 'primary' : 'outline-primary' ?>" id="btnLoyalty">
                                        <i class="mdi mdi-star"></i> Loyalty
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
                                <form method="GET" action="customer-report.php" class="row" id="filterForm">
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
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="min_sales" class="form-label">Min Sales (₹)</label>
                                            <input type="number" step="1000" class="form-control" id="min_sales" name="min_sales" value="<?= $min_sales > 0 ? $min_sales : '' ?>" placeholder="0">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="sort_by" class="form-label">Sort By</label>
                                            <select class="form-control" id="sort_by" name="sort_by">
                                                <option value="sales_desc" <?= $sort_by == 'sales_desc' ? 'selected' : '' ?>>Highest Sales</option>
                                                <option value="sales_asc" <?= $sort_by == 'sales_asc' ? 'selected' : '' ?>>Lowest Sales</option>
                                                <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                                                <option value="outstanding_desc" <?= $sort_by == 'outstanding_desc' ? 'selected' : '' ?>>Highest Outstanding</option>
                                                <option value="last_purchase_desc" <?= $sort_by == 'last_purchase_desc' ? 'selected' : '' ?>>Recent Purchase</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="search" class="form-label">Search</label>
                                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, Code, Phone..." value="<?= htmlspecialchars($search) ?>">
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
                                                    <i class="mdi mdi-account-group font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Customers</p>
                                            <h4><?= number_format($summary['total_customers'] ?? 0) ?></h4>
                                            <small class="text-muted">Active: <?= number_format($summary['active_customers'] ?? 0) ?></small>
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
                                                    <i class="mdi mdi-cash-multiple font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Sales</p>
                                            <h4>₹<?= number_format($summary['total_sales'] ?? 0, 2) ?></h4>
                                            <small class="text-muted"><?= number_format($summary['total_invoices'] ?? 0) ?> invoices</small>
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
                                            <p class="text-muted mb-2">Avg. per Customer</p>
                                            <h4>₹<?= number_format($summary['avg_per_customer'] ?? 0, 2) ?></h4>
                                            <small class="text-muted">Average sale</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($report_type == 'summary' && !empty($chart_data['customers'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Top Customers by Sales</h4>
                                    <div id="top-customers-chart" class="apex-charts" dir="ltr"></div>
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
                    <?php elseif ($report_type == 'loyalty' && !empty($chart_data['categories'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Customer Loyalty Distribution</h4>
                                    <div id="loyalty-chart" class="apex-charts" dir="ltr"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Customer Details</h4>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <?php if ($report_type == 'summary'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">Invoices</th>
                                                    <th class="text-end">Avg. per Invoice</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th>Last Purchase</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'detailed'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
                                                    <th>Phone</th>
                                                    <th>Email</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">Paid</th>
                                                    <th class="text-end">Outstanding</th>
                                                    <th class="text-end">Invoices</th>
                                                    <th>First Purchase</th>
                                                    <th>Last Purchase</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'aging'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
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
                                                <?php elseif ($report_type == 'loyalty'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">Invoices</th>
                                                    <th class="text-end">Avg. Value</th>
                                                    <th>First Purchase</th>
                                                    <th>Last Purchase</th>
                                                    <th>Days Active</th>
                                                    <th>Frequency</th>
                                                    <th>Action</th>
                                                </tr>
                                                <?php elseif ($report_type == 'inactive'): ?>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Code</th>
                                                    <th>Phone</th>
                                                    <th class="text-end">Last Sale</th>
                                                    <th class="text-end">Days Inactive</th>
                                                    <th class="text-end">Total Sales</th>
                                                    <th class="text-end">Invoices</th>
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
                                                        <p class="mt-2">No customer data found for selected filters</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_data as $row): ?>
                                                    <tr>
                                                        <?php if ($report_type == 'summary'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_invoice'] ?? 0, 2) ?></td>
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
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                                    data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                                    data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'detailed'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_paid'] ?? 0, 2) ?></td>
                                                        <td class="text-end <?= ($row['outstanding'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                            ₹<?= number_format($row['outstanding'] ?? 0, 2) ?>
                                                        </td>
                                                        <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                                        <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                                        <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                                    data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                                    data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'aging'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
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
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                                    data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                                    data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'loyalty'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
                                                        <td class="text-end">₹<?= number_format($row['avg_invoice'] ?? 0, 2) ?></td>
                                                        <td><?= !empty($row['first_purchase']) ? date('d M Y', strtotime($row['first_purchase'])) : 'Never' ?></td>
                                                        <td><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td><?= $row['days_active'] ?? 'New' ?></td>
                                                        <td>
                                                            <?php
                                                            $frequency = 'New';
                                                            $inv_count = $row['invoice_count'] ?? 0;
                                                            if ($inv_count >= 20) $frequency = 'Very High';
                                                            elseif ($inv_count >= 10) $frequency = 'High';
                                                            elseif ($inv_count >= 5) $frequency = 'Medium';
                                                            elseif ($inv_count >= 2) $frequency = 'Low';
                                                            ?>
                                                            <span class="badge bg-soft-<?= $frequency == 'Very High' ? 'success' : ($frequency == 'High' ? 'info' : ($frequency == 'Medium' ? 'warning' : 'secondary')) ?>">
                                                                <?= $frequency ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                                    data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                                    data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
                                                                <i class="mdi mdi-eye"></i>
                                                            </button>
                                                        </td>
                                                        
                                                        <?php elseif ($report_type == 'inactive'): ?>
                                                        <td><strong><?= htmlspecialchars($row['customer_name'] ?? '') ?></strong></td>
                                                        <td><?= htmlspecialchars($row['customer_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                                        <td class="text-end"><?= !empty($row['last_purchase']) ? date('d M Y', strtotime($row['last_purchase'])) : 'Never' ?></td>
                                                        <td class="text-end text-danger"><?= $row['days_inactive'] ?? 'N/A' ?></td>
                                                        <td class="text-end">₹<?= number_format($row['total_sales'] ?? 0, 2) ?></td>
                                                        <td class="text-end"><?= $row['invoice_count'] ?? 0 ?></td>
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
                                                            <button type="button" class="btn btn-sm btn-soft-primary view-customer-btn" 
                                                                    data-customer-id="<?= $row['customer_id'] ?? '' ?>"
                                                                    data-customer-name="<?= htmlspecialchars($row['customer_name'] ?? '') ?>">
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

<!-- Customer Details Modal -->
<div class="modal fade" id="customerDetailsModal" tabindex="-1" aria-labelledby="customerDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerDetailsModalLabel">Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="customerDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading customer details...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" class="btn btn-primary" id="viewCustomerFullBtn" target="_blank">View Full Profile</a>
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
    var topCustomersChart = null;
    var agingChart = null;
    var loyaltyChart = null;

    // Initialize charts on page load
    <?php if ($report_type == 'summary' && !empty($chart_data['customers'])): ?>
    initTopCustomersChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>
    
    <?php if ($report_type == 'aging' && !empty($chart_data['buckets'])): ?>
    initAgingChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>
    
    <?php if ($report_type == 'loyalty' && !empty($chart_data['categories'])): ?>
    initLoyaltyChart(<?= json_encode($chart_data) ?>);
    <?php endif; ?>

    // Initialize top customers chart
    function initTopCustomersChart(data) {
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

        if (topCustomersChart) {
            topCustomersChart.destroy();
        }
        topCustomersChart = new ApexCharts(document.querySelector("#top-customers-chart"), options);
        topCustomersChart.render();
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

    // Initialize loyalty chart
    function initLoyaltyChart(data) {
        const options = {
            chart: {
                height: 350,
                type: 'pie'
            },
            series: data.counts || [],
            labels: data.categories || [],
            colors: ['#556ee6', '#34c38f', '#f46a6a', '#50a5f1', '#f1b44c'],
            legend: {
                position: 'bottom'
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return val + ' customers';
                    }
                }
            }
        };

        if (loyaltyChart) {
            loyaltyChart.destroy();
        }
        loyaltyChart = new ApexCharts(document.querySelector("#loyalty-chart"), options);
        loyaltyChart.render();
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

    document.getElementById('btnLoyalty')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('loyalty');
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
            { id: 'btnLoyalty', type: 'loyalty' },
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
        document.getElementById('customer_id').value = '';
        document.getElementById('min_sales').value = '';
        document.getElementById('sort_by').value = 'sales_desc';
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
        params.append('customer_id', document.getElementById('customer_id').value);
        params.append('min_sales', document.getElementById('min_sales').value);
        params.append('sort_by', document.getElementById('sort_by').value);
        params.append('search', document.getElementById('search').value);
        
        window.location.href = 'customer-report.php?' + params.toString();
    });

    // Load report data via AJAX
    function loadReportData() {
        const reportType = document.getElementById('report_type').value;
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const customerId = document.getElementById('customer_id').value;
        const minSales = document.getElementById('min_sales').value;
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
        url.searchParams.set('customer_id', customerId);
        url.searchParams.set('min_sales', minSales);
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
                        if (data.report_type === 'summary' && data.chart_data && data.chart_data.customers && data.chart_data.customers.length > 0) {
                            initTopCustomersChart(data.chart_data);
                        } else if (data.report_type === 'aging' && data.chart_data && data.chart_data.buckets && data.chart_data.buckets.length > 0) {
                            initAgingChart(data.chart_data);
                        } else if (data.report_type === 'loyalty' && data.chart_data && data.chart_data.categories && data.chart_data.categories.length > 0) {
                            initLoyaltyChart(data.chart_data);
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
        document.querySelectorAll('.view-customer-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const customerId = this.dataset.customerId;
                const customerName = this.dataset.customerName;
                const dateFrom = document.getElementById('date_from').value;
                const dateTo = document.getElementById('date_to').value;
                
                if (customerId) {
                    showCustomerDetails(customerId, customerName, dateFrom, dateTo);
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Warning',
                        text: 'Invalid customer ID',
                        confirmButtonColor: '#556ee6'
                    });
                }
            });
        });
    }

    // Show customer details
    function showCustomerDetails(customerId, customerName, dateFrom, dateTo) {
        const modal = new bootstrap.Modal(document.getElementById('customerDetailsModal'));
        const modalTitle = document.getElementById('customerDetailsModalLabel');
        const modalContent = document.getElementById('customerDetailsContent');
        const viewFullBtn = document.getElementById('viewCustomerFullBtn');
        
        modalTitle.textContent = `Customer Details - ${customerName || 'Unknown'}`;
        viewFullBtn.href = `view-customer.php?id=${customerId}`;
        modalContent.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading customer details...</p>
            </div>
        `;
        
        modal.show();
        
        fetch(`customer-report.php?ajax=customer_details&customer_id=${customerId}&date_from=${dateFrom}&date_to=${dateTo}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayCustomerDetails(data);
                } else {
                    modalContent.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalContent.innerHTML = '<div class="alert alert-danger">An error occurred while loading customer details</div>';
            });
    }

    // Display customer details
    function displayCustomerDetails(data) {
        const customer = data.customer || {};
        const invoices = data.invoices || [];
        const payments = data.payments || [];
        const aging = data.aging || {};
        
        let html = `
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Customer Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Name:</strong></td>
                                    <td>${escapeHtml(customer.name || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Code:</strong></td>
                                    <td>${escapeHtml(customer.customer_code || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Phone:</strong></td>
                                    <td>${escapeHtml(customer.phone || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Email:</strong></td>
                                    <td>${escapeHtml(customer.email || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td><strong>Credit Limit:</strong></td>
                                    <td>₹${parseFloat(customer.credit_limit || 0).toFixed(2)}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Sales Summary</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Total Invoices:</strong></td>
                                    <td>${customer.total_invoices || 0}</td>
                                </tr>
                                <tr>
                                    <td><strong>Total Sales:</strong></td>
                                    <td>₹${parseFloat(customer.total_sales || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>Total Paid:</strong></td>
                                    <td>₹${parseFloat(customer.total_paid || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>Outstanding:</strong></td>
                                    <td class="text-danger">₹${parseFloat(customer.current_outstanding || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td><strong>First Purchase:</strong></td>
                                    <td>${customer.first_purchase_date ? new Date(customer.first_purchase_date).toLocaleDateString() : 'Never'}</td>
                                </tr>
                                <tr>
                                    <td><strong>Last Purchase:</strong></td>
                                    <td>${customer.last_purchase_date ? new Date(customer.last_purchase_date).toLocaleDateString() : 'Never'}</td>
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
            
            <h6 class="mb-3">Recent Invoices</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Outstanding</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        if (invoices.length > 0) {
            invoices.forEach(inv => {
                const statusClass = inv.status === 'paid' ? 'success' : (inv.status === 'overdue' ? 'danger' : 'warning');
                html += `
                    <tr>
                        <td>${escapeHtml(inv.invoice_number || '')}</td>
                        <td>${inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${inv.due_date ? new Date(inv.due_date).toLocaleDateString() : 'N/A'}</td>
                        <td class="text-end">₹${parseFloat(inv.total_amount || 0).toFixed(2)}</td>
                        <td class="text-end">₹${parseFloat(inv.paid_amount || 0).toFixed(2)}</td>
                        <td class="text-end">₹${parseFloat(inv.outstanding_amount || 0).toFixed(2)}</td>
                        <td><span class="badge bg-soft-${statusClass} text-${statusClass}">${inv.status || 'N/A'}</span></td>
                    </tr>
                `;
            });
        } else {
            html += `<tr><td colspan="7" class="text-center">No invoices found</td></tr>`;
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
                            <th>Invoice</th>
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
                        <td>${escapeHtml(payment.invoice_number || 'N/A')}</td>
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
        
        document.getElementById('customerDetailsContent').innerHTML = html;
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