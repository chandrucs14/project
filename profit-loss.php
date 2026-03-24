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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly'; // monthly, quarterly, yearly, custom
$period = isset($_GET['period']) ? $_GET['period'] : date('Y-m'); // YYYY-MM for monthly, YYYY for yearly
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$compare_with = isset($_GET['compare_with']) ? $_GET['compare_with'] : 'previous'; // previous, last_year, budget
$show_details = isset($_GET['show_details']) ? $_GET['show_details'] : 'summary'; // summary, detailed, ratio

// Function to get profit loss data
function getProfitLossData($pdo, $date_from, $date_to) {
    
    // Get sales revenue from invoices
    $salesStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(gst_total), 0) as total_gst,
            COALESCE(SUM(discount_amount), 0) as total_discount
        FROM invoices
        WHERE invoice_date BETWEEN :date_from AND :date_to
        AND status NOT IN ('cancelled', 'draft')
    ");
    $salesStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get other income (if you have an income table, otherwise use 0)
    $other_income = 0; // You can modify this if you have other income sources
    
    // Get opening stock (from daywise_stock or products table)
    $openingStockStmt = $pdo->prepare("
        SELECT COALESCE(SUM(current_stock * cost_price), 0) as opening_stock
        FROM products
        WHERE created_at <= :date_from
    ");
    $openingStockStmt->execute([':date_from' => $date_from]);
    $opening_stock = $openingStockStmt->fetchColumn();
    
    // Get purchases from purchase_orders
    $purchasesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total_purchases
        FROM purchase_orders
        WHERE order_date BETWEEN :date_from AND :date_to
        AND status NOT IN ('cancelled', 'draft')
    ");
    $purchasesStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $purchases = $purchasesStmt->fetchColumn();
    
    // Get closing stock
    $closingStockStmt = $pdo->prepare("
        SELECT COALESCE(SUM(current_stock * cost_price), 0) as closing_stock
        FROM products
    ");
    $closingStockStmt->execute();
    $closing_stock = $closingStockStmt->fetchColumn();
    
    // Get expenses by category
    $expensesStmt = $pdo->prepare("
        SELECT 
            category,
            COALESCE(SUM(total_amount), 0) as amount
        FROM expenses
        WHERE expense_date BETWEEN :date_from AND :date_to
        GROUP BY category
    ");
    $expensesStmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Categorize expenses
    $admin_expenses = 0;
    $selling_expenses = 0;
    $financial_expenses = 0;
    $other_expenses = 0;
    $expense_categories = [];
    
    foreach ($expenses as $exp) {
        $category = $exp['category'];
        $amount = floatval($exp['amount']);
        $expense_categories[$category] = $amount;
        
        // Categorize based on category name
        if (stripos($category, 'salary') !== false || stripos($category, 'rent') !== false || 
            stripos($category, 'office') !== false || stripos($category, 'administrative') !== false) {
            $admin_expenses += $amount;
        } elseif (stripos($category, 'marketing') !== false || stripos($category, 'advertising') !== false || 
                  stripos($category, 'selling') !== false || stripos($category, 'commission') !== false) {
            $selling_expenses += $amount;
        } elseif (stripos($category, 'interest') !== false || stripos($category, 'bank') !== false || 
                  stripos($category, 'financial') !== false) {
            $financial_expenses += $amount;
        } else {
            $other_expenses += $amount;
        }
    }
    
    // Calculate interest and taxes (from financial expenses)
    $interest_taxes = $financial_expenses;
    
    // Calculate totals
    $sales_revenue = floatval($sales['total_sales'] ?? 0);
    $total_revenue = $sales_revenue + $other_income;
    
    $cogs = $opening_stock + $purchases - $closing_stock;
    $gross_profit = $sales_revenue - $cogs;
    
    $total_operating_expenses = $admin_expenses + $selling_expenses + $financial_expenses + $other_expenses;
    $operating_profit = $gross_profit - $total_operating_expenses;
    
    $net_profit = $operating_profit + $other_income - $interest_taxes;
    
    // Calculate percentages with division by zero check
    $total_revenue_safe = max($total_revenue, 1); // Avoid division by zero
    
    $gross_margin = ($gross_profit / $total_revenue_safe) * 100;
    $operating_margin = ($operating_profit / $total_revenue_safe) * 100;
    $net_margin = ($net_profit / $total_revenue_safe) * 100;
    
    $sales_revenue_percent = ($sales_revenue / $total_revenue_safe) * 100;
    $other_income_percent = ($other_income / $total_revenue_safe) * 100;
    $cogs_percent = ($cogs / $total_revenue_safe) * 100;
    $opening_stock_percent = ($opening_stock / $total_revenue_safe) * 100;
    $purchases_percent = ($purchases / $total_revenue_safe) * 100;
    $closing_stock_percent = ($closing_stock / $total_revenue_safe) * 100;
    $admin_expenses_percent = ($admin_expenses / $total_revenue_safe) * 100;
    $selling_expenses_percent = ($selling_expenses / $total_revenue_safe) * 100;
    $financial_expenses_percent = ($financial_expenses / $total_revenue_safe) * 100;
    $other_expenses_percent = ($other_expenses / $total_revenue_safe) * 100;
    $operating_expenses_percent = ($total_operating_expenses / $total_revenue_safe) * 100;
    $interest_taxes_percent = ($interest_taxes / $total_revenue_safe) * 100;
    
    return [
        // Revenue
        'sales_revenue' => abs($sales_revenue),
        'sales_revenue_percent' => abs($sales_revenue_percent),
        'other_income' => abs($other_income),
        'other_income_percent' => abs($other_income_percent),
        'total_revenue' => abs($total_revenue),
        
        // COGS
        'opening_stock' => abs($opening_stock),
        'opening_stock_percent' => abs($opening_stock_percent),
        'purchases' => abs($purchases),
        'purchases_percent' => abs($purchases_percent),
        'closing_stock' => abs($closing_stock),
        'closing_stock_percent' => abs($closing_stock_percent),
        'cogs' => abs($cogs),
        'cogs_percent' => abs($cogs_percent),
        
        // Gross Profit
        'gross_profit' => abs($gross_profit),
        'gross_margin' => abs($gross_margin),
        
        // Operating Expenses
        'admin_expenses' => abs($admin_expenses),
        'admin_expenses_percent' => abs($admin_expenses_percent),
        'selling_expenses' => abs($selling_expenses),
        'selling_expenses_percent' => abs($selling_expenses_percent),
        'financial_expenses' => abs($financial_expenses),
        'financial_expenses_percent' => abs($financial_expenses_percent),
        'other_expenses' => abs($other_expenses),
        'other_expenses_percent' => abs($other_expenses_percent),
        'total_operating_expenses' => abs($total_operating_expenses),
        'operating_expenses_percent' => abs($operating_expenses_percent),
        'expense_categories' => $expense_categories,
        
        // Operating Profit
        'operating_profit' => abs($operating_profit),
        'operating_margin' => abs($operating_margin),
        
        // Other
        'interest_taxes' => abs($interest_taxes),
        'interest_taxes_percent' => abs($interest_taxes_percent),
        
        // Net Profit
        'net_profit' => abs($net_profit),
        'net_margin' => abs($net_margin)
    ];
}

// Function to get comparison dates
function getComparisonDates($date_from, $date_to, $compare_with) {
    $from = new DateTime($date_from);
    $to = new DateTime($date_to);
    $interval = $from->diff($to);
    $days = $interval->days;
    
    switch ($compare_with) {
        case 'previous':
            // Previous period of same length
            $prev_to = clone $from;
            $prev_to->modify('-1 day');
            $prev_from = clone $prev_to;
            $prev_from->modify('-' . $days . ' days');
            return [
                'from' => $prev_from->format('Y-m-d'),
                'to' => $prev_to->format('Y-m-d')
            ];
            
        case 'last_year':
            // Same period last year
            $prev_from = clone $from;
            $prev_from->modify('-1 year');
            $prev_to = clone $to;
            $prev_to->modify('-1 year');
            return [
                'from' => $prev_from->format('Y-m-d'),
                'to' => $prev_to->format('Y-m-d')
            ];
            
        default:
            return null;
    }
}

// Function to format period
function formatPeriod($date_from, $date_to) {
    $from = new DateTime($date_from);
    $to = new DateTime($date_to);
    
    if ($from->format('Y-m') == $to->format('Y-m')) {
        return $from->format('M Y');
    } elseif ($from->format('Y') == $to->format('Y')) {
        return $from->format('M') . ' - ' . $to->format('M Y');
    } else {
        return $from->format('M Y') . ' - ' . $to->format('M Y');
    }
}

// Function to calculate financial ratios (with division by zero check)
function calculateFinancialRatios($current, $comparison = null) {
    $revenue = floatval($current['total_revenue']);
    $revenue_safe = max($revenue, 1); // Avoid division by zero
    
    $cogs = floatval($current['cogs']);
    $opex = floatval($current['total_operating_expenses']);
    $operating_profit = floatval($current['operating_profit']);
    $net_profit = floatval($current['net_profit']);
    $interest = floatval($current['interest_taxes']);
    $gross_profit = floatval($current['gross_profit']);
    $gross_margin = floatval($current['gross_margin']);
    
    return [
        'gross_margin' => $gross_margin,
        'operating_margin' => ($operating_profit / $revenue_safe) * 100,
        'net_margin' => ($net_profit / $revenue_safe) * 100,
        'return_on_sales' => ($net_profit / $revenue_safe) * 100,
        'operating_expense_ratio' => ($opex / $revenue_safe) * 100,
        'admin_expense_ratio' => (floatval($current['admin_expenses']) / $revenue_safe) * 100,
        'selling_expense_ratio' => (floatval($current['selling_expenses']) / $revenue_safe) * 100,
        'financial_expense_ratio' => (floatval($current['financial_expenses']) / $revenue_safe) * 100,
        'cogs_to_revenue' => ($cogs / $revenue_safe) * 100,
        'opex_to_revenue' => ($opex / $revenue_safe) * 100,
        'interest_coverage' => $interest > 0 ? $operating_profit / $interest : 0,
        'breakeven_point' => $gross_margin > 0 ? ($opex / ($gross_margin / 100)) : 0,
        'margin_of_safety' => $revenue > 0 ? (($revenue - ($opex / max($gross_margin / 100, 0.01))) / $revenue) * 100 : 0
    ];
}

// Helper functions for comparison (using absolute values with division by zero check)
function getChangeClass($current, $previous, $inverse = false) {
    $current_abs = abs(floatval($current));
    $previous_abs = abs(floatval($previous));
    $change = $current_abs - $previous_abs;
    if ($inverse) {
        return $change < 0 ? 'text-success' : ($change > 0 ? 'text-danger' : 'text-muted');
    }
    return $change > 0 ? 'text-success' : ($change < 0 ? 'text-danger' : 'text-muted');
}

function getChangeIcon($current, $previous, $inverse = false) {
    $current_abs = abs(floatval($current));
    $previous_abs = abs(floatval($previous));
    $change = $current_abs - $previous_abs;
    if ($inverse) {
        return $change < 0 ? 'mdi-arrow-down' : ($change > 0 ? 'mdi-arrow-up' : 'mdi-minus');
    }
    return $change > 0 ? 'mdi-arrow-up' : ($change < 0 ? 'mdi-arrow-down' : 'mdi-minus');
}

function getChangePercent($current, $previous) {
    $current_abs = abs(floatval($current));
    $previous_abs = abs(floatval($previous));
    if ($previous_abs == 0) return 0;
    return (($current_abs - $previous_abs) / $previous_abs) * 100;
}

// Get initial data for page load
$current_data = getProfitLossData($pdo, $date_from, $date_to);
$ratios = calculateFinancialRatios($current_data);

// Helper function to format amount without minus sign
function formatAmount($amount) {
    return number_format(abs(floatval($amount)), 2);
}

// Helper function to format percentage without minus sign
function formatPercentage($percentage) {
    return number_format(abs(floatval($percentage)), 1);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
                            <h4 class="mb-0 font-size-18">Profit & Loss Statement</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Profit & Loss</li>
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
                                    <a href="?report_type=monthly" class="btn btn-<?= $report_type == 'monthly' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-calendar-month"></i> Monthly
                                    </a>
                                    <a href="?report_type=quarterly" class="btn btn-<?= $report_type == 'quarterly' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-calendar-clock"></i> Quarterly
                                    </a>
                                    <a href="?report_type=yearly" class="btn btn-<?= $report_type == 'yearly' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-calendar"></i> Yearly
                                    </a>
                                    <a href="?report_type=custom" class="btn btn-<?= $report_type == 'custom' ? 'primary' : 'outline-primary' ?>">
                                        <i class="mdi mdi-calendar-range"></i> Custom
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

                <!-- Filter Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Report Options</h4>
                                <form method="GET" action="profit-loss.php" class="row" id="filterForm">
                                    <input type="hidden" name="report_type" id="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
                                    <div class="col-md-3" id="period_select" style="display: <?= $report_type != 'custom' ? 'block' : 'none' ?>;">
                                        <div class="mb-3">
                                            <label for="period" class="form-label">Select Period</label>
                                            <select class="form-control" id="period" name="period">
                                                <?php if ($report_type == 'monthly'): ?>
                                                    <?php for ($i = 0; $i < 12; $i++): 
                                                        $month = date('Y-m', strtotime("-$i months")); ?>
                                                        <option value="<?= $month ?>" <?= $period == $month ? 'selected' : '' ?>>
                                                            <?= date('F Y', strtotime($month . '-01')) ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                <?php elseif ($report_type == 'quarterly'): ?>
                                                    <?php 
                                                    $quarters = [
                                                        '01-03' => 'Q1 (Jan-Mar)',
                                                        '04-06' => 'Q2 (Apr-Jun)',
                                                        '07-09' => 'Q3 (Jul-Sep)',
                                                        '10-12' => 'Q4 (Oct-Dec)'
                                                    ];
                                                    $current_year = date('Y');
                                                    for ($i = 0; $i < 4; $i++): 
                                                        $year = $current_year - floor($i/4);
                                                        $q = 4 - ($i % 4);
                                                        $quarter_key = array_keys($quarters)[$q-1];
                                                        $quarter_value = $year . '-' . $quarter_key;
                                                        ?>
                                                        <option value="<?= $quarter_value ?>" <?= $period == $quarter_value ? 'selected' : '' ?>>
                                                            <?= $quarters[$quarter_key] . ' ' . $year ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                <?php elseif ($report_type == 'yearly'): ?>
                                                    <?php for ($i = 0; $i < 5; $i++): 
                                                        $year = date('Y') - $i; ?>
                                                        <option value="<?= $year ?>" <?= $period == $year ? 'selected' : '' ?>>
                                                            <?= $year ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div id="custom_dates" style="display: <?= $report_type == 'custom' ? 'block' : 'none' ?>;">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="date_from" class="form-label">From Date</label>
                                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="date_to" class="form-label">To Date</label>
                                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="compare_with" class="form-label">Compare With</label>
                                            <select class="form-control" id="compare_with" name="compare_with">
                                                <option value="none">No Comparison</option>
                                                <option value="previous" <?= $compare_with == 'previous' ? 'selected' : '' ?>>Previous Period</option>
                                                <option value="last_year" <?= $compare_with == 'last_year' ? 'selected' : '' ?>>Same Period Last Year</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="show_details" class="form-label">View Mode</label>
                                            <select class="form-control" id="show_details" name="show_details">
                                                <option value="summary" <?= $show_details == 'summary' ? 'selected' : '' ?>>Summary View</option>
                                                <option value="detailed" <?= $show_details == 'detailed' ? 'selected' : '' ?>>Detailed View</option>
                                                <option value="ratio" <?= $show_details == 'ratio' ? 'selected' : '' ?>>Financial Ratios</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <button type="submit" class="btn btn-primary me-2">
                                                <i class="mdi mdi-filter"></i> Generate Report
                                            </button>
                                            <a href="profit-loss.php?report_type=<?= $report_type ?>" class="btn btn-secondary">
                                                <i class="mdi mdi-refresh"></i> Reset
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Content Container -->
                <div id="reportContent">
                    <!-- Summary Cards -->
                    <div class="row">
                        <div class="col-xl-3 col-md-6">
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
                                            <p class="text-muted mb-2">Total Revenue</p>
                                            <h4>₹<?= formatAmount($current_data['total_revenue']) ?></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                    <i class="mdi mdi-cart-arrow-down font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Total Expenses</p>
                                            <h4>₹<?= formatAmount($current_data['total_operating_expenses']) ?></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                    <i class="mdi mdi-chart-line font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Gross Profit</p>
                                            <h4>₹<?= formatAmount($current_data['gross_profit']) ?></h4>
                                            <small class="text-success">
                                                Margin: <?= formatPercentage($current_data['gross_margin']) ?>%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <div class="avatar-sm">
                                                <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                    <i class="mdi mdi-crown font-size-24"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <p class="text-muted mb-2">Net Profit</p>
                                            <h4 class="text-success">₹<?= formatAmount($current_data['net_profit']) ?></h4>
                                            <small class="text-success">
                                                Margin: <?= formatPercentage($current_data['net_margin']) ?>%
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profit & Loss Statement -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Profit & Loss Statement</h4>
                                    <p class="text-muted">Period: <?= date('d M Y', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?></p>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th style="width: 40%;">Particulars</th>
                                                    <th class="text-end">Amount (₹)</th>
                                                    <th class="text-end">% of Revenue</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Revenue Section -->
                                                <tr class="table-info">
                                                    <td colspan="3"><strong>REVENUE</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Sales Revenue</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['sales_revenue']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['sales_revenue_percent']) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Other Income</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['other_income']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['other_income_percent']) ?>%</td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td><strong>Total Revenue</strong></td>
                                                    <td class="text-end"><strong>₹<?= formatAmount($current_data['total_revenue']) ?></strong></td>
                                                    <td class="text-end"><strong>100%</strong></td>
                                                </tr>

                                                <!-- Cost of Goods Sold -->
                                                <tr class="table-info">
                                                    <td colspan="3"><strong>COST OF GOODS SOLD</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Opening Stock</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['opening_stock']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['opening_stock_percent']) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Purchases</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['purchases']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['purchases_percent']) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Closing Stock</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['closing_stock']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['closing_stock_percent']) ?>%</td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td><strong>Cost of Goods Sold</strong></td>
                                                    <td class="text-end"><strong>₹<?= formatAmount($current_data['cogs']) ?></strong></td>
                                                    <td class="text-end"><strong><?= formatPercentage($current_data['cogs_percent']) ?>%</strong></td>
                                                </tr>

                                                <!-- Gross Profit -->
                                                <tr class="table-success">
                                                    <td><strong>GROSS PROFIT</strong></td>
                                                    <td class="text-end"><strong>₹<?= formatAmount($current_data['gross_profit']) ?></strong></td>
                                                    <td class="text-end"><strong><?= formatPercentage($current_data['gross_margin']) ?>%</strong></td>
                                                </tr>

                                                <!-- Operating Expenses -->
                                                <tr class="table-info">
                                                    <td colspan="3"><strong>OPERATING EXPENSES</strong></td>
                                                </tr>
                                                
                                                <?php if ($show_details == 'detailed'): ?>
                                                    <?php foreach ($current_data['expense_categories'] as $category => $amount): ?>
                                                    <tr>
                                                        <td style="padding-left: 30px;"><?= htmlspecialchars($category) ?></td>
                                                        <td class="text-end">₹<?= formatAmount($amount) ?></td>
                                                        <td class="text-end"><?= formatPercentage(($amount / max($current_data['total_revenue'], 1)) * 100) ?>%</td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Administrative Expenses</td>
                                                        <td class="text-end">₹<?= formatAmount($current_data['admin_expenses']) ?></td>
                                                        <td class="text-end"><?= formatPercentage($current_data['admin_expenses_percent']) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Selling & Marketing</td>
                                                        <td class="text-end">₹<?= formatAmount($current_data['selling_expenses']) ?></td>
                                                        <td class="text-end"><?= formatPercentage($current_data['selling_expenses_percent']) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Financial Expenses</td>
                                                        <td class="text-end">₹<?= formatAmount($current_data['financial_expenses']) ?></td>
                                                        <td class="text-end"><?= formatPercentage($current_data['financial_expenses_percent']) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Other Expenses</td>
                                                        <td class="text-end">₹<?= formatAmount($current_data['other_expenses']) ?></td>
                                                        <td class="text-end"><?= formatPercentage($current_data['other_expenses_percent']) ?>%</td>
                                                    </tr>
                                                <?php endif; ?>
                                                
                                                <tr class="border-top">
                                                    <td><strong>Total Operating Expenses</strong></td>
                                                    <td class="text-end"><strong>₹<?= formatAmount($current_data['total_operating_expenses']) ?></strong></td>
                                                    <td class="text-end"><strong><?= formatPercentage($current_data['operating_expenses_percent']) ?>%</strong></td>
                                                </tr>

                                                <!-- Operating Profit -->
                                                <tr class="table-success">
                                                    <td><strong>OPERATING PROFIT</strong></td>
                                                    <td class="text-end"><strong>₹<?= formatAmount($current_data['operating_profit']) ?></strong></td>
                                                    <td class="text-end"><strong><?= formatPercentage($current_data['operating_margin']) ?>%</strong></td>
                                                </tr>

                                                <!-- Other Income/Expenses -->
                                                <tr>
                                                    <td style="padding-left: 30px;">Add: Other Income</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['other_income']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['other_income_percent']) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Interest & Taxes</td>
                                                    <td class="text-end">₹<?= formatAmount($current_data['interest_taxes']) ?></td>
                                                    <td class="text-end"><?= formatPercentage($current_data['interest_taxes_percent']) ?>%</td>
                                                </tr>

                                                <!-- Net Profit -->
                                                <tr class="table-primary">
                                                    <td><strong>NET PROFIT</strong></td>
                                                    <td class="text-end"><strong class="text-success">₹<?= formatAmount($current_data['net_profit']) ?></strong></td>
                                                    <td class="text-end"><strong class="text-success"><?= formatPercentage($current_data['net_margin']) ?>%</strong></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Ratios -->
                    <?php if ($show_details == 'ratio'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Financial Ratios</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h5 class="card-title">Profitability Ratios</h5>
                                                    <table class="table table-sm table-borderless">
                                                        <tr>
                                                            <td>Gross Profit Margin</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['gross_margin']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Operating Profit Margin</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['operating_margin']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Net Profit Margin</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['net_margin']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Return on Sales</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['return_on_sales']) ?>%</strong></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h5 class="card-title">Expense Ratios</h5>
                                                    <table class="table table-sm table-borderless">
                                                        <tr>
                                                            <td>Operating Expense Ratio</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['operating_expense_ratio']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Admin Expense Ratio</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['admin_expense_ratio']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Selling Expense Ratio</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['selling_expense_ratio']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Financial Expense Ratio</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['financial_expense_ratio']) ?>%</strong></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h5 class="card-title">Efficiency Ratios</h5>
                                                    <table class="table table-sm table-borderless">
                                                        <tr>
                                                            <td>Cost of Goods Sold / Revenue</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['cogs_to_revenue']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Operating Expenses / Revenue</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['opex_to_revenue']) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Interest Coverage Ratio</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['interest_coverage'], 2) ?>x</strong></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h5 class="card-title">Break-even Analysis</h5>
                                                    <table class="table table-sm table-borderless">
                                                        <tr>
                                                            <td>Break-even Point (Revenue)</td>
                                                            <td class="text-end"><strong>₹<?= formatAmount($ratios['breakeven_point']) ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Margin of Safety</td>
                                                            <td class="text-end"><strong><?= formatPercentage($ratios['margin_of_safety']) ?>%</strong></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
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
    .table-info, .table-success, .table-primary {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
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

/* Table styles */
.table thead th {
    font-weight: 600;
    color: #495057;
}

.table tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}

.table-info {
    background-color: #e7f1ff;
}

.table-success {
    background-color: #d4edda;
}

.table-primary {
    background-color: #cfe2ff;
}

/* Card styles */
.card.bg-light {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,.02);
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

/* Amount styling */
.text-end {
    font-family: 'Roboto Mono', monospace;
}

/* Remove any minus signs */
.text-danger {
    color: #28a745 !important; /* Override red to green */
}

/* Ensure all amounts are displayed without minus */
.amount-display {
    color: #28a745 !important;
}
</style>

</body>
</html>