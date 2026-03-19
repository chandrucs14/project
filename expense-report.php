<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';

// Check if $pdo is set and connection is successful
if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

// Get report type from query parameter
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$category = isset($_GET['category']) ? $_GET['category'] : '';
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : '';
$vehicle_id = isset($_GET['vehicle_id']) ? $_GET['vehicle_id'] : '';
$comparison_period = isset($_GET['comparison_period']) ? $_GET['comparison_period'] : 'previous_month';
$chart_type = isset($_GET['chart_type']) ? $_GET['chart_type'] : 'bar';

// Get expense categories
$expense_categories = [
    'Transportation' => 'Transportation & Freight',
    'Fuel' => 'Fuel & Diesel',
    'Maintenance' => 'Vehicle Maintenance',
    'Salary' => 'Salaries & Wages',
    'Rent' => 'Rent & Lease',
    'Electricity' => 'Electricity & Utilities',
    'Office' => 'Office Expenses',
    'Marketing' => 'Marketing & Advertising',
    'Insurance' => 'Insurance',
    'Tax' => 'Taxes & Fees',
    'Legal' => 'Legal & Professional',
    'Repair' => 'Repairs & Maintenance',
    'Equipment' => 'Equipment Purchase',
    'Miscellaneous' => 'Miscellaneous'
];

