<?php
session_start();


// Get user role for personalized documentation
$user_role = $_SESSION['user_role'] ?? 'sales';
$user_name = $_SESSION['full_name'] ?? 'User';

// Documentation sections
$doc_sections = [
    'getting-started' => [
        'title' => 'Getting Started',
        'icon' => 'mdi-rocket',
        'color' => 'primary',
        'description' => 'Learn the basics and get up and running quickly',
        'articles' => [
            'introduction' => [
                'title' => 'System Introduction',
                'content' => 'Welcome to the Business Management System. This comprehensive platform helps you manage customers, suppliers, sales, purchases, inventory, expenses, and generate detailed reports. The system is designed to streamline your business operations and provide real-time insights into your business performance.'
            ],
            'system-requirements' => [
                'title' => 'System Requirements',
                'content' => 'The system is web-based and can be accessed from any modern browser (Chrome, Firefox, Safari, Edge). For optimal performance, we recommend using the latest version of Google Chrome. Internet connection speed of at least 1 Mbps is recommended.'
            ],
            'login-first-time' => [
                'title' => 'First Time Login',
                'content' => 'When you first log in, you will be prompted to change your password. Use the credentials provided by your administrator. After successful login, you can update your profile information and preferences from the user menu in the top-right corner.'
            ],
            'dashboard-overview' => [
                'title' => 'Dashboard Overview',
                'content' => 'The dashboard provides a quick overview of your business metrics including total customers, suppliers, products, recent invoices, low stock alerts, and more. Key performance indicators are displayed in easy-to-read cards and charts.'
            ]
        ]
    ],
    'customers' => [
        'title' => 'Customer Management',
        'icon' => 'mdi-account-group',
        'color' => 'success',
        'description' => 'Manage your customers effectively',
        'articles' => [
            'add-customer' => [
                'title' => 'Adding a New Customer',
                'content' => 'Navigate to Customers → Add Customer. Fill in the required details including name, phone, email, and address. Customer code is auto-generated. You can also set credit limits and payment terms for credit customers.'
            ],
            'manage-customers' => [
                'title' => 'Managing Customers',
                'content' => 'Go to Customers → Manage Customers to view, edit, or deactivate customers. Use the search and filter options to find specific customers. You can export the customer list to CSV for backup or analysis.'
            ],
            'customer-outstanding' => [
                'title' => 'Customer Outstanding',
                'content' => 'Track pending payments from customers in the Customer Outstanding section. View aging analysis to see which invoices are due, overdue, or critical. You can also record payments against invoices here.'
            ],
            'customer-reports' => [
                'title' => 'Customer Reports',
                'content' => 'Generate detailed customer reports including sales history, payment patterns, and loyalty analysis. Access these from Reports → Customer Report.'
            ]
        ]
    ],
    'suppliers' => [
        'title' => 'Supplier Management',
        'icon' => 'mdi-truck',
        'color' => 'info',
        'description' => 'Manage your suppliers and purchases',
        'articles' => [
            'add-supplier' => [
                'title' => 'Adding a New Supplier',
                'content' => 'Navigate to Suppliers → Add Supplier. Enter supplier details including company name, contact person, GST number, and payment terms. Supplier code is auto-generated.'
            ],
            'manage-suppliers' => [
                'title' => 'Managing Suppliers',
                'content' => 'Go to Suppliers → Manage Suppliers to view and edit supplier information. You can search by name, code, or GST number. The list can be exported to CSV.'
            ],
            'supplier-outstanding' => [
                'title' => 'Supplier Outstanding',
                'content' => 'Track payments due to suppliers in the Supplier Outstanding section. View aging analysis and record payments against purchase orders.'
            ],
            'supplier-performance' => [
                'title' => 'Supplier Performance',
                'content' => 'Evaluate supplier performance based on on-time delivery, order accuracy, and pricing. Access supplier reports from Reports → Supplier Report.'
            ]
        ]
    ],
    'sales' => [
        'title' => 'Sales & Invoicing',
        'icon' => 'mdi-cart',
        'color' => 'warning',
        'description' => 'Create and manage sales invoices',
        'articles' => [
            'create-invoice' => [
                'title' => 'Creating an Invoice',
                'content' => 'Go to Sales → New Invoice. Select customer, add products/services, specify quantities and prices. The system automatically calculates GST and totals. You can save as draft or generate final invoice.'
            ],
            'manage-invoices' => [
                'title' => 'Managing Invoices',
                'content' => 'View all invoices in Sales → Invoices. Filter by status (paid, pending, overdue), date range, or customer. You can print invoices, send email copies, or record payments.'
            ],
            'invoice-settings' => [
                'title' => 'Invoice Settings',
                'content' => 'Customize your invoice template in Sales → Invoice Settings. Add company logo, set invoice numbering prefix, configure payment terms, and customize footer text.'
            ],
            'sales-analytics' => [
                'title' => 'Sales Analytics',
                'content' => 'Analyze sales performance with interactive charts and reports. View sales by product, customer, or time period. Access from Sales → Sales Analytics.'
            ]
        ]
    ],
    'purchases' => [
        'title' => 'Purchase Management',
        'icon' => 'mdi-cart-arrow-down',
        'color' => 'danger',
        'description' => 'Manage purchase orders and procurement',
        'articles' => [
            'create-po' => [
                'title' => 'Creating Purchase Orders',
                'content' => 'Navigate to Purchases → New PO. Select supplier, add products, specify quantities and negotiated prices. The system calculates totals and GST input credit.'
            ],
            'manage-pos' => [
                'title' => 'Managing Purchase Orders',
                'content' => 'View all purchase orders in Purchases → Purchase Orders. Track order status, receive items, and record payments against orders.'
            ],
            'gst-input-credit' => [
                'title' => 'GST Input Credit',
                'content' => 'Track and claim input tax credit on purchases. View eligible credits by GST rate and filing period. Access from Purchases → GST Input Credit.'
            ]
        ]
    ],
    'stock' => [
        'title' => 'Stock Management',
        'icon' => 'mdi-package-variant',
        'color' => 'secondary',
        'description' => 'Manage inventory and stock movements',
        'articles' => [
            'current-stock' => [
                'title' => 'Current Stock',
                'content' => 'View current inventory levels for all products. Monitor stock value at cost and selling price. Get alerts for low stock items that need reordering.'
            ],
            'stock-transactions' => [
                'title' => 'Stock Transactions',
                'content' => 'Track all stock movements including purchases, sales, adjustments, and transfers. View transaction history with running balance.'
            ],
            'daywise-stock' => [
                'title' => 'Day-wise Stock',
                'content' => 'Monitor stock levels day by day. Track opening stock, inward, outward, and closing stock for each day. Useful for inventory reconciliation.'
            ],
            'stock-reports' => [
                'title' => 'Stock Reports',
                'content' => 'Generate comprehensive stock reports including valuation, movement analysis, slow-moving items, and reorder recommendations.'
            ]
        ]
    ],
    'expenses' => [
        'title' => 'Expense Management',
        'icon' => 'mdi-wallet',
        'color' => 'dark',
        'description' => 'Track and manage business expenses',
        'articles' => [
            'add-expense' => [
                'title' => 'Adding Expenses',
                'content' => 'Go to Expenses → Add Expense. Select category, enter amount, description, and payment method. You can attach receipts and link expenses to suppliers or vehicles.'
            ],
            'manage-expenses' => [
                'title' => 'Managing Expenses',
                'content' => 'View and manage all expenses in Expenses → Manage Expenses. Filter by category, date range, or payment method. Export expense data for accounting.'
            ],
            'expense-reports' => [
                'title' => 'Expense Reports',
                'content' => 'Analyze expenses by category, supplier, or time period. Generate expense reports with charts and summaries from Reports → Expense Report.'
            ]
        ]
    ],
    'accounts' => [
        'title' => 'Accounts & Finance',
        'icon' => 'mdi-bank',
        'color' => 'primary',
        'description' => 'Financial management and reporting',
        'articles' => [
            'daywise-amounts' => [
                'title' => 'Day-wise Amounts',
                'content' => 'Track daily cash and bank balances. Monitor cash sales, credit sales, expenses, and bank transactions day by day. Access from Accounts → Day-wise Amounts.'
            ],
            'outstanding-report' => [
                'title' => 'Outstanding Report',
                'content' => 'Get a comprehensive view of all receivables and payables. View aging analysis for both customers and suppliers. Access from Accounts → Outstanding Report.'
            ],
            'profit-loss' => [
                'title' => 'Profit & Loss',
                'content' => 'Generate profit and loss statements for any period. View revenue, expenses, gross profit, and net profit with percentage breakdowns. Access from Reports → Profit & Loss.'
            ]
        ]
    ],
    'reports' => [
        'title' => 'Reports & Analytics',
        'icon' => 'mdi-chart-bar',
        'color' => 'success',
        'description' => 'Generate and analyze business reports',
        'articles' => [
            'sales-report' => [
                'title' => 'Sales Report',
                'content' => 'Generate detailed sales reports by day, month, year, product, or customer. View trends, compare periods, and export data for further analysis.'
            ],
            'purchase-report' => [
                'title' => 'Purchase Report',
                'content' => 'Analyze purchase patterns, supplier performance, and spending trends. View purchases by supplier, product, or category.'
            ],
            'gst-report' => [
                'title' => 'GST Report',
                'content' => 'Generate GST returns and summaries. View output GST, input GST, and net payable. Break down by GST rate and HSN code for filing returns.'
            ],
            'customer-report' => [
                'title' => 'Customer Report',
                'content' => 'Comprehensive customer analysis including sales, payments, outstanding, and loyalty metrics. Identify top customers and those needing attention.'
            ],
            'supplier-report' => [
                'title' => 'Supplier Report',
                'content' => 'Analyze supplier performance, purchase patterns, and outstanding payments. Evaluate reliability and identify key suppliers.'
            ]
        ]
    ],
    'settings' => [
        'title' => 'System Settings',
        'icon' => 'mdi-cog',
        'color' => 'info',
        'description' => 'Configure system preferences',
        'articles' => [
            'company-settings' => [
                'title' => 'Company Settings',
                'content' => 'Configure your company information including name, address, GST number, and logo. These details appear on invoices and reports.'
            ],
            'user-management' => [
                'title' => 'User Management',
                'content' => 'Manage system users, roles, and permissions. Add new users, reset passwords, and control access to different modules based on user roles.'
            ],
            'backup-restore' => [
                'title' => 'Backup & Restore',
                'content' => 'Create database backups to prevent data loss. Restore from previous backups if needed. Schedule automatic backups for peace of mind.'
            ],
            'system-preferences' => [
                'title' => 'System Preferences',
                'content' => 'Configure system-wide preferences including date format, currency, tax settings, and notification preferences.'
            ]
        ]
    ]
];

