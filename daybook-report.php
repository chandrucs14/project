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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'daily'; // daily, weekly, monthly, yearly
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer_type = isset($_GET['customer_type']) ? $_GET['customer_type'] : 'all';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get report data
$report_data = [];
$summary = [];
$chart_data = [];

try {
    // Base query for daybook entries
    $query = "
        SELECT 
            d.id,
            d.invoice_number,
            d.invoice_date,
            d.driver_name,
            d.driver_number,
            d.location,
            d.customer_type,
            d.customer_name,
            d.customer_mobile,
            d.subtotal,
            d.discount_total,
            d.grand_total,
            d.paid_amount,
            d.payment_status,
            d.created_at,
            COUNT(di.id) as item_count,
            SUM(di.quantity) as total_quantity
        FROM daybook d
        LEFT JOIN daybook_items di ON d.id = di.daybook_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($report_type == 'daily') {
        $query .= " AND d.invoice_date = :date";
        $params[':date'] = $date_from;
    } elseif ($report_type == 'weekly') {
        $query .= " AND d.invoice_date BETWEEN :date_from AND :date_to";
        $params[':date_from'] = $date_from;
        $params[':date_to'] = $date_to;
    } elseif ($report_type == 'monthly') {
        $query .= " AND MONTH(d.invoice_date) = :month AND YEAR(d.invoice_date) = :year";
        $params[':month'] = date('m', strtotime($date_from));
        $params[':year'] = date('Y', strtotime($date_from));
    } elseif ($report_type == 'yearly') {
        $query .= " AND YEAR(d.invoice_date) = :year";
        $params[':year'] = date('Y', strtotime($date_from));
    }
    
    if ($customer_type != 'all') {
        $query .= " AND d.customer_type = :customer_type";
        $params[':customer_type'] = $customer_type;
    }
    
    if ($payment_status != 'all') {
        $query .= " AND d.payment_status = :payment_status";
        $params[':payment_status'] = $payment_status;
    }
    
    if (!empty($search)) {
        $query .= " AND (d.customer_name LIKE :search OR d.driver_name LIKE :search OR d.invoice_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $query .= " GROUP BY d.id ORDER BY d.invoice_date DESC, d.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $summary = [
        'total_entries' => count($report_data),
        'total_subtotal' => 0,
        'total_discount' => 0,
        'total_amount' => 0,
        'total_paid' => 0,
        'total_outstanding' => 0,
        'avg_transaction' => 0,
        'by_customer_type' => [
            'constructor' => ['count' => 0, 'amount' => 0],
            'customer' => ['count' => 0, 'amount' => 0],
            'dealer' => ['count' => 0, 'amount' => 0]
        ],
        'by_payment_status' => [
            'paid' => ['count' => 0, 'amount' => 0],
            'pending' => ['count' => 0, 'amount' => 0],
            'partial' => ['count' => 0, 'amount' => 0]
        ]
    ];
    
    foreach ($report_data as $entry) {
        $summary['total_subtotal'] += $entry['subtotal'];
        $summary['total_discount'] += $entry['discount_total'];
        $summary['total_amount'] += $entry['grand_total'];
        $summary['total_paid'] += $entry['paid_amount'];
        $summary['total_outstanding'] += ($entry['grand_total'] - $entry['paid_amount']);
        
        // By customer type
        if ($entry['customer_type'] == 'constructor') {
            $summary['by_customer_type']['constructor']['count']++;
            $summary['by_customer_type']['constructor']['amount'] += $entry['grand_total'];
        } elseif ($entry['customer_type'] == 'customer') {
            $summary['by_customer_type']['customer']['count']++;
            $summary['by_customer_type']['customer']['amount'] += $entry['grand_total'];
        } elseif ($entry['customer_type'] == 'dealer') {
            $summary['by_customer_type']['dealer']['count']++;
            $summary['by_customer_type']['dealer']['amount'] += $entry['grand_total'];
        }
        
        // By payment status
        if ($entry['payment_status'] == 'paid') {
            $summary['by_payment_status']['paid']['count']++;
            $summary['by_payment_status']['paid']['amount'] += $entry['grand_total'];
        } elseif ($entry['payment_status'] == 'pending') {
            $summary['by_payment_status']['pending']['count']++;
            $summary['by_payment_status']['pending']['amount'] += $entry['grand_total'];
        } elseif ($entry['payment_status'] == 'partial') {
            $summary['by_payment_status']['partial']['count']++;
            $summary['by_payment_status']['partial']['amount'] += $entry['grand_total'];
        }
    }
    
    if ($summary['total_entries'] > 0) {
        $summary['avg_transaction'] = $summary['total_amount'] / $summary['total_entries'];
    }
    
    // Prepare chart data based on report type
    if ($report_type == 'daily') {
        $chart_data = getDailyChartData($pdo, $date_from);
    } elseif ($report_type == 'weekly') {
        $chart_data = getWeeklyChartData($pdo, $date_from, $date_to);
    } elseif ($report_type == 'monthly') {
        $chart_data = getMonthlyChartData($pdo, $date_from);
    } elseif ($report_type == 'yearly') {
        $chart_data = getYearlyChartData($pdo, $date_from);
    }
    
} catch (Exception $e) {
    error_log("Error fetching report data: " . $e->getMessage());
}

// Function to get daily chart data
function getDailyChartData($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count,
                SUM(grand_total) as total
            FROM daybook
            WHERE invoice_date = :date
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $stmt->execute([':date' => $date]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $hours = [];
        $counts = [];
        $totals = [];
        
        for ($i = 0; $i < 24; $i++) {
            $hours[] = $i . ':00';
            $found = false;
            foreach ($data as $row) {
                if ($row['hour'] == $i) {
                    $counts[] = $row['count'];
                    $totals[] = $row['total'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $counts[] = 0;
                $totals[] = 0;
            }
        }
        
        return ['hours' => $hours, 'counts' => $counts, 'totals' => $totals];
    } catch (Exception $e) {
        return ['hours' => [], 'counts' => [], 'totals' => []];
    }
}

// Function to get weekly chart data
function getWeeklyChartData($pdo, $date_from, $date_to) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(invoice_date) as date,
                COUNT(*) as count,
                SUM(grand_total) as total
            FROM daybook
            WHERE invoice_date BETWEEN :date_from AND :date_to
            GROUP BY DATE(invoice_date)
            ORDER BY date
        ");
        $stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $dates = [];
        $counts = [];
        $totals = [];
        
        $current = strtotime($date_from);
        $end = strtotime($date_to);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $dates[] = date('d M', $current);
            $found = false;
            foreach ($data as $row) {
                if ($row['date'] == $date) {
                    $counts[] = $row['count'];
                    $totals[] = $row['total'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $counts[] = 0;
                $totals[] = 0;
            }
            $current = strtotime('+1 day', $current);
        }
        
        return ['dates' => $dates, 'counts' => $counts, 'totals' => $totals];
    } catch (Exception $e) {
        return ['dates' => [], 'counts' => [], 'totals' => []];
    }
}

// Function to get monthly chart data
function getMonthlyChartData($pdo, $date) {
    try {
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        
        $stmt = $pdo->prepare("
            SELECT 
                DAY(invoice_date) as day,
                COUNT(*) as count,
                SUM(grand_total) as total
            FROM daybook
            WHERE MONTH(invoice_date) = :month AND YEAR(invoice_date) = :year
            GROUP BY DAY(invoice_date)
            ORDER BY day
        ");
        $stmt->execute([':month' => $month, ':year' => $year]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $days = [];
        $counts = [];
        $totals = [];
        
        $days_in_month = date('t', strtotime($date));
        
        for ($i = 1; $i <= $days_in_month; $i++) {
            $days[] = $i;
            $found = false;
            foreach ($data as $row) {
                if ($row['day'] == $i) {
                    $counts[] = $row['count'];
                    $totals[] = $row['total'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $counts[] = 0;
                $totals[] = 0;
            }
        }
        
        return ['days' => $days, 'counts' => $counts, 'totals' => $totals];
    } catch (Exception $e) {
        return ['days' => [], 'counts' => [], 'totals' => []];
    }
}

// Function to get yearly chart data
function getYearlyChartData($pdo, $date) {
    try {
        $year = date('Y', strtotime($date));
        
        $stmt = $pdo->prepare("
            SELECT 
                MONTH(invoice_date) as month,
                COUNT(*) as count,
                SUM(grand_total) as total
            FROM daybook
            WHERE YEAR(invoice_date) = :year
            GROUP BY MONTH(invoice_date)
            ORDER BY month
        ");
        $stmt->execute([':year' => $year]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $counts = [];
        $totals = [];
        
        for ($i = 1; $i <= 12; $i++) {
            $found = false;
            foreach ($data as $row) {
                if ($row['month'] == $i) {
                    $counts[] = $row['count'];
                    $totals[] = $row['total'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $counts[] = 0;
                $totals[] = 0;
            }
        }
        
        return ['months' => $months, 'counts' => $counts, 'totals' => $totals];
    } catch (Exception $e) {
        return ['months' => [], 'counts' => [], 'totals' => []];
    }
}

// Handle export to CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daybook_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Daybook Report - ' . ucfirst($report_type) . ' Report']);
    
    if ($report_type == 'daily') {
        fputcsv($output, ['Date: ' . date('d M Y', strtotime($date_from))]);
    } elseif ($report_type == 'weekly') {
        fputcsv($output, ['Period: ' . date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))]);
    } elseif ($report_type == 'monthly') {
        fputcsv($output, ['Month: ' . date('F Y', strtotime($date_from))]);
    } elseif ($report_type == 'yearly') {
        fputcsv($output, ['Year: ' . date('Y', strtotime($date_from))]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Invoice #', 'Date', 'Driver', 'Location', 'Customer Type', 'Customer', 'Subtotal', 'Discount', 'Total', 'Paid', 'Outstanding', 'Status']);
    
    foreach ($report_data as $entry) {
        $outstanding = $entry['grand_total'] - $entry['paid_amount'];
        fputcsv($output, [
            $entry['invoice_number'],
            date('d-m-Y', strtotime($entry['invoice_date'])),
            $entry['driver_name'],
            $entry['location'],
            ucfirst($entry['customer_type']),
            $entry['customer_name'],
            number_format($entry['subtotal'], 2),
            number_format($entry['discount_total'], 2),
            number_format($entry['grand_total'], 2),
            number_format($entry['paid_amount'], 2),
            number_format($outstanding, 2),
            ucfirst($entry['payment_status'])
        ]);
    }
    
    fclose($output);
    exit();
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
    <!-- Chart JS -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .page-title-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 20px;
        }
        
        .page-title-box h4 {
            color: white;
        }
        
        .page-title-box .breadcrumb-item a,
        .page-title-box .breadcrumb-item.active {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .summary-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            cursor: pointer;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin-bottom: 20px;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background-color: #fed7aa;
            color: #92400e;
        }
        
        .status-partial {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .report-type-btn {
            transition: all 0.3s;
        }
        
        .report-type-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        
        @media print {
            .vertical-menu, .topbar, .footer, .btn, .modal, 
            .page-title-right, .card-title .btn, .action-buttons,
            .filter-section, .no-print, .chart-container {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6c757d;
        }
        
        .trend-up {
            color: #28a745;
        }
        
        .trend-down {
            color: #dc3545;
        }
    </style>
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
                            <h4 class="mb-0 font-size-18">Daybook Reports</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="daybook-list.php">Daybook</a></li>
                                    <li class="breadcrumb-item active">Reports</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Report Type Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="?report_type=daily&date_from=<?= date('Y-m-d') ?>" 
                                       class="btn report-type-btn <?= $report_type == 'daily' ? 'active' : 'btn-outline-primary' ?>">
                                        <i class="mdi mdi-calendar-today"></i> Daily Report
                                    </a>
                                    <a href="?report_type=weekly&date_from=<?= date('Y-m-d', strtotime('-7 days')) ?>&date_to=<?= date('Y-m-d') ?>" 
                                       class="btn report-type-btn <?= $report_type == 'weekly' ? 'active' : 'btn-outline-primary' ?>">
                                        <i class="mdi mdi-calendar-week"></i> Weekly Report
                                    </a>
                                    <a href="?report_type=monthly&date_from=<?= date('Y-m-01') ?>" 
                                       class="btn report-type-btn <?= $report_type == 'monthly' ? 'active' : 'btn-outline-primary' ?>">
                                        <i class="mdi mdi-calendar-month"></i> Monthly Report
                                    </a>
                                    <a href="?report_type=yearly&date_from=<?= date('Y-01-01') ?>" 
                                       class="btn report-type-btn <?= $report_type == 'yearly' ? 'active' : 'btn-outline-primary' ?>">
                                        <i class="mdi mdi-calendar-clock"></i> Yearly Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body filter-section">
                                <h4 class="card-title mb-4">Filter Report</h4>
                                <form method="GET" action="daybook-report.php" id="filterForm">
                                    <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
                                    <div class="row g-3 align-items-end">
                                        <?php if ($report_type == 'daily'): ?>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="date_from" class="form-label">Date</label>
                                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                            </div>
                                        </div>
                                        <?php elseif ($report_type == 'weekly'): ?>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="date_from" class="form-label">From Date</label>
                                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="date_to" class="form-label">To Date</label>
                                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                            </div>
                                        </div>
                                        <?php elseif ($report_type == 'monthly'): ?>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="date_from" class="form-label">Month</label>
                                                <input type="month" class="form-control" id="date_from" name="date_from" value="<?= date('Y-m', strtotime($date_from)) ?>">
                                            </div>
                                        </div>
                                        <?php elseif ($report_type == 'yearly'): ?>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="date_from" class="form-label">Year</label>
                                                <select class="form-control" id="date_from" name="date_from">
                                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                                    <option value="<?= $y ?>-01-01" <?= date('Y', strtotime($date_from)) == $y ? 'selected' : '' ?>><?= $y ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="customer_type" class="form-label">Customer Type</label>
                                                <select class="form-control" id="customer_type" name="customer_type">
                                                    <option value="all" <?= $customer_type == 'all' ? 'selected' : '' ?>>All Types</option>
                                                    <option value="constructor" <?= $customer_type == 'constructor' ? 'selected' : '' ?>>Constructor</option>
                                                    <option value="customer" <?= $customer_type == 'customer' ? 'selected' : '' ?>>Customer</option>
                                                    <option value="dealer" <?= $customer_type == 'dealer' ? 'selected' : '' ?>>Dealer</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="payment_status" class="form-label">Payment Status</label>
                                                <select class="form-control" id="payment_status" name="payment_status">
                                                    <option value="all" <?= $payment_status == 'all' ? 'selected' : '' ?>>All</option>
                                                    <option value="paid" <?= $payment_status == 'paid' ? 'selected' : '' ?>>Paid</option>
                                                    <option value="pending" <?= $payment_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="partial" <?= $payment_status == 'partial' ? 'selected' : '' ?>>Partial</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label for="search" class="form-label">Search</label>
                                                <input type="text" class="form-control" id="search" name="search" placeholder="Customer, Driver..." value="<?= htmlspecialchars($search) ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <div class="filter-buttons">
                                                <button type="submit" class="btn btn-primary w-100 mb-2">
                                                    <i class="mdi mdi-filter"></i> Apply
                                                </button>
                                                <a href="daybook-report.php?report_type=<?= $report_type ?>" class="btn btn-secondary w-100">
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

                <!-- Summary Statistics Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="mdi mdi-file-document font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Entries</p>
                                        <h4><?= number_format($summary['total_entries'] ?? 0) ?></h4>
                                        <small class="text-muted">Transactions</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
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
                                        <p class="text-muted mb-2">Total Amount</p>
                                        <h4>₹<?= number_format($summary['total_amount'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Gross sales</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="mdi mdi-credit-card-check font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Total Paid</p>
                                        <h4>₹<?= number_format($summary['total_paid'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">Collections</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="mdi mdi-clock-alert font-size-24"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-2">Outstanding</p>
                                        <h4>₹<?= number_format($summary['total_outstanding'] ?? 0, 2) ?></h4>
                                        <small class="text-muted">To be collected</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Transaction Trend</h4>
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Payment Status Distribution</h4>
                                <div class="chart-container">
                                    <canvas id="paymentStatusChart"></canvas>
                                </div>
                                <div class="mt-3">
                                    <table class="table table-sm">
                                        <tbody>
                                            <tr>
                                                <td><span class="badge bg-success">Paid</span></td>
                                                <td class="text-end"><?= number_format($summary['by_payment_status']['paid']['count'] ?? 0) ?> entries</td>
                                                <td class="text-end">₹<?= number_format($summary['by_payment_status']['paid']['amount'] ?? 0, 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td><span class="badge bg-warning">Pending</span></td>
                                                <td class="text-end"><?= number_format($summary['by_payment_status']['pending']['count'] ?? 0) ?> entries</td>
                                                <td class="text-end">₹<?= number_format($summary['by_payment_status']['pending']['amount'] ?? 0, 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td><span class="badge bg-info">Partial</span></td>
                                                <td class="text-end"><?= number_format($summary['by_payment_status']['partial']['count'] ?? 0) ?> entries</td>
                                                <td class="text-end">₹<?= number_format($summary['by_payment_status']['partial']['amount'] ?? 0, 2) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Type Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Performance by Customer Type</h4>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-domain font-size-36 text-primary"></i>
                                            <h5 class="mt-2">Constructor/Builder</h5>
                                            <div class="stat-value"><?= number_format($summary['by_customer_type']['constructor']['count'] ?? 0) ?></div>
                                            <div class="stat-label">Entries</div>
                                            <div class="stat-value text-success">₹<?= number_format($summary['by_customer_type']['constructor']['amount'] ?? 0, 2) ?></div>
                                            <div class="stat-label">Total Amount</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-account-group font-size-36 text-success"></i>
                                            <h5 class="mt-2">Regular Customers</h5>
                                            <div class="stat-value"><?= number_format($summary['by_customer_type']['customer']['count'] ?? 0) ?></div>
                                            <div class="stat-label">Entries</div>
                                            <div class="stat-value text-success">₹<?= number_format($summary['by_customer_type']['customer']['amount'] ?? 0, 2) ?></div>
                                            <div class="stat-label">Total Amount</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <i class="mdi mdi-truck-delivery font-size-36 text-info"></i>
                                            <h5 class="mt-2">Dealers</h5>
                                            <div class="stat-value"><?= number_format($summary['by_customer_type']['dealer']['count'] ?? 0) ?></div>
                                            <div class="stat-label">Entries</div>
                                            <div class="stat-value text-success">₹<?= number_format($summary['by_customer_type']['dealer']['amount'] ?? 0, 2) ?></div>
                                            <div class="stat-label">Total Amount</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
                                        <i class="mdi mdi-export"></i> Export CSV
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="window.print()">
                                        <i class="mdi mdi-printer"></i> Print Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Report Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Detailed Transactions</h4>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0" id="reportTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Date</th>
                                                <th>Driver</th>
                                                <th>Location</th>
                                                <th>Customer Type</th>
                                                <th>Customer</th>
                                                <th>Items</th>
                                                <th>Subtotal</th>
                                                <th>Discount</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Outstanding</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="13" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No transactions found for selected filters</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($report_data as $entry): 
                                                    $outstanding = $entry['grand_total'] - $entry['paid_amount'];
                                                    $status_class = '';
                                                    if ($entry['payment_status'] == 'paid') $status_class = 'status-paid';
                                                    elseif ($entry['payment_status'] == 'pending') $status_class = 'status-pending';
                                                    elseif ($entry['payment_status'] == 'partial') $status_class = 'status-partial';
                                                ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($entry['invoice_number']) ?></strong></td>
                                                    <td><?= date('d-m-Y', strtotime($entry['invoice_date'])) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($entry['driver_name']) ?>
                                                        <?php if ($entry['driver_number']): ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($entry['driver_number']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($entry['location']) ?></td>
                                                    <td>
                                                        <span class="badge bg-soft-info">
                                                            <?= ucfirst(htmlspecialchars($entry['customer_type'])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($entry['customer_name']) ?>
                                                        <?php if ($entry['customer_mobile']): ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($entry['customer_mobile']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-secondary"><?= $entry['item_count'] ?? 0 ?> items</span>
                                                        <?php if ($entry['total_quantity']): ?>
                                                            <br><small><?= number_format($entry['total_quantity'], 2) ?> units</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">₹<?= number_format($entry['subtotal'], 2) ?></td>
                                                    <td class="text-end text-danger">-₹<?= number_format($entry['discount_total'], 2) ?></td>
                                                    <td class="text-end"><strong>₹<?= number_format($entry['grand_total'], 2) ?></strong></td>
                                                    <td class="text-end text-success">₹<?= number_format($entry['paid_amount'], 2) ?></td>
                                                    <td class="text-end outstanding-amount">₹<?= number_format($outstanding, 2) ?></td>
                                                    <td>
                                                        <span class="status-badge <?= $status_class ?>">
                                                            <?= ucfirst($entry['payment_status']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="7" class="text-end">Total:</th>
                                                <th class="text-end">₹<?= number_format($summary['total_subtotal'] ?? 0, 2) ?></th>
                                                <th class="text-end">₹<?= number_format($summary['total_discount'] ?? 0, 2) ?></th>
                                                <th class="text-end">₹<?= number_format($summary['total_amount'] ?? 0, 2) ?></th>
                                                <th class="text-end">₹<?= number_format($summary['total_paid'] ?? 0, 2) ?></th>
                                                <th class="text-end">₹<?= number_format($summary['total_outstanding'] ?? 0, 2) ?></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<!-- Chart JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#reportTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    previous: "<",
                    next: ">"
                }
            },
            footerCallback: function(row, data, start, end, display) {
                // Keep footer totals
                var api = this.api();
                $(api.column(7).footer()).html('₹' + api.column(7).data().reduce(function(a, b) {
                    return parseFloat(a) + parseFloat(b.replace('₹', ''));
                }, 0).toFixed(2));
            }
        });
        
        // Initialize trend chart
        <?php if ($report_type == 'daily'): ?>
        var trendCtx = document.getElementById('trendChart').getContext('2d');
        var trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['hours'] ?? []) ?>,
                datasets: [{
                    label: 'Number of Transactions',
                    data: <?= json_encode($chart_data['counts'] ?? []) ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Amount (₹)',
                    data: <?= json_encode($chart_data['totals'] ?? []) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.label.includes('Amount')) {
                                    label += '₹' + context.raw.toFixed(2);
                                } else {
                                    label += context.raw;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of Transactions'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });
        <?php elseif ($report_type == 'weekly' || $report_type == 'monthly' || $report_type == 'yearly'): ?>
        var trendCtx = document.getElementById('trendChart').getContext('2d');
        var trendChart = new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: <?= $report_type == 'weekly' ? json_encode($chart_data['dates'] ?? []) : ($report_type == 'monthly' ? json_encode($chart_data['days'] ?? []) : json_encode($chart_data['months'] ?? [])) ?>,
                datasets: [{
                    label: 'Number of Transactions',
                    data: <?= json_encode($chart_data['counts'] ?? []) ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.5)',
                    borderColor: '#667eea',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Amount (₹)',
                    data: <?= json_encode($chart_data['totals'] ?? []) ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.5)',
                    borderColor: '#10b981',
                    borderWidth: 1,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.label.includes('Amount')) {
                                    label += '₹' + context.raw.toFixed(2);
                                } else {
                                    label += context.raw;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of Transactions'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });
        <?php endif; ?>
        
        // Payment Status Chart
        var paymentCtx = document.getElementById('paymentStatusChart').getContext('2d');
        var paymentChart = new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Pending', 'Partial'],
                datasets: [{
                    data: [
                        <?= $summary['by_payment_status']['paid']['amount'] ?? 0 ?>,
                        <?= $summary['by_payment_status']['pending']['amount'] ?? 0 ?>,
                        <?= $summary['by_payment_status']['partial']['amount'] ?? 0 ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#3b82f6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ₹${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
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

<style>
    .outstanding-amount {
        font-weight: bold;
        color: #dc3545;
    }
    
    .badge.bg-soft-info {
        background-color: rgba(59, 130, 246, 0.1);
        color: #3b82f6;
    }
    
    .badge.bg-secondary {
        background-color: #6c757d;
    }
    
    .table tfoot {
        font-weight: bold;
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: bold;
        margin: 10px 0;
    }
    
    .stat-label {
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .border {
        border: 1px solid #e5e7eb !important;
    }
    
    .rounded {
        border-radius: 8px !important;
    }
</style>

</body>
</html>