// Get suppliers for dropdown
try {
    $suppliersStmt = $pdo->query("SELECT id, name, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
    error_log("Error fetching suppliers: " . $e->getMessage());
}

// Get vehicles for dropdown
try {
    $vehiclesStmt = $pdo->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
    $vehicles = $vehiclesStmt->fetchAll();
} catch (Exception $e) {
    $vehicles = [];
    error_log("Error fetching vehicles: " . $e->getMessage());
}

// Handle AJAX request for expense details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'expense_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $expense_id = intval($_GET['id']);
        
        $stmt = $pdo->prepare("
            SELECT e.*, 
                   s.name as supplier_name,
                   s.company_name as supplier_company,
                   v.vehicle_number,
                   u.full_name as created_by_name
            FROM expenses e
            LEFT JOIN suppliers s ON e.supplier_id = s.id
            LEFT JOIN vehicles v ON e.vehicle_id = v.id
            LEFT JOIN users u ON e.created_by = u.id
            WHERE e.id = ?
        ");
        $stmt->execute([$expense_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($expense) {
            echo json_encode(['success' => true, 'data' => $expense]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Expense not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for category expenses
if (isset($_GET['ajax']) && $_GET['ajax'] === 'category_expenses' && isset($_GET['category'])) {
    header('Content-Type: application/json');
    
    try {
        $category = $_GET['category'];
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT e.*, 
                   s.name as supplier_name,
                   s.company_name as supplier_company,
                   v.vehicle_number
            FROM expenses e
            LEFT JOIN suppliers s ON e.supplier_id = s.id
            LEFT JOIN vehicles v ON e.vehicle_id = v.id
            WHERE e.category = :category
            AND e.expense_date BETWEEN :date_from AND :date_to
            ORDER BY e.expense_date DESC
            LIMIT 50
        ");
        $stmt->execute([
            ':category' => $category,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $expenses]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Initialize report data
$report_data = [];
$report_summary = [];
$chart_data = [];
$comparison_data = [];

// Generate report based on type
try {
    switch ($report_type) {
        case 'summary':
            // Summary Report - Daily/Monthly breakdown
            $query = "
                SELECT 
                    DATE(expense_date) as expense_day,
                    DATE_FORMAT(expense_date, '%Y-%m') as expense_month,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(amount), 0) as total_base_amount,
                    COALESCE(SUM(gst_amount), 0) as total_gst,
                    COALESCE(SUM(total_amount), 0) as total_expense,
                    COALESCE(AVG(total_amount), 0) as average_expense,
                    COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_total,
                    COALESCE(SUM(CASE WHEN payment_method IN ('bank', 'cheque', 'online') THEN total_amount ELSE 0 END), 0) as bank_total,
                    GROUP_CONCAT(DISTINCT id) as expense_ids
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $query .= " AND category = :category";
                $params[':category'] = $category;
            }
            
            if (!empty($payment_method)) {
                $query .= " AND payment_method = :payment_method";
                $params[':payment_method'] = $payment_method;
            }
            
            if (!empty($supplier_id)) {
                $query .= " AND supplier_id = :supplier_id";
                $params[':supplier_id'] = $supplier_id;
            }
            
            if (!empty($vehicle_id)) {
                $query .= " AND vehicle_id = :vehicle_id";
                $params[':vehicle_id'] = $vehicle_id;
            }
            
            $query .= " GROUP BY DATE(expense_date) ORDER BY expense_date";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_expenses = 0;
            $total_base = 0;
            $total_gst = 0;
            $total_cash = 0;
            $total_bank = 0;
            $days_count = count($report_data);
            
            foreach ($report_data as $row) {
                $total_expenses += floatval($row['total_expense'] ?? 0);
                $total_base += floatval($row['total_base_amount'] ?? 0);
                $total_gst += floatval($row['total_gst'] ?? 0);
                $total_cash += floatval($row['cash_total'] ?? 0);
                $total_bank += floatval($row['bank_total'] ?? 0);
            }
            
            $report_summary = [
                'total_expenses' => $total_expenses,
                'total_base' => $total_base,
                'total_gst' => $total_gst,
                'total_cash' => $total_cash,
                'total_bank' => $total_bank,
                'days_count' => $days_count,
                'average_daily' => $days_count > 0 ? $total_expenses / $days_count : 0,
                'transaction_count' => array_sum(array_column($report_data, 'transaction_count') ?: [0])
            ];
            
            // Chart data - Daily expenses
            $chart_data = $report_data;
            break;
            
        case 'category':
            // Category-wise Report
            $query = "
                SELECT 
                    category,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(amount), 0) as total_base_amount,
                    COALESCE(SUM(gst_amount), 0) as total_gst,
                    COALESCE(SUM(total_amount), 0) as total_expense,
                    COALESCE(AVG(total_amount), 0) as average_expense,
                    COALESCE(MIN(total_amount), 0) as min_expense,
                    COALESCE(MAX(total_amount), 0) as max_expense,
                    COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_total,
                    COALESCE(SUM(CASE WHEN payment_method IN ('bank', 'cheque', 'online') THEN total_amount ELSE 0 END), 0) as bank_total,
                    GROUP_CONCAT(DISTINCT id) as expense_ids
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $query .= " AND category = :category";
                $params[':category'] = $category;
            }
            
            if (!empty($payment_method)) {
                $query .= " AND payment_method = :payment_method";
                $params[':payment_method'] = $payment_method;
            }
            
            if (!empty($supplier_id)) {
                $query .= " AND supplier_id = :supplier_id";
                $params[':supplier_id'] = $supplier_id;
            }
            
            $query .= " GROUP BY category ORDER BY total_expense DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_expenses = 0;
            $total_base = 0;
            $total_gst = 0;
            $total_cash = 0;
            $total_bank = 0;
            $categories_count = count($report_data);
            $largest_category = ['name' => '', 'amount' => 0];
            
            foreach ($report_data as $row) {
                $total_expenses += floatval($row['total_expense'] ?? 0);
                $total_base += floatval($row['total_base_amount'] ?? 0);
                $total_gst += floatval($row['total_gst'] ?? 0);
                $total_cash += floatval($row['cash_total'] ?? 0);
                $total_bank += floatval($row['bank_total'] ?? 0);
                
                if (($row['total_expense'] ?? 0) > $largest_category['amount']) {
                    $largest_category['name'] = $expense_categories[$row['category']] ?? $row['category'];
                    $largest_category['amount'] = floatval($row['total_expense'] ?? 0);
                }
            }
            
            $report_summary = [
                'total_expenses' => $total_expenses,
                'total_base' => $total_base,
                'total_gst' => $total_gst,
                'total_cash' => $total_cash,
                'total_bank' => $total_bank,
                'categories_count' => $categories_count,
                'largest_category' => $largest_category,
                'transaction_count' => array_sum(array_column($report_data, 'transaction_count') ?: [0])
            ];
            
            // Chart data for categories
            $chart_data = $report_data;
            break;
            
        case 'payment_method':
            // Payment Method Report
            $query = "
                SELECT 
                    payment_method,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(amount), 0) as total_base_amount,
                    COALESCE(SUM(gst_amount), 0) as total_gst,
                    COALESCE(SUM(total_amount), 0) as total_expense,
                    COALESCE(AVG(total_amount), 0) as average_expense,
                    GROUP_CONCAT(DISTINCT id) as expense_ids
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $query .= " AND category = :category";
                $params[':category'] = $category;
            }
            
            if (!empty($supplier_id)) {
                $query .= " AND supplier_id = :supplier_id";
                $params[':supplier_id'] = $supplier_id;
            }
            
            $query .= " GROUP BY payment_method ORDER BY total_expense DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_expenses = 0;
            $total_base = 0;
            $total_gst = 0;
            
            foreach ($report_data as $row) {
                $total_expenses += floatval($row['total_expense'] ?? 0);
                $total_base += floatval($row['total_base_amount'] ?? 0);
                $total_gst += floatval($row['total_gst'] ?? 0);
            }
            
            $report_summary = [
                'total_expenses' => $total_expenses,
                'total_base' => $total_base,
                'total_gst' => $total_gst,
                'methods_count' => count($report_data),
                'transaction_count' => array_sum(array_column($report_data, 'transaction_count') ?: [0])
            ];
            
            // Chart data for payment methods
            $chart_data = $report_data;
            break;
            
        case 'supplier':
            // Supplier-wise Report
            $query = "
                SELECT 
                    e.supplier_id,
                    s.name as supplier_name,
                    s.company_name,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(e.amount), 0) as total_base_amount,
                    COALESCE(SUM(e.gst_amount), 0) as total_gst,
                    COALESCE(SUM(e.total_amount), 0) as total_expense,
                    COALESCE(AVG(e.total_amount), 0) as average_expense,
                    GROUP_CONCAT(DISTINCT e.id) as expense_ids
                FROM expenses e
                LEFT JOIN suppliers s ON e.supplier_id = s.id
                WHERE e.expense_date BETWEEN :date_from AND :date_to
                AND e.supplier_id IS NOT NULL
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $query .= " AND e.category = :category";
                $params[':category'] = $category;
            }
            
            if (!empty($payment_method)) {
                $query .= " AND e.payment_method = :payment_method";
                $params[':payment_method'] = $payment_method;
            }
            
            if (!empty($supplier_id)) {
                $query .= " AND e.supplier_id = :supplier_id";
                $params[':supplier_id'] = $supplier_id;
            }
            
            $query .= " GROUP BY e.supplier_id, s.name, s.company_name ORDER BY total_expense DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_expenses = 0;
            $total_base = 0;
            $total_gst = 0;
            $suppliers_count = count($report_data);
            
            foreach ($report_data as $row) {
                $total_expenses += floatval($row['total_expense'] ?? 0);
                $total_base += floatval($row['total_base_amount'] ?? 0);
                $total_gst += floatval($row['total_gst'] ?? 0);
            }
            
            $report_summary = [
                'total_expenses' => $total_expenses,
                'total_base' => $total_base,
                'total_gst' => $total_gst,
                'suppliers_count' => $suppliers_count,
                'transaction_count' => array_sum(array_column($report_data, 'transaction_count') ?: [0])
            ];
            
            // Chart data for suppliers
            $chart_data = $report_data;
            break;
            
        case 'vehicle':
            // Vehicle-wise Report
            $query = "
                SELECT 
                    e.vehicle_id,
                    v.vehicle_number,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(e.amount), 0) as total_base_amount,
                    COALESCE(SUM(e.gst_amount), 0) as total_gst,
                    COALESCE(SUM(e.total_amount), 0) as total_expense,
                    COALESCE(AVG(e.total_amount), 0) as average_expense,
                    COALESCE(SUM(CASE WHEN e.category = 'Fuel' THEN e.total_amount ELSE 0 END), 0) as fuel_expense,
                    COALESCE(SUM(CASE WHEN e.category = 'Maintenance' THEN e.total_amount ELSE 0 END), 0) as maintenance_expense,
                    GROUP_CONCAT(DISTINCT e.id) as expense_ids
                FROM expenses e
                LEFT JOIN vehicles v ON e.vehicle_id = v.id
                WHERE e.expense_date BETWEEN :date_from AND :date_to
                AND e.vehicle_id IS NOT NULL
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $query .= " AND e.category = :category";
                $params[':category'] = $category;
            }
            
            if (!empty($payment_method)) {
                $query .= " AND e.payment_method = :payment_method";
                $params[':payment_method'] = $payment_method;
            }
            
            if (!empty($vehicle_id)) {
                $query .= " AND e.vehicle_id = :vehicle_id";
                $params[':vehicle_id'] = $vehicle_id;
            }
            
            $query .= " GROUP BY e.vehicle_id, v.vehicle_number ORDER BY total_expense DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            
            // Calculate summary
            $total_expenses = 0;
            $total_fuel = 0;
            $total_maintenance = 0;
            $vehicles_count = count($report_data);
            
            foreach ($report_data as $row) {
                $total_expenses += floatval($row['total_expense'] ?? 0);
                $total_fuel += floatval($row['fuel_expense'] ?? 0);
                $total_maintenance += floatval($row['maintenance_expense'] ?? 0);
            }
            
            $report_summary = [
                'total_expenses' => $total_expenses,
                'total_fuel' => $total_fuel,
                'total_maintenance' => $total_maintenance,
                'vehicles_count' => $vehicles_count,
                'transaction_count' => array_sum(array_column($report_data, 'transaction_count') ?: [0])
            ];
            
            // Chart data for vehicles
            $chart_data = $report_data;
            break;
            
        case 'trends':
            // Trend Analysis - Monthly comparison
            $current_period_query = "
                SELECT 
                    DATE_FORMAT(expense_date, '%Y-%m') as period,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(total_amount), 0) as total_expense,
                    GROUP_CONCAT(DISTINCT id) as expense_ids
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
            ";
            
            $params = [':date_from' => $date_from, ':date_to' => $date_to];
            
            if (!empty($category)) {
                $current_period_query .= " AND category = :category";
                $params[':category'] = $category;
            }
            
            $current_period_query .= " GROUP BY DATE_FORMAT(expense_date, '%Y-%m') ORDER BY period";
            
            $stmt = $pdo->prepare($current_period_query);
            $stmt->execute($params);
            $current_data = $stmt->fetchAll();
            
            // Calculate previous period based on comparison type
            $date_from_obj = new DateTime($date_from);
            $date_to_obj = new DateTime($date_to);
            
            if ($comparison_period == 'previous_month') {
                $prev_date_from = (clone $date_from_obj)->modify('-1 month')->format('Y-m-d');
                $prev_date_to = (clone $date_to_obj)->modify('-1 month')->format('Y-m-d');
            } elseif ($comparison_period == 'previous_year') {
                $prev_date_from = (clone $date_from_obj)->modify('-1 year')->format('Y-m-d');
                $prev_date_to = (clone $date_to_obj)->modify('-1 year')->format('Y-m-d');
            } elseif ($comparison_period == 'same_period_last_year') {
                $prev_date_from = (clone $date_from_obj)->modify('-1 year')->format('Y-m-d');
                $prev_date_to = (clone $date_to_obj)->modify('-1 year')->format('Y-m-d');
            }
            
            // Get previous period data
            $prev_period_query = "
                SELECT 
                    DATE_FORMAT(expense_date, '%Y-%m') as period,
                    COALESCE(SUM(total_amount), 0) as total_expense,
                    GROUP_CONCAT(DISTINCT id) as expense_ids
                FROM expenses
                WHERE expense_date BETWEEN :date_from AND :date_to
            ";
            
            $prev_params = [':date_from' => $prev_date_from, ':date_to' => $prev_date_to];
            
            if (!empty($category)) {
                $prev_period_query .= " AND category = :category";
                $prev_params[':category'] = $category;
            }
            
            $prev_period_query .= " GROUP BY DATE_FORMAT(expense_date, '%Y-%m') ORDER BY period";
            
            $prevStmt = $pdo->prepare($prev_period_query);
            $prevStmt->execute($prev_params);
            $prev_data = $prevStmt->fetchAll();
            
            // Calculate totals for comparison
            $current_total = array_sum(array_column($current_data, 'total_expense') ?: [0]);
            $prev_total = array_sum(array_column($prev_data, 'total_expense') ?: [0]);
            
            $report_summary = [
                'current_total' => $current_total,
                'prev_total' => $prev_total,
                'change' => $prev_total > 0 ? (($current_total - $prev_total) / $prev_total * 100) : 0,
                'current_count' => array_sum(array_column($current_data, 'transaction_count') ?: [0]),
                'current_period' => date('M Y', strtotime($date_from)) . ' - ' . date('M Y', strtotime($date_to)),
                'prev_period' => date('M Y', strtotime($prev_date_from)) . ' - ' . date('M Y', strtotime($prev_date_to))
            ];
            
            // Chart data for trends
            $chart_data = [
                'current' => $current_data,
                'previous' => $prev_data,
                'labels' => array_unique(array_merge(
                    array_column($current_data, 'period') ?: [],
                    array_column($prev_data, 'period') ?: []
                ))
            ];
            break;
    }
    
    // Log activity
    if (isset($_SESSION['user_id'])) {
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (:user_id, 6, :description, :activity_data, :created_by)
        ");
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':description' => "Generated expense report: " . ucfirst($report_type),
            ':activity_data' => json_encode([
                'report_type' => $report_type,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'category' => $category,
                'payment_method' => $payment_method
            ]),
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
    }
    
} catch (Exception $e) {
    error_log("Expense report error: " . $e->getMessage());
    $report_data = [];
    $report_summary = [];
    $chart_data = [];
    $error_message = "Error generating report: " . $e->getMessage();
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($report_data, $report_type, $expense_categories);
    exit();
}

