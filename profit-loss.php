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

// Handle AJAX request for report data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_report') {
    header('Content-Type: application/json');
    
    try {
        $report_type = $_GET['report_type'] ?? 'monthly';
        $period = $_GET['period'] ?? date('Y-m');
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        $compare_with = $_GET['compare_with'] ?? 'previous';
        $show_details = $_GET['show_details'] ?? 'summary';
        
        // Get current period data
        $current_data = getProfitLossData($pdo, $date_from, $date_to);
        
        // Get comparison data if needed
        $comparison_data = null;
        $comparison_period = '';
        
        if ($compare_with != 'none') {
            $comparison_dates = getComparisonDates($date_from, $date_to, $compare_with);
            if ($comparison_dates) {
                $comparison_data = getProfitLossData($pdo, $comparison_dates['from'], $comparison_dates['to']);
                $comparison_period = formatPeriod($comparison_dates['from'], $comparison_dates['to']);
            }
        }
        
        // Calculate ratios and percentages
        $ratios = calculateFinancialRatios($current_data, $comparison_data);
        
        // Generate HTML
        ob_start();
        ?>
        
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
                                <h4>₹<?= number_format($current_data['total_revenue'], 2) ?></h4>
                                <?php if ($comparison_data): ?>
                                <small class="<?= getChangeClass($current_data['total_revenue'], $comparison_data['total_revenue']) ?>">
                                    <i class="mdi mdi-<?= getChangeIcon($current_data['total_revenue'], $comparison_data['total_revenue']) ?>"></i>
                                    <?= number_format(abs(getChangePercent($current_data['total_revenue'], $comparison_data['total_revenue'])), 1) ?>%
                                </small>
                                <?php endif; ?>
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
                                <h4>₹<?= number_format($current_data['total_expenses'], 2) ?></h4>
                                <?php if ($comparison_data): ?>
                                <small class="<?= getChangeClass($current_data['total_expenses'], $comparison_data['total_expenses'], true) ?>">
                                    <i class="mdi mdi-<?= getChangeIcon($current_data['total_expenses'], $comparison_data['total_expenses'], true) ?>"></i>
                                    <?= number_format(abs(getChangePercent($current_data['total_expenses'], $comparison_data['total_expenses'])), 1) ?>%
                                </small>
                                <?php endif; ?>
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
                                <h4>₹<?= number_format($current_data['gross_profit'], 2) ?></h4>
                                <small class="<?= $current_data['gross_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Margin: <?= number_format($current_data['gross_margin'], 1) ?>%
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
                                <h4 class="<?= $current_data['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    ₹<?= number_format($current_data['net_profit'], 2) ?>
                                </h4>
                                <small class="<?= $current_data['net_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Margin: <?= number_format($current_data['net_margin'], 1) ?>%
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
                        
                        <div class="table-responsive">
                            <table class="table table-centered table-nowrap mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 40%;">Particulars</th>
                                        <th class="text-end">Amount (₹)</th>
                                        <th class="text-end">% of Revenue</th>
                                        <?php if ($comparison_data): ?>
                                        <th class="text-end">Previous (₹)</th>
                                        <th class="text-end">Change</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Revenue Section -->
                                    <tr class="table-info">
                                        <td colspan="5"><strong>REVENUE</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Sales Revenue</td>
                                        <td class="text-end">₹<?= number_format($current_data['sales_revenue'], 2) ?></td>
                                        <td class="text-end"><?= number_format($current_data['sales_revenue_percent'], 1) ?>%</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">₹<?= number_format($comparison_data['sales_revenue'], 2) ?></td>
                                        <td class="text-end <?= getChangeClass($current_data['sales_revenue'], $comparison_data['sales_revenue']) ?>">
                                            <?= getChangeIcon($current_data['sales_revenue'], $comparison_data['sales_revenue']) ?> 
                                            <?= number_format(getChangePercent($current_data['sales_revenue'], $comparison_data['sales_revenue']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Other Income</td>
                                        <td class="text-end">₹<?= number_format($current_data['other_income'], 2) ?></td>
                                        <td class="text-end"><?= number_format($current_data['other_income_percent'], 1) ?>%</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">₹<?= number_format($comparison_data['other_income'], 2) ?></td>
                                        <td class="text-end <?= getChangeClass($current_data['other_income'], $comparison_data['other_income']) ?>">
                                            <?= getChangeIcon($current_data['other_income'], $comparison_data['other_income']) ?> 
                                            <?= number_format(getChangePercent($current_data['other_income'], $comparison_data['other_income']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr class="border-top">
                                        <td><strong>Total Revenue</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($current_data['total_revenue'], 2) ?></strong></td>
                                        <td class="text-end"><strong>100%</strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['total_revenue'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['total_revenue'], $comparison_data['total_revenue']) ?>">
                                            <?= getChangeIcon($current_data['total_revenue'], $comparison_data['total_revenue']) ?> 
                                            <?= number_format(getChangePercent($current_data['total_revenue'], $comparison_data['total_revenue']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Cost of Goods Sold -->
                                    <tr class="table-info">
                                        <td colspan="5"><strong>COST OF GOODS SOLD</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Opening Stock</td>
                                        <td class="text-end">₹<?= number_format($current_data['opening_stock'], 2) ?></td>
                                        <td class="text-end"><?= number_format($current_data['opening_stock_percent'], 1) ?>%</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">₹<?= number_format($comparison_data['opening_stock'], 2) ?></td>
                                        <td class="text-end <?= getChangeClass($current_data['opening_stock'], $comparison_data['opening_stock']) ?>">
                                            <?= getChangeIcon($current_data['opening_stock'], $comparison_data['opening_stock']) ?> 
                                            <?= number_format(getChangePercent($current_data['opening_stock'], $comparison_data['opening_stock']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Purchases</td>
                                        <td class="text-end">₹<?= number_format($current_data['purchases'], 2) ?></td>
                                        <td class="text-end"><?= number_format($current_data['purchases_percent'], 1) ?>%</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">₹<?= number_format($comparison_data['purchases'], 2) ?></td>
                                        <td class="text-end <?= getChangeClass($current_data['purchases'], $comparison_data['purchases'], true) ?>">
                                            <?= getChangeIcon($current_data['purchases'], $comparison_data['purchases'], true) ?> 
                                            <?= number_format(getChangePercent($current_data['purchases'], $comparison_data['purchases']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Less: Closing Stock</td>
                                        <td class="text-end">(₹<?= number_format($current_data['closing_stock'], 2) ?>)</td>
                                        <td class="text-end">(<?= number_format($current_data['closing_stock_percent'], 1) ?>%)</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">(₹<?= number_format($comparison_data['closing_stock'], 2) ?>)</td>
                                        <td class="text-end <?= getChangeClass($current_data['closing_stock'], $comparison_data['closing_stock']) ?>">
                                            <?= getChangeIcon($current_data['closing_stock'], $comparison_data['closing_stock']) ?> 
                                            <?= number_format(getChangePercent($current_data['closing_stock'], $comparison_data['closing_stock']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr class="border-top">
                                        <td><strong>Cost of Goods Sold</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($current_data['cogs'], 2) ?></strong></td>
                                        <td class="text-end"><strong><?= number_format($current_data['cogs_percent'], 1) ?>%</strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['cogs'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['cogs'], $comparison_data['cogs'], true) ?>">
                                            <?= getChangeIcon($current_data['cogs'], $comparison_data['cogs'], true) ?> 
                                            <?= number_format(getChangePercent($current_data['cogs'], $comparison_data['cogs']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Gross Profit -->
                                    <tr class="table-success">
                                        <td><strong>GROSS PROFIT</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($current_data['gross_profit'], 2) ?></strong></td>
                                        <td class="text-end"><strong><?= number_format($current_data['gross_margin'], 1) ?>%</strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['gross_profit'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['gross_profit'], $comparison_data['gross_profit']) ?>">
                                            <?= getChangeIcon($current_data['gross_profit'], $comparison_data['gross_profit']) ?> 
                                            <?= number_format(getChangePercent($current_data['gross_profit'], $comparison_data['gross_profit']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Operating Expenses -->
                                    <tr class="table-info">
                                        <td colspan="5"><strong>OPERATING EXPENSES</strong></td>
                                    </tr>
                                    
                                    <?php if ($show_details == 'detailed'): ?>
                                        <?php foreach ($current_data['expense_categories'] as $category => $amount): ?>
                                        <tr>
                                            <td style="padding-left: 30px;"><?= htmlspecialchars($category) ?></td>
                                            <td class="text-end">₹<?= number_format($amount, 2) ?></td>
                                            <td class="text-end"><?= number_format(($amount / max($current_data['total_revenue'], 1)) * 100, 1) ?>%</td>
                                            <?php if ($comparison_data && isset($comparison_data['expense_categories'][$category])): ?>
                                            <td class="text-end">₹<?= number_format($comparison_data['expense_categories'][$category], 2) ?></td>
                                            <td class="text-end <?= getChangeClass($amount, $comparison_data['expense_categories'][$category], true) ?>">
                                                <?= getChangeIcon($amount, $comparison_data['expense_categories'][$category], true) ?> 
                                                <?= number_format(getChangePercent($amount, $comparison_data['expense_categories'][$category]), 1) ?>%
                                            </td>
                                            <?php elseif ($comparison_data): ?>
                                            <td class="text-end">-</td>
                                            <td class="text-end">-</td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td style="padding-left: 30px;">Administrative Expenses</td>
                                            <td class="text-end">₹<?= number_format($current_data['admin_expenses'], 2) ?></td>
                                            <td class="text-end"><?= number_format($current_data['admin_expenses_percent'], 1) ?>%</td>
                                            <?php if ($comparison_data): ?>
                                            <td class="text-end">₹<?= number_format($comparison_data['admin_expenses'], 2) ?></td>
                                            <td class="text-end <?= getChangeClass($current_data['admin_expenses'], $comparison_data['admin_expenses'], true) ?>">
                                                <?= getChangeIcon($current_data['admin_expenses'], $comparison_data['admin_expenses'], true) ?> 
                                                <?= number_format(getChangePercent($current_data['admin_expenses'], $comparison_data['admin_expenses']), 1) ?>%
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <tr>
                                            <td style="padding-left: 30px;">Selling & Marketing</td>
                                            <td class="text-end">₹<?= number_format($current_data['selling_expenses'], 2) ?></td>
                                            <td class="text-end"><?= number_format($current_data['selling_expenses_percent'], 1) ?>%</td>
                                            <?php if ($comparison_data): ?>
                                            <td class="text-end">₹<?= number_format($comparison_data['selling_expenses'], 2) ?></td>
                                            <td class="text-end <?= getChangeClass($current_data['selling_expenses'], $comparison_data['selling_expenses'], true) ?>">
                                                <?= getChangeIcon($current_data['selling_expenses'], $comparison_data['selling_expenses'], true) ?> 
                                                <?= number_format(getChangePercent($current_data['selling_expenses'], $comparison_data['selling_expenses']), 1) ?>%
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <tr>
                                            <td style="padding-left: 30px;">Financial Expenses</td>
                                            <td class="text-end">₹<?= number_format($current_data['financial_expenses'], 2) ?></td>
                                            <td class="text-end"><?= number_format($current_data['financial_expenses_percent'], 1) ?>%</td>
                                            <?php if ($comparison_data): ?>
                                            <td class="text-end">₹<?= number_format($comparison_data['financial_expenses'], 2) ?></td>
                                            <td class="text-end <?= getChangeClass($current_data['financial_expenses'], $comparison_data['financial_expenses'], true) ?>">
                                                <?= getChangeIcon($current_data['financial_expenses'], $comparison_data['financial_expenses'], true) ?> 
                                                <?= number_format(getChangePercent($current_data['financial_expenses'], $comparison_data['financial_expenses']), 1) ?>%
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <tr>
                                            <td style="padding-left: 30px;">Other Expenses</td>
                                            <td class="text-end">₹<?= number_format($current_data['other_expenses'], 2) ?></td>
                                            <td class="text-end"><?= number_format($current_data['other_expenses_percent'], 1) ?>%</td>
                                            <?php if ($comparison_data): ?>
                                            <td class="text-end">₹<?= number_format($comparison_data['other_expenses'], 2) ?></td>
                                            <td class="text-end <?= getChangeClass($current_data['other_expenses'], $comparison_data['other_expenses'], true) ?>">
                                                <?= getChangeIcon($current_data['other_expenses'], $comparison_data['other_expenses'], true) ?> 
                                                <?= number_format(getChangePercent($current_data['other_expenses'], $comparison_data['other_expenses']), 1) ?>%
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="border-top">
                                        <td><strong>Total Operating Expenses</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($current_data['total_operating_expenses'], 2) ?></strong></td>
                                        <td class="text-end"><strong><?= number_format($current_data['operating_expenses_percent'], 1) ?>%</strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['total_operating_expenses'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['total_operating_expenses'], $comparison_data['total_operating_expenses'], true) ?>">
                                            <?= getChangeIcon($current_data['total_operating_expenses'], $comparison_data['total_operating_expenses'], true) ?> 
                                            <?= number_format(getChangePercent($current_data['total_operating_expenses'], $comparison_data['total_operating_expenses']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Operating Profit -->
                                    <tr class="table-success">
                                        <td><strong>OPERATING PROFIT</strong></td>
                                        <td class="text-end"><strong>₹<?= number_format($current_data['operating_profit'], 2) ?></strong></td>
                                        <td class="text-end"><strong><?= number_format($current_data['operating_margin'], 1) ?>%</strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['operating_profit'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['operating_profit'], $comparison_data['operating_profit']) ?>">
                                            <?= getChangeIcon($current_data['operating_profit'], $comparison_data['operating_profit']) ?> 
                                            <?= number_format(getChangePercent($current_data['operating_profit'], $comparison_data['operating_profit']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Other Income/Expenses -->
                                    <tr>
                                        <td style="padding-left: 30px;">Add: Other Income</td>
                                        <td class="text-end">₹<?= number_format($current_data['other_income'], 2) ?></td>
                                        <td class="text-end"><?= number_format($current_data['other_income_percent'], 1) ?>%</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">₹<?= number_format($comparison_data['other_income'], 2) ?></td>
                                        <td class="text-end <?= getChangeClass($current_data['other_income'], $comparison_data['other_income']) ?>">
                                            <?= getChangeIcon($current_data['other_income'], $comparison_data['other_income']) ?> 
                                            <?= number_format(getChangePercent($current_data['other_income'], $comparison_data['other_income']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding-left: 30px;">Less: Interest & Taxes</td>
                                        <td class="text-end">(₹<?= number_format($current_data['interest_taxes'], 2) ?>)</td>
                                        <td class="text-end">(<?= number_format($current_data['interest_taxes_percent'], 1) ?>%)</td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end">(₹<?= number_format($comparison_data['interest_taxes'], 2) ?>)</td>
                                        <td class="text-end <?= getChangeClass($current_data['interest_taxes'], $comparison_data['interest_taxes'], true) ?>">
                                            <?= getChangeIcon($current_data['interest_taxes'], $comparison_data['interest_taxes'], true) ?> 
                                            <?= number_format(getChangePercent($current_data['interest_taxes'], $comparison_data['interest_taxes']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
                                    </tr>

                                    <!-- Net Profit -->
                                    <tr class="table-primary">
                                        <td><strong>NET PROFIT</strong></td>
                                        <td class="text-end"><strong class="<?= $current_data['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            ₹<?= number_format($current_data['net_profit'], 2) ?>
                                        </strong></td>
                                        <td class="text-end"><strong class="<?= $current_data['net_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($current_data['net_margin'], 1) ?>%
                                        </strong></td>
                                        <?php if ($comparison_data): ?>
                                        <td class="text-end"><strong>₹<?= number_format($comparison_data['net_profit'], 2) ?></strong></td>
                                        <td class="text-end <?= getChangeClass($current_data['net_profit'], $comparison_data['net_profit']) ?>">
                                            <?= getChangeIcon($current_data['net_profit'], $comparison_data['net_profit']) ?> 
                                            <?= number_format(getChangePercent($current_data['net_profit'], $comparison_data['net_profit']), 1) ?>%
                                        </td>
                                        <?php endif; ?>
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
                                                <td class="text-end"><strong><?= number_format($ratios['gross_margin'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Operating Profit Margin</td>
                                                <td class="text-end"><strong><?= number_format($ratios['operating_margin'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Net Profit Margin</td>
                                                <td class="text-end"><strong><?= number_format($ratios['net_margin'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Return on Sales</td>
                                                <td class="text-end"><strong><?= number_format($ratios['return_on_sales'], 1) ?>%</strong></td>
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
                                                <td class="text-end"><strong><?= number_format($ratios['operating_expense_ratio'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Admin Expense Ratio</td>
                                                <td class="text-end"><strong><?= number_format($ratios['admin_expense_ratio'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Selling Expense Ratio</td>
                                                <td class="text-end"><strong><?= number_format($ratios['selling_expense_ratio'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Financial Expense Ratio</td>
                                                <td class="text-end"><strong><?= number_format($ratios['financial_expense_ratio'], 1) ?>%</strong></td>
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
                                                <td class="text-end"><strong><?= number_format($ratios['cogs_to_revenue'], 1) ?>%</strong></td>
                                            </tr>
                                            <tr>
                                                <td>Operating Expenses / Revenue</td>
                                                <td class="text-end"><strong><?= number_format($ratios['opex_to_revenue'], 1) ?>%</strong></td>
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
                                                <td class="text-end"><strong>₹<?= number_format($ratios['breakeven_point'], 2) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td>Margin of Safety</td>
                                                <td class="text-end"><strong><?= number_format($ratios['margin_of_safety'], 1) ?>%</strong></td>
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

        <?php
        $html = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'current_data' => $current_data,
            'comparison_data' => $comparison_data,
            'ratios' => $ratios
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

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
        $amount = $exp['amount'];
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
    $sales_revenue = $sales['total_sales'] ?? 0;
    $total_revenue = $sales_revenue + $other_income;
    
    $cogs = $opening_stock + $purchases - $closing_stock;
    $gross_profit = $sales_revenue - $cogs;
    $gross_margin = $total_revenue > 0 ? ($gross_profit / $total_revenue) * 100 : 0;
    
    $total_operating_expenses = $admin_expenses + $selling_expenses + $financial_expenses + $other_expenses;
    $operating_profit = $gross_profit - $total_operating_expenses;
    $operating_margin = $total_revenue > 0 ? ($operating_profit / $total_revenue) * 100 : 0;
    
    $net_profit = $operating_profit + $other_income - $interest_taxes;
    $net_margin = $total_revenue > 0 ? ($net_profit / $total_revenue) * 100 : 0;
    
    // Calculate percentages
    $sales_revenue_percent = $total_revenue > 0 ? ($sales_revenue / $total_revenue) * 100 : 0;
    $other_income_percent = $total_revenue > 0 ? ($other_income / $total_revenue) * 100 : 0;
    $cogs_percent = $total_revenue > 0 ? ($cogs / $total_revenue) * 100 : 0;
    $opening_stock_percent = $total_revenue > 0 ? ($opening_stock / $total_revenue) * 100 : 0;
    $purchases_percent = $total_revenue > 0 ? ($purchases / $total_revenue) * 100 : 0;
    $closing_stock_percent = $total_revenue > 0 ? ($closing_stock / $total_revenue) * 100 : 0;
    $admin_expenses_percent = $total_revenue > 0 ? ($admin_expenses / $total_revenue) * 100 : 0;
    $selling_expenses_percent = $total_revenue > 0 ? ($selling_expenses / $total_revenue) * 100 : 0;
    $financial_expenses_percent = $total_revenue > 0 ? ($financial_expenses / $total_revenue) * 100 : 0;
    $other_expenses_percent = $total_revenue > 0 ? ($other_expenses / $total_revenue) * 100 : 0;
    $operating_expenses_percent = $total_revenue > 0 ? ($total_operating_expenses / $total_revenue) * 100 : 0;
    $interest_taxes_percent = $total_revenue > 0 ? ($interest_taxes / $total_revenue) * 100 : 0;
    
    return [
        // Revenue
        'sales_revenue' => $sales_revenue,
        'sales_revenue_percent' => $sales_revenue_percent,
        'other_income' => $other_income,
        'other_income_percent' => $other_income_percent,
        'total_revenue' => $total_revenue,
        
        // COGS
        'opening_stock' => $opening_stock,
        'opening_stock_percent' => $opening_stock_percent,
        'purchases' => $purchases,
        'purchases_percent' => $purchases_percent,
        'closing_stock' => $closing_stock,
        'closing_stock_percent' => $closing_stock_percent,
        'cogs' => $cogs,
        'cogs_percent' => $cogs_percent,
        
        // Gross Profit
        'gross_profit' => $gross_profit,
        'gross_margin' => $gross_margin,
        
        // Operating Expenses
        'admin_expenses' => $admin_expenses,
        'admin_expenses_percent' => $admin_expenses_percent,
        'selling_expenses' => $selling_expenses,
        'selling_expenses_percent' => $selling_expenses_percent,
        'financial_expenses' => $financial_expenses,
        'financial_expenses_percent' => $financial_expenses_percent,
        'other_expenses' => $other_expenses,
        'other_expenses_percent' => $other_expenses_percent,
        'total_operating_expenses' => $total_operating_expenses,
        'operating_expenses_percent' => $operating_expenses_percent,
        'expense_categories' => $expense_categories,
        
        // Operating Profit
        'operating_profit' => $operating_profit,
        'operating_margin' => $operating_margin,
        
        // Other
        'interest_taxes' => $interest_taxes,
        'interest_taxes_percent' => $interest_taxes_percent,
        
        // Net Profit
        'net_profit' => $net_profit,
        'net_margin' => $net_margin
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

// Function to calculate financial ratios
function calculateFinancialRatios($current, $comparison = null) {
    $revenue = $current['total_revenue'];
    $cogs = $current['cogs'];
    $opex = $current['total_operating_expenses'];
    $operating_profit = $current['operating_profit'];
    $net_profit = $current['net_profit'];
    $interest = $current['interest_taxes'];
    
    return [
        'gross_margin' => $revenue > 0 ? ($current['gross_profit'] / $revenue) * 100 : 0,
        'operating_margin' => $revenue > 0 ? ($operating_profit / $revenue) * 100 : 0,
        'net_margin' => $revenue > 0 ? ($net_profit / $revenue) * 100 : 0,
        'return_on_sales' => $revenue > 0 ? ($net_profit / $revenue) * 100 : 0,
        'operating_expense_ratio' => $revenue > 0 ? ($opex / $revenue) * 100 : 0,
        'admin_expense_ratio' => $revenue > 0 ? ($current['admin_expenses'] / $revenue) * 100 : 0,
        'selling_expense_ratio' => $revenue > 0 ? ($current['selling_expenses'] / $revenue) * 100 : 0,
        'financial_expense_ratio' => $revenue > 0 ? ($current['financial_expenses'] / $revenue) * 100 : 0,
        'cogs_to_revenue' => $revenue > 0 ? ($cogs / $revenue) * 100 : 0,
        'opex_to_revenue' => $revenue > 0 ? ($opex / $revenue) * 100 : 0,
        'interest_coverage' => $interest > 0 ? $operating_profit / $interest : 0,
        'breakeven_point' => $current['gross_margin'] > 0 ? ($opex / ($current['gross_margin'] / 100)) : 0,
        'margin_of_safety' => $revenue > 0 ? (($revenue - ($opex / ($current['gross_margin'] / 100))) / $revenue) * 100 : 0
    ];
}

// Helper functions for comparison
function getChangeClass($current, $previous, $inverse = false) {
    $change = $current - $previous;
    if ($inverse) {
        return $change < 0 ? 'text-success' : ($change > 0 ? 'text-danger' : 'text-muted');
    }
    return $change > 0 ? 'text-success' : ($change < 0 ? 'text-danger' : 'text-muted');
}

function getChangeIcon($current, $previous, $inverse = false) {
    $change = $current - $previous;
    if ($inverse) {
        return $change < 0 ? 'mdi-arrow-down' : ($change > 0 ? 'mdi-arrow-up' : 'mdi-minus');
    }
    return $change > 0 ? 'mdi-arrow-up' : ($change < 0 ? 'mdi-arrow-down' : 'mdi-minus');
}

function getChangePercent($current, $previous) {
    if ($previous == 0) return 100;
    return (($current - $previous) / $previous) * 100;
}

// Get initial data for page load
$current_data = getProfitLossData($pdo, $date_from, $date_to);
$ratios = calculateFinancialRatios($current_data);
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

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-<?= $report_type == 'monthly' ? 'primary' : 'outline-primary' ?>" id="btnMonthly">
                                        <i class="mdi mdi-calendar-month"></i> Monthly
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'quarterly' ? 'primary' : 'outline-primary' ?>" id="btnQuarterly">
                                        <i class="mdi mdi-calendar-clock"></i> Quarterly
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'yearly' ? 'primary' : 'outline-primary' ?>" id="btnYearly">
                                        <i class="mdi mdi-calendar"></i> Yearly
                                    </button>
                                    <button type="button" class="btn btn-<?= $report_type == 'custom' ? 'primary' : 'outline-primary' ?>" id="btnCustom">
                                        <i class="mdi mdi-calendar-range"></i> Custom
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
                                            <button type="button" class="btn btn-primary me-2" id="applyFilterBtn">
                                                <i class="mdi mdi-filter"></i> Generate Report
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
                                            <h4>₹<?= number_format($current_data['total_revenue'], 2) ?></h4>
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
                                            <h4>₹<?= number_format($current_data['total_expenses'] ?? 0, 2) ?></h4>
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
                                            <h4>₹<?= number_format($current_data['gross_profit'], 2) ?></h4>
                                            <small class="<?= $current_data['gross_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                Margin: <?= number_format($current_data['gross_margin'], 1) ?>%
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
                                            <h4 class="<?= $current_data['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                ₹<?= number_format($current_data['net_profit'], 2) ?>
                                            </h4>
                                            <small class="<?= $current_data['net_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                Margin: <?= number_format($current_data['net_margin'], 1) ?>%
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
                                                    <td class="text-end">₹<?= number_format($current_data['sales_revenue'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($current_data['sales_revenue_percent'], 1) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Other Income</td>
                                                    <td class="text-end">₹<?= number_format($current_data['other_income'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($current_data['other_income_percent'], 1) ?>%</td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td><strong>Total Revenue</strong></td>
                                                    <td class="text-end"><strong>₹<?= number_format($current_data['total_revenue'], 2) ?></strong></td>
                                                    <td class="text-end"><strong>100%</strong></td>
                                                </tr>

                                                <!-- Cost of Goods Sold -->
                                                <tr class="table-info">
                                                    <td colspan="3"><strong>COST OF GOODS SOLD</strong></td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Opening Stock</td>
                                                    <td class="text-end">₹<?= number_format($current_data['opening_stock'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($current_data['opening_stock_percent'], 1) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Purchases</td>
                                                    <td class="text-end">₹<?= number_format($current_data['purchases'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($current_data['purchases_percent'], 1) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Less: Closing Stock</td>
                                                    <td class="text-end">(₹<?= number_format($current_data['closing_stock'], 2) ?>)</td>
                                                    <td class="text-end">(<?= number_format($current_data['closing_stock_percent'], 1) ?>%)</td>
                                                </tr>
                                                <tr class="border-top">
                                                    <td><strong>Cost of Goods Sold</strong></td>
                                                    <td class="text-end"><strong>₹<?= number_format($current_data['cogs'], 2) ?></strong></td>
                                                    <td class="text-end"><strong><?= number_format($current_data['cogs_percent'], 1) ?>%</strong></td>
                                                </tr>

                                                <!-- Gross Profit -->
                                                <tr class="table-success">
                                                    <td><strong>GROSS PROFIT</strong></td>
                                                    <td class="text-end"><strong>₹<?= number_format($current_data['gross_profit'], 2) ?></strong></td>
                                                    <td class="text-end"><strong><?= number_format($current_data['gross_margin'], 1) ?>%</strong></td>
                                                </tr>

                                                <!-- Operating Expenses -->
                                                <tr class="table-info">
                                                    <td colspan="3"><strong>OPERATING EXPENSES</strong></td>
                                                </tr>
                                                
                                                <?php if ($show_details == 'detailed'): ?>
                                                    <?php foreach ($current_data['expense_categories'] as $category => $amount): ?>
                                                    <tr>
                                                        <td style="padding-left: 30px;"><?= htmlspecialchars($category) ?></td>
                                                        <td class="text-end">₹<?= number_format($amount, 2) ?></td>
                                                        <td class="text-end"><?= number_format(($amount / max($current_data['total_revenue'], 1)) * 100, 1) ?>%</td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Administrative Expenses</td>
                                                        <td class="text-end">₹<?= number_format($current_data['admin_expenses'], 2) ?></td>
                                                        <td class="text-end"><?= number_format($current_data['admin_expenses_percent'], 1) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Selling & Marketing</td>
                                                        <td class="text-end">₹<?= number_format($current_data['selling_expenses'], 2) ?></td>
                                                        <td class="text-end"><?= number_format($current_data['selling_expenses_percent'], 1) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Financial Expenses</td>
                                                        <td class="text-end">₹<?= number_format($current_data['financial_expenses'], 2) ?></td>
                                                        <td class="text-end"><?= number_format($current_data['financial_expenses_percent'], 1) ?>%</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-left: 30px;">Other Expenses</td>
                                                        <td class="text-end">₹<?= number_format($current_data['other_expenses'], 2) ?></td>
                                                        <td class="text-end"><?= number_format($current_data['other_expenses_percent'], 1) ?>%</td>
                                                    </tr>
                                                <?php endif; ?>
                                                
                                                <tr class="border-top">
                                                    <td><strong>Total Operating Expenses</strong></td>
                                                    <td class="text-end"><strong>₹<?= number_format($current_data['total_operating_expenses'], 2) ?></strong></td>
                                                    <td class="text-end"><strong><?= number_format($current_data['operating_expenses_percent'], 1) ?>%</strong></td>
                                                </tr>

                                                <!-- Operating Profit -->
                                                <tr class="table-success">
                                                    <td><strong>OPERATING PROFIT</strong></td>
                                                    <td class="text-end"><strong>₹<?= number_format($current_data['operating_profit'], 2) ?></strong></td>
                                                    <td class="text-end"><strong><?= number_format($current_data['operating_margin'], 1) ?>%</strong></td>
                                                </tr>

                                                <!-- Other Income/Expenses -->
                                                <tr>
                                                    <td style="padding-left: 30px;">Add: Other Income</td>
                                                    <td class="text-end">₹<?= number_format($current_data['other_income'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($current_data['other_income_percent'], 1) ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td style="padding-left: 30px;">Less: Interest & Taxes</td>
                                                    <td class="text-end">(₹<?= number_format($current_data['interest_taxes'], 2) ?>)</td>
                                                    <td class="text-end">(<?= number_format($current_data['interest_taxes_percent'], 1) ?>%)</td>
                                                </tr>

                                                <!-- Net Profit -->
                                                <tr class="table-primary">
                                                    <td><strong>NET PROFIT</strong></td>
                                                    <td class="text-end"><strong class="<?= $current_data['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                        ₹<?= number_format($current_data['net_profit'], 2) ?>
                                                    </strong></td>
                                                    <td class="text-end"><strong class="<?= $current_data['net_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                        <?= number_format($current_data['net_margin'], 1) ?>%
                                                    </strong></td>
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
                                                            <td class="text-end"><strong><?= number_format($ratios['gross_margin'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Operating Profit Margin</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['operating_margin'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Net Profit Margin</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['net_margin'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Return on Sales</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['return_on_sales'], 1) ?>%</strong></td>
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
                                                            <td class="text-end"><strong><?= number_format($ratios['operating_expense_ratio'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Admin Expense Ratio</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['admin_expense_ratio'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Selling Expense Ratio</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['selling_expense_ratio'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Financial Expense Ratio</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['financial_expense_ratio'], 1) ?>%</strong></td>
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
                                                            <td class="text-end"><strong><?= number_format($ratios['cogs_to_revenue'], 1) ?>%</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Operating Expenses / Revenue</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['opex_to_revenue'], 1) ?>%</strong></td>
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
                                                            <td class="text-end"><strong>₹<?= number_format($ratios['breakeven_point'], 2) ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <td>Margin of Safety</td>
                                                            <td class="text-end"><strong><?= number_format($ratios['margin_of_safety'], 1) ?>%</strong></td>
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

    // Report type buttons
    document.getElementById('btnMonthly')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('monthly');
    });

    document.getElementById('btnQuarterly')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('quarterly');
    });

    document.getElementById('btnYearly')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('yearly');
    });

    document.getElementById('btnCustom')?.addEventListener('click', function(e) {
        e.preventDefault();
        updateReportType('custom');
    });

    // Update report type and show/hide fields
    function updateReportType(type) {
        document.getElementById('report_type').value = type;
        
        // Update button styles
        const buttons = [
            { id: 'btnMonthly', type: 'monthly' },
            { id: 'btnQuarterly', type: 'quarterly' },
            { id: 'btnYearly', type: 'yearly' },
            { id: 'btnCustom', type: 'custom' }
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
        
        // Show/hide period select and custom dates
        if (type === 'custom') {
            document.getElementById('period_select').style.display = 'none';
            document.getElementById('custom_dates').style.display = 'block';
        } else {
            document.getElementById('period_select').style.display = 'block';
            document.getElementById('custom_dates').style.display = 'none';
            
            // Update period options based on type
            updatePeriodOptions(type);
        }
        
        // Load report data
        loadReportData();
    }

    // Update period options based on report type
    function updatePeriodOptions(type) {
        const periodSelect = document.getElementById('period');
        if (!periodSelect) return;
        
        let options = '';
        
        if (type === 'monthly') {
            for (let i = 0; i < 12; i++) {
                const date = new Date();
                date.setMonth(date.getMonth() - i);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const value = `${year}-${month}`;
                const label = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
                options += `<option value="${value}">${label}</option>`;
            }
        } else if (type === 'quarterly') {
            const quarters = {
                '01-03': 'Q1 (Jan-Mar)',
                '04-06': 'Q2 (Apr-Jun)',
                '07-09': 'Q3 (Jul-Sep)',
                '10-12': 'Q4 (Oct-Dec)'
            };
            const currentYear = new Date().getFullYear();
            for (let i = 0; i < 4; i++) {
                const year = currentYear - Math.floor(i / 4);
                const quarterNum = 4 - (i % 4);
                const quarterKey = Object.keys(quarters)[quarterNum - 1];
                const value = `${year}-${quarterKey}`;
                const label = `${quarters[quarterKey]} ${year}`;
                options += `<option value="${value}">${label}</option>`;
            }
        } else if (type === 'yearly') {
            const currentYear = new Date().getFullYear();
            for (let i = 0; i < 5; i++) {
                const year = currentYear - i;
                options += `<option value="${year}">${year}</option>`;
            }
        }
        
        periodSelect.innerHTML = options;
    }

    // Apply filter button
    document.getElementById('applyFilterBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        loadReportData();
    });

    // Reset filter button
    document.getElementById('resetFilterBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Reset to default values
        document.getElementById('report_type').value = 'monthly';
        document.getElementById('period').value = '<?= date('Y-m') ?>';
        document.getElementById('date_from').value = '<?= date('Y-m-01') ?>';
        document.getElementById('date_to').value = '<?= date('Y-m-d') ?>';
        document.getElementById('compare_with').value = 'none';
        document.getElementById('show_details').value = 'summary';
        
        // Update button styles
        updateReportType('monthly');
    });

    // Export button
    document.getElementById('btnExport')?.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Build export URL with current filters
        const params = new URLSearchParams();
        params.append('export', 'csv');
        params.append('report_type', document.getElementById('report_type').value);
        params.append('period', document.getElementById('period').value);
        params.append('date_from', document.getElementById('date_from').value);
        params.append('date_to', document.getElementById('date_to').value);
        params.append('compare_with', document.getElementById('compare_with').value);
        params.append('show_details', document.getElementById('show_details').value);
        
        window.location.href = 'profit-loss.php?' + params.toString();
    });

    // Load report data via AJAX
    function loadReportData() {
        const reportType = document.getElementById('report_type').value;
        const period = document.getElementById('period')?.value || '';
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const compareWith = document.getElementById('compare_with').value;
        const showDetails = document.getElementById('show_details').value;
        
        // Show loading indicator
        document.getElementById('loadingIndicator').style.display = 'block';
        document.getElementById('reportContent').style.opacity = '0.5';
        
        // Build URL with parameters
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', 'load_report');
        url.searchParams.set('report_type', reportType);
        url.searchParams.set('period', period);
        url.searchParams.set('date_from', dateFrom);
        url.searchParams.set('date_to', dateTo);
        url.searchParams.set('compare_with', compareWith);
        url.searchParams.set('show_details', showDetails);
        
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
                        text: 'Report generated successfully!',
                        timer: 1500,
                        showConfirmButton: false
                    });
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
</style>

</body>
</html>