// Quick start guides
$quick_guides = [
    'admin' => [
        'title' => 'Administrator Guide',
        'steps' => [
            'Complete company setup in Settings → Company Settings',
            'Create user accounts with appropriate roles',
            'Configure GST rates and tax settings',
            'Set up product categories and add products',
            'Review system logs and audit trails regularly',
            'Schedule regular database backups'
        ]
    ],
    'sales' => [
        'title' => 'Sales Staff Guide',
        'steps' => [
            'Add new customers with complete details',
            'Create invoices for customer orders',
            'Record customer payments against invoices',
            'Generate sales reports for performance tracking',
            'Monitor customer outstanding for follow-up'
        ]
    ],
    'manager' => [
        'title' => 'Manager Guide',
        'steps' => [
            'Review daily sales and purchase reports',
            'Monitor stock levels and reorder alerts',
            'Analyze expense patterns and budgets',
            'Generate profit & loss statements',
            'Track key performance indicators on dashboard'
        ]
    ],
    'auditor' => [
        'title' => 'Auditor Guide',
        'steps' => [
            'Review audit logs for all transactions',
            'Verify GST calculations and input credit',
            'Reconcile day-wise amounts with bank statements',
            'Validate customer and supplier outstanding',
            'Generate financial reports for compliance'
        ]
    ]
];