// Export function
function exportToCSV($data, $report_type, $expense_categories) {
    $filename = 'expense_report_' . $report_type . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers based on report type
    switch ($report_type) {
        case 'summary':
            fputcsv($output, ['Date', 'Month', 'Transactions', 'Base Amount', 'GST', 'Total Expense', 'Average', 'Cash', 'Bank']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['expense_day'] ?? '',
                    $row['expense_month'] ?? '',
                    $row['transaction_count'] ?? 0,
                    number_format(floatval($row['total_base_amount'] ?? 0), 2),
                    number_format(floatval($row['total_gst'] ?? 0), 2),
                    number_format(floatval($row['total_expense'] ?? 0), 2),
                    number_format(floatval($row['average_expense'] ?? 0), 2),
                    number_format(floatval($row['cash_total'] ?? 0), 2),
                    number_format(floatval($row['bank_total'] ?? 0), 2)
                ]);
            }
            break;
            
        case 'category':
            fputcsv($output, ['Category', 'Transactions', 'Base Amount', 'GST', 'Total Expense', 'Average', 'Min', 'Max', 'Cash', 'Bank']);
            foreach ($data as $row) {
                $category_name = $expense_categories[$row['category']] ?? $row['category'] ?? 'Unknown';
                fputcsv($output, [
                    $category_name,
                    $row['transaction_count'] ?? 0,
                    number_format(floatval($row['total_base_amount'] ?? 0), 2),
                    number_format(floatval($row['total_gst'] ?? 0), 2),
                    number_format(floatval($row['total_expense'] ?? 0), 2),
                    number_format(floatval($row['average_expense'] ?? 0), 2),
                    number_format(floatval($row['min_expense'] ?? 0), 2),
                    number_format(floatval($row['max_expense'] ?? 0), 2),
                    number_format(floatval($row['cash_total'] ?? 0), 2),
                    number_format(floatval($row['bank_total'] ?? 0), 2)
                ]);
            }
            break;
            
        case 'payment_method':
            fputcsv($output, ['Payment Method', 'Transactions', 'Base Amount', 'GST', 'Total Expense', 'Average']);
            foreach ($data as $row) {
                fputcsv($output, [
                    ucfirst($row['payment_method'] ?? 'Unknown'),
                    $row['transaction_count'] ?? 0,
                    number_format(floatval($row['total_base_amount'] ?? 0), 2),
                    number_format(floatval($row['total_gst'] ?? 0), 2),
                    number_format(floatval($row['total_expense'] ?? 0), 2),
                    number_format(floatval($row['average_expense'] ?? 0), 2)
                ]);
            }
            break;
            
        case 'supplier':
            fputcsv($output, ['Supplier', 'Company', 'Transactions', 'Base Amount', 'GST', 'Total Expense', 'Average']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['supplier_name'] ?? 'Unknown',
                    $row['company_name'] ?? '',
                    $row['transaction_count'] ?? 0,
                    number_format(floatval($row['total_base_amount'] ?? 0), 2),
                    number_format(floatval($row['total_gst'] ?? 0), 2),
                    number_format(floatval($row['total_expense'] ?? 0), 2),
                    number_format(floatval($row['average_expense'] ?? 0), 2)
                ]);
            }
            break;
            
        case 'vehicle':
            fputcsv($output, ['Vehicle', 'Transactions', 'Base Amount', 'GST', 'Total Expense', 'Average', 'Fuel', 'Maintenance']);
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['vehicle_number'] ?? 'Unknown',
                    $row['transaction_count'] ?? 0,
                    number_format(floatval($row['total_base_amount'] ?? 0), 2),
                    number_format(floatval($row['total_gst'] ?? 0), 2),
                    number_format(floatval($row['total_expense'] ?? 0), 2),
                    number_format(floatval($row['average_expense'] ?? 0), 2),
                    number_format(floatval($row['fuel_expense'] ?? 0), 2),
                    number_format(floatval($row['maintenance_expense'] ?? 0), 2)
                ]);
            }
            break;
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
                            <h4 class="mb-0 font-size-18">Expense Reports</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Reports</a></li>
                                    <li class="breadcrumb-item active">Expense Reports</li>
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

                <!-- Report Type Selection -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Select Report Type</h4>
                                <div class="row">
                                    <div class="col-md-2">
                                        <a href="?report_type=summary" class="text-decoration-none">
                                            <div class="card bg-soft-primary border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-chart-bar text-primary" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Summary</h6>
                                                    <small class="text-muted">Daily/Monthly breakdown</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="?report_type=category" class="text-decoration-none">
                                            <div class="card bg-soft-success border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-chart-pie text-success" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Category Wise</h6>
                                                    <small class="text-muted">By expense category</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="?report_type=payment_method" class="text-decoration-none">
                                            <div class="card bg-soft-info border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-credit-card text-info" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Payment Method</h6>
                                                    <small class="text-muted">Cash vs Bank</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="?report_type=supplier" class="text-decoration-none">
                                            <div class="card bg-soft-warning border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-truck text-warning" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Supplier Wise</h6>
                                                    <small class="text-muted">By vendor</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="?report_type=vehicle" class="text-decoration-none">
                                            <div class="card bg-soft-danger border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-car text-danger" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Vehicle Wise</h6>
                                                    <small class="text-muted">By vehicle</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="?report_type=trends" class="text-decoration-none">
                                            <div class="card bg-soft-secondary border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi mdi-trending-up text-secondary" style="font-size: 40px;"></i>
                                                    <h6 class="mt-2 mb-0">Trends</h6>
                                                    <small class="text-muted">Period comparison</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
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
                                <form method="GET" action="expense-report.php" class="row" id="filterForm">
                                    <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                    
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
                                            <label for="category" class="form-label">Category</label>
                                            <select class="form-control" id="category" name="category">
                                                <option value="">All Categories</option>
                                                <?php foreach ($expense_categories as $key => $value): ?>
                                                <option value="<?= $key ?>" <?= $category == $key ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($value) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="payment_method" class="form-label">Payment Method</label>
                                            <select class="form-control" id="payment_method" name="payment_method">
                                                <option value="">All Methods</option>
                                                <option value="cash" <?= $payment_method == 'cash' ? 'selected' : '' ?>>Cash</option>
                                                <option value="bank" <?= $payment_method == 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                                <option value="cheque" <?= $payment_method == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                                                <option value="online" <?= $payment_method == 'online' ? 'selected' : '' ?>>Online</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="supplier_id" class="form-label">Supplier</label>
                                            <select class="form-control" id="supplier_id" name="supplier_id">
                                                <option value="">All Suppliers</option>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?= $supplier['id'] ?>" <?= $supplier_id == $supplier['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($supplier['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="vehicle_id" class="form-label">Vehicle</label>
                                            <select class="form-control" id="vehicle_id" name="vehicle_id">
                                                <option value="">All Vehicles</option>
                                                <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?= $vehicle['id'] ?>" <?= $vehicle_id == $vehicle['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <?php if ($report_type == 'trends'): ?>
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label for="comparison_period" class="form-label">Compare With</label>
                                            <select class="form-control" id="comparison_period" name="comparison_period">
                                                <option value="previous_month" <?= $comparison_period == 'previous_month' ? 'selected' : '' ?>>Previous Month</option>
                                                <option value="previous_year" <?= $comparison_period == 'previous_year' ? 'selected' : '' ?>>Previous Year</option>
                                                <option value="same_period_last_year" <?= $comparison_period == 'same_period_last_year' ? 'selected' : '' ?>>Same Period Last Year</option>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-2">
                                        <div class="mb-3">
                                            <label class="form-label">&nbsp;</label>
                                            <div>
                                                <button type="submit" class="btn btn-primary me-2">
                                                    <i class="mdi mdi-filter"></i> Generate
                                                </button>
                                                <a href="expense-report.php?report_type=<?= $report_type ?>" class="btn btn-secondary">
                                                    <i class="mdi mdi-refresh"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <h4 class="mb-0">
                                <?php
                                switch($report_type) {
                                    case 'summary':
                                        echo 'Expense Summary Report';
                                        break;
                                    case 'category':
                                        echo 'Category-wise Expense Report';
                                        break;
                                    case 'payment_method':
                                        echo 'Payment Method Analysis';
                                        break;
                                    case 'supplier':
                                        echo 'Supplier-wise Expense Report';
                                        break;
                                    case 'vehicle':
                                        echo 'Vehicle Expense Report';
                                        break;
                                    case 'trends':
                                        echo 'Expense Trend Analysis';
                                        break;
                                    default:
                                        echo 'Expense Report';
                                }
                                ?>
                            </h4>
                            <p class="text-muted">
                                <?= date('d M Y', strtotime($date_from)) ?> - <?= date('d M Y', strtotime($date_to)) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <?php if (!empty($report_summary)): ?>
                <div class="row">
                    <?php if ($report_type == 'summary'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Expenses</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_expenses'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= number_format($report_summary['transaction_count'] ?? 0) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Base Amount</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_base'] ?? 0), 2) ?></h4>
                                <small class="text-muted">GST: ₹<?= number_format(floatval($report_summary['total_gst'] ?? 0), 2) ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Cash vs Bank</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_cash'] ?? 0), 2) ?> / ₹<?= number_format(floatval($report_summary['total_bank'] ?? 0), 2) ?></h4>
                                <small class="text-muted">Cash / Bank</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Daily Average</h5>
                                <h4>₹<?= number_format(floatval($report_summary['average_daily'] ?? 0), 2) ?></h4>
                                <small class="text-muted">Over <?= number_format($report_summary['days_count'] ?? 0) ?> days</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'category'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Expenses</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_expenses'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= number_format($report_summary['transaction_count'] ?? 0) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Categories</h5>
                                <h4><?= number_format($report_summary['categories_count'] ?? 0) ?></h4>
                                <small class="text-muted">Active categories</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Largest Category</h5>
                                <h6><?= htmlspecialchars($report_summary['largest_category']['name'] ?? 'N/A') ?></h6>
                                <small class="text-muted">₹<?= number_format(floatval($report_summary['largest_category']['amount'] ?? 0), 2) ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">GST Total</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_gst'] ?? 0), 2) ?></h4>
                                <small class="text-muted">Input credit available</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'payment_method'): ?>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Expenses</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_expenses'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= number_format($report_summary['transaction_count'] ?? 0) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Payment Methods</h5>
                                <h4><?= number_format($report_summary['methods_count'] ?? 0) ?></h4>
                                <small class="text-muted">Active methods</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Base Amount</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_base'] ?? 0), 2) ?></h4>
                                <small class="text-muted">+ GST ₹<?= number_format(floatval($report_summary['total_gst'] ?? 0), 2) ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'supplier'): ?>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Expenses</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_expenses'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= number_format($report_summary['transaction_count'] ?? 0) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Suppliers</h5>
                                <h4><?= number_format($report_summary['suppliers_count'] ?? 0) ?></h4>
                                <small class="text-muted">Active suppliers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">GST Input Credit</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_gst'] ?? 0), 2) ?></h4>
                                <small class="text-muted">Total GST paid</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'vehicle'): ?>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Total Expenses</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_expenses'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= number_format($report_summary['transaction_count'] ?? 0) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Vehicles</h5>
                                <h4><?= number_format($report_summary['vehicles_count'] ?? 0) ?></h4>
                                <small class="text-muted">Active vehicles</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Fuel Expense</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_fuel'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= ($report_summary['vehicles_count'] ?? 0) > 0 ? number_format(floatval($report_summary['total_fuel'] ?? 0) / ($report_summary['vehicles_count'] ?? 1), 2) : 0 ?> per vehicle</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Maintenance</h5>
                                <h4>₹<?= number_format(floatval($report_summary['total_maintenance'] ?? 0), 2) ?></h4>
                                <small class="text-muted">Repairs & service</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'trends'): ?>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Current Period</h5>
                                <h4>₹<?= number_format(floatval($report_summary['current_total'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= htmlspecialchars($report_summary['current_period'] ?? '') ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Previous Period</h5>
                                <h4>₹<?= number_format(floatval($report_summary['prev_total'] ?? 0), 2) ?></h4>
                                <small class="text-muted"><?= htmlspecialchars($report_summary['prev_period'] ?? '') ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="font-size-14">Change</h5>
                                <h4 class="<?= ($report_summary['change'] ?? 0) >= 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= ($report_summary['change'] ?? 0) >= 0 ? '+' : '' ?><?= number_format(floatval($report_summary['change'] ?? 0), 2) ?>%
                                </h4>
                                <small class="text-muted"><?= ($report_summary['change'] ?? 0) >= 0 ? 'Increase' : 'Decrease' ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Chart Section -->
                <?php if (!empty($chart_data)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="card-title mb-0">
                                        <?php
                                        switch($report_type) {
                                            case 'summary':
                                                echo 'Daily Expense Trend';
                                                break;
                                            case 'category':
                                                echo 'Expense Distribution by Category';
                                                break;
                                            case 'payment_method':
                                                echo 'Payment Method Breakdown';
                                                break;
                                            case 'supplier':
                                                echo 'Top Suppliers by Expense';
                                                break;
                                            case 'vehicle':
                                                echo 'Vehicle Expense Comparison';
                                                break;
                                            case 'trends':
                                                echo 'Period Comparison';
                                                break;
                                        }
                                        ?>
                                    </h4>
                                    <?php if ($report_type != 'trends'): ?>
                                    <div>
                                        <select class="form-select form-select-sm" id="chartType" onchange="changeChartType(this.value)">
                                            <option value="bar" <?= $chart_type == 'bar' ? 'selected' : '' ?>>Bar Chart</option>
                                            <option value="pie" <?= $chart_type == 'pie' ? 'selected' : '' ?>>Pie Chart</option>
                                            <option value="line" <?= $chart_type == 'line' ? 'selected' : '' ?>>Line Chart</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div id="expense-chart" class="apex-charts" dir="ltr"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h4 class="card-title">Detailed Breakdown</h4>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success me-2">
                                                <i class="mdi mdi-export"></i> Export CSV
                                            </a>
                                            <button onclick="window.print()" class="btn btn-info">
                                                <i class="mdi mdi-printer"></i> Print
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead class="thead-light">
                                            <?php if ($report_type == 'summary'): ?>
                                            <tr>
                                                <th>Date</th>
                                                <th>Month</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Base Amount</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Average</th>
                                                <th class="text-end">Cash</th>
                                                <th class="text-end">Bank</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php elseif ($report_type == 'category'): ?>
                                            <tr>
                                                <th>Category</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Base Amount</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Average</th>
                                                <th class="text-end">Min</th>
                                                <th class="text-end">Max</th>
                                                <th class="text-end">Cash</th>
                                                <th class="text-end">Bank</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php elseif ($report_type == 'payment_method'): ?>
                                            <tr>
                                                <th>Payment Method</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Base Amount</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Average</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php elseif ($report_type == 'supplier'): ?>
                                            <tr>
                                                <th>Supplier</th>
                                                <th>Company</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Base Amount</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Average</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php elseif ($report_type == 'vehicle'): ?>
                                            <tr>
                                                <th>Vehicle</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Base Amount</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Average</th>
                                                <th class="text-end">Fuel</th>
                                                <th class="text-end">Maintenance</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php elseif ($report_type == 'trends'): ?>
                                            <tr>
                                                <th>Period</th>
                                                <th>Transactions</th>
                                                <th class="text-end">Total Expense</th>
                                                <th class="text-end">Change</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                            <?php endif; ?>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($report_data) && $report_type != 'trends'): ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">
                                                    <i class="mdi mdi-alert-circle-outline font-size-24"></i>
                                                    <p class="mt-2">No data available for the selected filters</p>
                                                </td>
                                            </tr>
                                            <?php elseif ($report_type == 'trends'): ?>
                                                <?php if (!empty($chart_data['current'])): ?>
                                                    <?php foreach ($chart_data['current'] as $index => $period): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($period['period'] ?? '') ?></td>
                                                        <td><?= number_format($period['transaction_count'] ?? 0) ?></td>
                                                        <td class="text-end">₹<?= number_format(floatval($period['total_expense'] ?? 0), 2) ?></td>
                                                        <td class="text-end">
                                                            <?php
                                                            $prev_amount = 0;
                                                            foreach (($chart_data['previous'] ?? []) as $prev) {
                                                                if (($prev['period'] ?? '') == ($period['period'] ?? '')) {
                                                                    $prev_amount = floatval($prev['total_expense'] ?? 0);
                                                                    break;
                                                                }
                                                            }
                                                            if ($prev_amount > 0) {
                                                                $change = (($period['total_expense'] ?? 0) - $prev_amount) / $prev_amount * 100;
                                                                echo '<span class="' . ($change >= 0 ? 'text-danger' : 'text-success') . '">';
                                                                echo ($change >= 0 ? '+' : '') . number_format($change, 2) . '%';
                                                                echo '</span>';
                                                            } else {
                                                                echo '<span class="text-muted">N/A</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="view-expense.php?id=<?= explode(',', $period['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View expenses for this period">
                                                                <i class="mdi mdi-eye"></i> View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted">No trend data available</td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php foreach ($report_data as $row): ?>
                                                <tr>
                                                    <?php if ($report_type == 'summary'): ?>
                                                    <td><?= date('d M Y', strtotime($row['expense_day'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars($row['expense_month'] ?? '') ?></td>
                                                    <td><?= number_format($row['transaction_count'] ?? 0) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_base_amount'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_gst'] ?? 0), 2) ?></td>
                                                    <td class="text-end"><strong>₹<?= number_format(floatval($row['total_expense'] ?? 0), 2) ?></strong></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['average_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['cash_total'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['bank_total'] ?? 0), 2) ?></td>
                                                    <td class="text-center">
                                                        <a href="view-expense.php?id=<?= explode(',', $row['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View first expense for this day">
                                                            <i class="mdi mdi-eye"></i> View
                                                        </a>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'category'): ?>
                                                    <td>
                                                        <strong><?= htmlspecialchars($expense_categories[$row['category']] ?? $row['category'] ?? 'N/A') ?></strong>
                                                    </td>
                                                    <td><?= number_format($row['transaction_count'] ?? 0) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_base_amount'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_gst'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['average_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['min_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['max_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['cash_total'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['bank_total'] ?? 0), 2) ?></td>
                                                    <td class="text-center">
                                                        <a href="view-expense.php?id=<?= explode(',', $row['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View first expense in this category">
                                                            <i class="mdi mdi-eye"></i> View
                                                        </a>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'payment_method'): ?>
                                                    <td>
                                                        <?php
                                                        $methodIcon = '';
                                                        switch($row['payment_method'] ?? '') {
                                                            case 'cash':
                                                                $methodIcon = 'mdi-cash';
                                                                break;
                                                            case 'bank':
                                                                $methodIcon = 'mdi-bank';
                                                                break;
                                                            case 'cheque':
                                                                $methodIcon = 'mdi-file-document';
                                                                break;
                                                            case 'online':
                                                                $methodIcon = 'mdi-credit-card';
                                                                break;
                                                        }
                                                        ?>
                                                        <?php if (!empty($methodIcon)): ?>
                                                        <i class="mdi <?= $methodIcon ?>"></i> 
                                                        <?php endif; ?>
                                                        <?= ucfirst($row['payment_method'] ?? 'Unknown') ?>
                                                    </td>
                                                    <td><?= number_format($row['transaction_count'] ?? 0) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_base_amount'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_gst'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['average_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-center">
                                                        <a href="view-expense.php?id=<?= explode(',', $row['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View first expense with this payment method">
                                                            <i class="mdi mdi-eye"></i> View
                                                        </a>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'supplier'): ?>
                                                    <td>
                                                        <strong><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown') ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($row['company_name'] ?? '') ?></td>
                                                    <td><?= number_format($row['transaction_count'] ?? 0) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_base_amount'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_gst'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['average_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-center">
                                                        <?php if (!empty($row['supplier_id']) && !empty($row['expense_ids'])): ?>
                                                        <a href="view-expense.php?id=<?= explode(',', $row['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View first expense from this supplier">
                                                            <i class="mdi mdi-eye"></i> View
                                                        </a>
                                                        <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <?php elseif ($report_type == 'vehicle'): ?>
                                                    <td>
                                                        <strong><?= htmlspecialchars($row['vehicle_number'] ?? 'Unknown') ?></strong>
                                                    </td>
                                                    <td><?= number_format($row['transaction_count'] ?? 0) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_base_amount'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_gst'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['total_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['average_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['fuel_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-end">₹<?= number_format(floatval($row['maintenance_expense'] ?? 0), 2) ?></td>
                                                    <td class="text-center">
                                                        <?php if (!empty($row['vehicle_id']) && !empty($row['expense_ids'])): ?>
                                                        <a href="view-expense.php?id=<?= explode(',', $row['expense_ids'])[0] ?>" class="btn btn-sm btn-soft-primary" title="View first expense for this vehicle">
                                                            <i class="mdi mdi-eye"></i> View
                                                        </a>
                                                        <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                        <?php endif; ?>
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

<!-- Category Expenses Modal -->
<div class="modal fade" id="categoryExpensesModal" tabindex="-1" aria-labelledby="categoryExpensesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryExpensesModalLabel">Category Expenses</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="categoryExpensesContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading expenses...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Expense Details Modal -->
<div class="modal fade" id="expenseDetailsModal" tabindex="-1" aria-labelledby="expenseDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="expenseDetailsModalLabel">Expense Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="expenseDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading expense details...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="viewFullExpenseBtn" class="btn btn-primary" target="_blank">View Full Details</a>
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

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    var chart;
    var currentChartType = '<?= $chart_type ?>';

    <?php if (!empty($chart_data)): ?>
    // Render chart based on report type
    function renderChart(chartType) {
        var options = {};
        
        <?php if ($report_type == 'summary'): ?>
        // Daily summary chart
        var chartData = <?= json_encode($chart_data) ?>;
        var dates = chartData.map(item => {
            if (item.expense_day) {
                var date = new Date(item.expense_day + 'T12:00:00');
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }
            return '';
        });
        var expenses = chartData.map(item => parseFloat(item.total_expense) || 0);
        
        options = {
            chart: {
                height: 350,
                type: chartType,
                toolbar: {
                    show: true
                }
            },
            series: [{
                name: 'Total Expense',
                data: expenses
            }],
            xaxis: {
                categories: dates,
                title: {
                    text: 'Date'
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
            colors: ['#556ee6'],
            dataLabels: {
                enabled: chartType === 'bar' ? false : true
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };
        
        <?php elseif ($report_type == 'category'): ?>
        // Category chart
        var chartData = <?= json_encode($chart_data) ?>;
        var categories = chartData.map(item => {
            var cat = item.category;
            <?php foreach ($expense_categories as $key => $value): ?>
            if (cat === '<?= $key ?>') return '<?= addslashes($value) ?>';
            <?php endforeach; ?>
            return cat || 'Unknown';
        });
        var expenses = chartData.map(item => parseFloat(item.total_expense) || 0);
        
        if (chartType === 'pie') {
            options = {
                chart: {
                    height: 350,
                    type: 'pie'
                },
                series: expenses,
                labels: categories,
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
        } else {
            options = {
                chart: {
                    height: 350,
                    type: chartType,
                    toolbar: {
                        show: true
                    }
                },
                series: [{
                    name: 'Total Expense',
                    data: expenses
                }],
                xaxis: {
                    categories: categories,
                    title: {
                        text: 'Category'
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
                colors: ['#556ee6'],
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return '₹' + val.toFixed(2);
                        }
                    }
                }
            };
        }
        
        <?php elseif ($report_type == 'payment_method'): ?>
        // Payment method chart
        var chartData = <?= json_encode($chart_data) ?>;
        var methods = chartData.map(item => item.payment_method ? item.payment_method.charAt(0).toUpperCase() + item.payment_method.slice(1) : 'Unknown');
        var expenses = chartData.map(item => parseFloat(item.total_expense) || 0);
        
        if (chartType === 'pie') {
            options = {
                chart: {
                    height: 350,
                    type: 'pie'
                },
                series: expenses,
                labels: methods,
                colors: ['#34c38f', '#556ee6', '#f1b44c', '#50a5f1'],
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
        } else {
            options = {
                chart: {
                    height: 350,
                    type: chartType,
                    toolbar: {
                        show: true
                    }
                },
                series: [{
                    name: 'Total Expense',
                    data: expenses
                }],
                xaxis: {
                    categories: methods,
                    title: {
                        text: 'Payment Method'
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
                colors: ['#556ee6'],
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return '₹' + val.toFixed(2);
                        }
                    }
                }
            };
        }
        
        <?php elseif ($report_type == 'supplier'): ?>
        // Supplier chart (top 10)
        var chartData = <?= json_encode(array_slice($chart_data, 0, 10)) ?>;
        var suppliers = chartData.map(item => {
            var name = item.supplier_name || 'Unknown';
            return name.length > 20 ? name.substring(0, 20) + '...' : name;
        });
        var expenses = chartData.map(item => parseFloat(item.total_expense) || 0);
        
        options = {
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
                    columnWidth: '50%',
                    endingShape: 'rounded'
                }
            },
            dataLabels: {
                enabled: false
            },
            series: [{
                name: 'Total Expense',
                data: expenses
            }],
            xaxis: {
                categories: suppliers,
                title: {
                    text: 'Supplier'
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
            colors: ['#34c38f'],
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };
        
        <?php elseif ($report_type == 'vehicle'): ?>
        // Vehicle chart
        var chartData = <?= json_encode($chart_data) ?>;
        var vehicles = chartData.map(item => item.vehicle_number ? item.vehicle_number : 'Unknown');
        var totals = chartData.map(item => parseFloat(item.total_expense) || 0);
        var fuel = chartData.map(item => parseFloat(item.fuel_expense) || 0);
        var maintenance = chartData.map(item => parseFloat(item.maintenance_expense) || 0);
        
        options = {
            chart: {
                height: 350,
                type: 'bar',
                stacked: true,
                toolbar: {
                    show: true
                }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '50%',
                    endingShape: 'rounded'
                }
            },
            dataLabels: {
                enabled: false
            },
            series: [
                {
                    name: 'Fuel',
                    data: fuel
                },
                {
                    name: 'Maintenance',
                    data: maintenance
                }
            ],
            xaxis: {
                categories: vehicles,
                title: {
                    text: 'Vehicle'
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
            colors: ['#f1b44c', '#556ee6'],
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };
        
        <?php elseif ($report_type == 'trends'): ?>
        // Trends comparison chart
        var chartData = <?= json_encode($chart_data) ?>;
        var labels = (chartData.labels || []).sort();
        var currentData = [];
        var previousData = [];
        
        labels.forEach(function(label) {
            var current = (chartData.current || []).find(item => item.period === label);
            var previous = (chartData.previous || []).find(item => item.period === label);
            
            currentData.push(current ? parseFloat(current.total_expense) || 0 : 0);
            previousData.push(previous ? parseFloat(previous.total_expense) || 0 : 0);
        });
        
        options = {
            chart: {
                height: 350,
                type: 'line',
                toolbar: {
                    show: true
                }
            },
            series: [
                {
                    name: 'Current Period',
                    data: currentData
                },
                {
                    name: 'Previous Period',
                    data: previousData
                }
            ],
            xaxis: {
                categories: labels,
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
            colors: ['#556ee6', '#f46a6a'],
            stroke: {
                curve: 'smooth',
                width: 3
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return '₹' + val.toFixed(2);
                    }
                }
            }
        };
        <?php endif; ?>
        
        // Destroy existing chart if any
        if (chart) {
            chart.destroy();
        }
        
        // Render new chart
        if (Object.keys(options).length > 0) {
            chart = new ApexCharts(document.querySelector("#expense-chart"), options);
            chart.render();
        }
    }

    // Initial render
    renderChart(currentChartType);

    // Change chart type
    function changeChartType(type) {
        currentChartType = type;
        renderChart(type);
    }
    <?php endif; ?>

    // View expense details
    function viewExpenseDetails(expenseId) {
        if (!expenseId) return;
        window.location.href = 'view-expense.php?id=' + expenseId;
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
    #report_type_selection, form, .apex-charts {
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
        font-size: 10pt;
    }
}

.card.bg-soft-primary, .card.bg-soft-success, .card.bg-soft-info,
.card.bg-soft-warning, .card.bg-soft-danger, .card.bg-soft-secondary {
    transition: transform 0.2s;
    cursor: pointer;
}

.card.bg-soft-primary:hover, .card.bg-soft-success:hover, .card.bg-soft-info:hover,
.card.bg-soft-warning:hover, .card.bg-soft-danger:hover, .card.bg-soft-secondary:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

/* Table text alignment */
.table td.text-end {
    text-align: right;
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

/* View button styling */
.btn-soft-primary {
    transition: all 0.3s;
}

.btn-soft-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(85, 110, 230, 0.3);
}

/* Modal table styling */
#categoryExpensesContent .table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

#categoryExpensesContent .table-hover tbody tr:hover {
    background-color: rgba(85, 110, 230, 0.05);
}
</style>

</body>
</html>