// Keyboard shortcuts
$keyboard_shortcuts = [
    ['key' => 'Ctrl + N', 'action' => 'Create new invoice/PO/expense'],
    ['key' => 'Ctrl + S', 'action' => 'Save current form'],
    ['key' => 'Ctrl + F', 'action' => 'Search in current view'],
    ['key' => 'Ctrl + P', 'action' => 'Print current page/report'],
    ['key' => 'Ctrl + E', 'action' => 'Export to CSV'],
    ['key' => 'Ctrl + R', 'action' => 'Refresh current view'],
    ['key' => 'Ctrl + H', 'action' => 'Go to Help center'],
    ['key' => 'Ctrl + D', 'action' => 'Go to Dashboard'],
    ['key' => 'Ctrl + Shift + L', 'action' => 'Logout'],
    ['key' => 'Esc', 'action' => 'Close modal/ Cancel']
];

// API endpoints (if applicable)
$api_endpoints = [
    [
        'method' => 'GET',
        'endpoint' => '/api/customers',
        'description' => 'Get list of all customers',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/customers/{id}',
        'description' => 'Get customer by ID',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'POST',
        'endpoint' => '/api/customers',
        'description' => 'Create new customer',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'PUT',
        'endpoint' => '/api/customers/{id}',
        'description' => 'Update customer',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'DELETE',
        'endpoint' => '/api/customers/{id}',
        'description' => 'Delete customer',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/invoices',
        'description' => 'Get list of invoices',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'POST',
        'endpoint' => '/api/invoices',
        'description' => 'Create new invoice',
        'auth' => 'Bearer Token'
    ],
    [
        'method' => 'GET',
        'endpoint' => '/api/reports/sales',
        'description' => 'Generate sales report',
        'auth' => 'Bearer Token'
    ]
];

// Troubleshooting guide
$troubleshooting = [
    [
        'issue' => 'Cannot login to the system',
        'solution' => 'Check your username and password. Use "Forgot Password" option if needed. Contact administrator if problem persists.'
    ],
    [
        'issue' => 'Invoice not generating properly',
        'solution' => 'Verify customer and product details are complete. Check GST calculations. Ensure all required fields are filled.'
    ],
    [
        'issue' => 'Stock levels not updating',
        'solution' => 'Check stock transactions for the product. Verify that sales and purchases are properly recorded. Run stock reconciliation if needed.'
    ],
    [
        'issue' => 'Reports showing incorrect data',
        'solution' => 'Verify date range selection. Check filters applied. Ensure all transactions are properly recorded and not in draft/cancelled status.'
    ],
    [
        'issue' => 'GST calculations mismatch',
        'solution' => 'Verify GST rates for products. Check that correct GST rates are applied. Review input credit eligibility.'
    ],
    [
        'issue' => 'Email notifications not sending',
        'solution' => 'Check email configuration in settings. Verify SMTP settings. Check spam folder. Contact support if issue persists.'
    ]
];
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Font Awesome for additional icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Prism.js for code highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-tomorrow.min.css">
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
                            <h4 class="mb-0 font-size-18">Documentation</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Documentation</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Documentation Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-lg-8">
                                        <h4 class="mb-3">📚 System Documentation</h4>
                                        <p class="mb-4">Complete guide to using the Business Management System. Find detailed instructions, best practices, and troubleshooting tips.</p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="#quick-start" class="btn btn-primary"><i class="mdi mdi-speedometer me-2"></i>Quick Start Guide</a>
                                            <a href="#user-guides" class="btn btn-outline-primary"><i class="mdi mdi-book-open-page-variant me-2"></i>User Guides</a>
                                            <a href="#api" class="btn btn-outline-primary"><i class="mdi mdi-api me-2"></i>API Reference</a>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 text-center">
                                        <img src="assets/images/documentation.svg" alt="Documentation" class="img-fluid" style="max-height: 150px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Search -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8 mx-auto">
                                        <div class="input-group">
                                            <span class="input-group-text bg-primary text-white"><i class="mdi mdi-magnify"></i></span>
                                            <input type="text" class="form-control form-control-lg" id="docSearch" placeholder="Search documentation...">
                                            <button class="btn btn-primary" type="button" id="searchBtn"><i class="mdi mdi-magnify"></i> Search</button>
                                        </div>
                                        <div id="searchResults" class="mt-3" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Start Guide -->
                <div class="row" id="quick-start">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-speedometer me-2 text-primary"></i>
                                    Quick Start Guide for <?= ucfirst($user_role) ?>
                                </h4>
                                
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="timeline">
                                            <?php 
                                            $guide = $quick_guides[$user_role] ?? $quick_guides['sales'];
                                            foreach ($guide['steps'] as $index => $step): 
                                            ?>
                                            <div class="timeline-item">
                                                <div class="timeline-badge bg-<?= 
                                                    $index == 0 ? 'success' : 
                                                    ($index == count($guide['steps'])-1 ? 'primary' : 'secondary') 
                                                ?>">
                                                    <?= $index + 1 ?>
                                                </div>
                                                <div class="timeline-content">
                                                    <p class="mb-0"><?= $step ?></p>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6 class="card-title">Need Help?</h6>
                                                <p class="card-text">If you need assistance with any of these steps, check the detailed guides below or contact support.</p>
                                                <a href="help.php" class="btn btn-sm btn-primary w-100">
                                                    <i class="mdi mdi-help-circle me-2"></i>Visit Help Center
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Keyboard Shortcuts -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-keyboard me-2 text-primary"></i>
                                    Keyboard Shortcuts
                                </h4>
                                
                                <div class="row">
                                    <?php foreach (array_chunk($keyboard_shortcuts, 5) as $chunk): ?>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tbody>
                                                <?php foreach ($chunk as $shortcut): ?>
                                                <tr>
                                                    <td><span class="badge bg-dark"><?= $shortcut['key'] ?></span></td>
                                                    <td><?= $shortcut['action'] ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documentation Sections -->
                <div class="row" id="user-guides">
                    <div class="col-12">
                        <h4 class="mb-4">📖 User Guides</h4>
                    </div>
                </div>

                <!-- Documentation Accordion -->
                <div class="row">
                    <div class="col-12">
                        <div class="accordion" id="docAccordion">
                            <?php foreach ($doc_sections as $section_id => $section): ?>
                            <div class="accordion-item mb-3">
                                <h2 class="accordion-header" id="heading<?= $section_id ?>">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                            data-bs-target="#collapse<?= $section_id ?>" aria-expanded="false">
                                        <span class="badge bg-soft-<?= $section['color'] ?> text-<?= $section['color'] ?> p-2 me-3">
                                            <i class="mdi <?= $section['icon'] ?>"></i>
                                        </span>
                                        <span class="flex-grow-1"><?= $section['title'] ?></span>
                                        <small class="text-muted me-3"><?= count($section['articles']) ?> articles</small>
                                    </button>
                                </h2>
                                <div id="collapse<?= $section_id ?>" class="accordion-collapse collapse" 
                                     data-bs-parent="#docAccordion">
                                    <div class="accordion-body">
                                        <p class="text-muted mb-3"><?= $section['description'] ?></p>
                                        
                                        <div class="row">
                                            <?php foreach ($section['articles'] as $article_id => $article): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card h-100 border-0 bg-light">
                                                    <div class="card-body">
                                                        <h6 class="card-title">
                                                            <i class="mdi mdi-file-document text-<?= $section['color'] ?> me-2"></i>
                                                            <?= $article['title'] ?>
                                                        </h6>
                                                        <p class="card-text small text-muted">
                                                            <?= substr($article['content'], 0, 100) ?>...
                                                        </p>
                                                        <button class="btn btn-sm btn-link text-<?= $section['color'] ?> p-0 read-more-btn" 
                                                                data-section="<?= $section_id ?>" 
                                                                data-article="<?= $article_id ?>"
                                                                data-title="<?= htmlspecialchars($article['title']) ?>"
                                                                data-content="<?= htmlspecialchars($article['content']) ?>">
                                                            Read more <i class="mdi mdi-arrow-right"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- API Reference -->
                <div class="row mt-4" id="api">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-api me-2 text-primary"></i>
                                    API Reference
                                </h4>
                                
                                <p class="text-muted mb-4">Integrate with our REST API to extend functionality or connect with other applications.</p>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Method</th>
                                                <th>Endpoint</th>
                                                <th>Description</th>
                                                <th>Auth</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($api_endpoints as $endpoint): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $endpoint['method'] == 'GET' ? 'success' : 
                                                        ($endpoint['method'] == 'POST' ? 'primary' : 
                                                        ($endpoint['method'] == 'PUT' ? 'warning' : 'danger')) 
                                                    ?>">
                                                        <?= $endpoint['method'] ?>
                                                    </span>
                                                </td>
                                                <td><code><?= $endpoint['endpoint'] ?></code></td>
                                                <td><?= $endpoint['description'] ?></td>
                                                <td><small class="text-muted"><?= $endpoint['auth'] ?></small></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <h6>Example Request</h6>
                                    <pre><code class="language-bash">curl -X GET https://yourdomain.com/api/customers \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json"</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Troubleshooting -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-alert-circle me-2 text-primary"></i>
                                    Troubleshooting Guide
                                </h4>
                                
                                <div class="accordion" id="troubleshootingAccordion">
                                    <?php foreach ($troubleshooting as $index => $item): ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="troubleHeading<?= $index ?>">
                                            <button class="accordion-button collapsed" type="button" 
                                                    data-bs-toggle="collapse" 
                                                    data-bs-target="#troubleCollapse<?= $index ?>">
                                                <?= $item['issue'] ?>
                                            </button>
                                        </h2>
                                        <div id="troubleCollapse<?= $index ?>" class="accordion-collapse collapse">
                                            <div class="accordion-body">
                                                <?= $item['solution'] ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Version Information -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h5 class="mb-1">System Version</h5>
                                        <p class="text-muted mb-0">Version 2.0.0 (Build 2024.03.15)</p>
                                    </div>
                                    <div class="col-md-6 text-md-end">
                                        <h5 class="mb-1">Last Updated</h5>
                                        <p class="text-muted mb-0">March 15, 2024</p>
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

<!-- Article Modal -->
<div class="modal fade" id="articleModal" tabindex="-1" aria-labelledby="articleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="articleModalLabel">Article Title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="articleContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printArticle()">
                    <i class="mdi mdi-printer"></i> Print
                </button>
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
<!-- Prism.js for code highlighting -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/components/prism-bash.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Search functionality
    document.getElementById('searchBtn')?.addEventListener('click', function() {
        performSearch();
    });

    document.getElementById('docSearch')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });

    function performSearch() {
        const searchTerm = document.getElementById('docSearch').value.toLowerCase();
        const resultsDiv = document.getElementById('searchResults');
        
        if (searchTerm.length < 2) {
            Swal.fire({
                icon: 'warning',
                title: 'Search term too short',
                text: 'Please enter at least 2 characters to search',
                confirmButtonColor: '#556ee6'
            });
            return;
        }
        
        // Search in documentation sections
        let results = [];
        
        <?php foreach ($doc_sections as $section_id => $section): ?>
            <?php foreach ($section['articles'] as $article_id => $article): ?>
                if ('<?= strtolower($article['title']) ?>'.includes(searchTerm) || 
                    '<?= strtolower($article['content']) ?>'.includes(searchTerm)) {
                    results.push({
                        title: '<?= addslashes($article['title']) ?>',
                        section: '<?= addslashes($section['title']) ?>',
                        content: '<?= addslashes(substr($article['content'], 0, 150)) ?>...',
                        sectionId: '<?= $section_id ?>',
                        articleId: '<?= $article_id ?>'
                    });
                }
            <?php endforeach; ?>
        <?php endforeach; ?>
        
        if (results.length > 0) {
            let html = '<div class="list-group">';
            results.forEach(result => {
                html += `<a href="#" class="list-group-item list-group-item-action search-result" 
                           data-section="${result.sectionId}" 
                           data-article="${result.articleId}"
                           data-title="${result.title}"
                           data-content="${result.content}">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">${result.title}</h6>
                        <small class="text-primary">${result.section}</small>
                    </div>
                    <p class="mb-0 small text-muted">${result.content}</p>
                </a>`;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
            
            // Add click handlers to search results
            document.querySelectorAll('.search-result').forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const section = this.dataset.section;
                    const article = this.dataset.article;
                    const title = this.dataset.title;
                    const content = this.dataset.content;
                    
                    // Find full content from PHP data
                    <?php foreach ($doc_sections as $section_id => $section): ?>
                        <?php foreach ($section['articles'] as $article_id => $article): ?>
                            if (section === '<?= $section_id ?>' && article === '<?= $article_id ?>') {
                                showArticle('<?= addslashes($article['title']) ?>', '<?= addslashes($article['content']) ?>');
                            }
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                });
            });
        } else {
            resultsDiv.innerHTML = '<div class="alert alert-info">No results found for "' + searchTerm + '"</div>';
            resultsDiv.style.display = 'block';
        }
    }

    // Read more buttons
    document.querySelectorAll('.read-more-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const title = this.dataset.title;
            const content = this.dataset.content;
            showArticle(title, content);
        });
    });

    // Show article in modal
    function showArticle(title, content) {
        document.getElementById('articleModalLabel').textContent = title;
        document.getElementById('articleContent').innerHTML = `<p>${content}</p>`;
        
        const modal = new bootstrap.Modal(document.getElementById('articleModal'));
        modal.show();
    }

    // Print article
    function printArticle() {
        const title = document.getElementById('articleModalLabel').textContent;
        const content = document.getElementById('articleContent').innerHTML;
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>${title}</title>
                    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        @media print {
                            .btn { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h4 class="mb-4">${title}</h4>
                    ${content}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
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
    form, #searchResults, .accordion-button::after {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .accordion-item {
        border: 1px solid #dee2e6 !important;
        margin-bottom: 10px !important;
    }
    .accordion-collapse {
        display: block !important;
    }
}

/* Timeline styles */
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-badge {
    position: absolute;
    left: -30px;
    top: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    color: white;
    font-size: 12px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
}

.timeline-content {
    padding: 10px 15px;
    background-color: #f8f9fa;
    border-radius: 6px;
    margin-left: 10px;
}

/* Accordion customization */
.accordion-button:not(.collapsed) {
    background-color: rgba(85, 110, 230, 0.1);
    color: #556ee6;
}

.accordion-button .badge {
    min-width: 40px;
    text-align: center;
}

/* Code block styling */
pre {
    background-color: #2d2d2d;
    border-radius: 6px;
    padding: 15px;
    margin: 15px 0;
}

code.language-bash {
    color: #f8f8f2;
    text-shadow: none;
}

/* Search results */
#searchResults {
    max-height: 400px;
    overflow-y: auto;
    border-radius: 8px;
}

#searchResults::-webkit-scrollbar {
    width: 6px;
}

#searchResults::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

#searchResults::-webkit-scrollbar-thumb {
    background: #556ee6;
    border-radius: 10px;
}

/* Card hover effects */
.card.bg-light {
    transition: all 0.3s;
    border: 1px solid transparent;
}

.card.bg-light:hover {
    border-color: #556ee6;
    box-shadow: 0 5px 15px rgba(85, 110, 230, 0.1);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .timeline {
        padding-left: 25px;
    }
    
    .timeline-badge {
        left: -25px;
        width: 20px;
        height: 20px;
        font-size: 10px;
    }
    
    .timeline-content {
        padding: 8px 12px;
    }
}

/* Loading state */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.65;
}

.btn-loading:after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Version badge */
.version-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 10px;
    opacity: 0.7;
}

/* Article modal */
#articleContent {
    line-height: 1.8;
}

#articleContent p {
    margin-bottom: 1rem;
}

#articleContent ul, #articleContent ol {
    margin-bottom: 1rem;
    padding-left: 2rem;
}

#articleContent h1, #articleContent h2, #articleContent h3, 
#articleContent h4, #articleContent h5, #articleContent h6 {
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}
</style>

</body>
</html>