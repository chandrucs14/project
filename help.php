<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user role for personalized help content
$user_role = $_SESSION['user_role'] ?? 'sales';
$user_name = $_SESSION['full_name'] ?? 'User';

// Help categories
$help_categories = [
    'getting-started' => [
        'name' => 'Getting Started',
        'icon' => 'mdi-rocket',
        'description' => 'New to the system? Start here',
        'color' => 'primary'
    ],
    'customers' => [
        'name' => 'Customers',
        'icon' => 'mdi-account-group',
        'description' => 'Manage your customers effectively',
        'color' => 'success'
    ],
    'suppliers' => [
        'name' => 'Suppliers',
        'icon' 'mdi-truck',
        'description' => 'Supplier management guides',
        'color' => 'info'
    ],
    'sales' => [
        'name' => 'Sales & Invoicing',
        'icon' => 'mdi-cart',
        'description' => 'Create invoices and manage sales',
        'color' => 'warning'
    ],
    'purchases' => [
        'name' => 'Purchases',
        'icon' => 'mdi-cart-arrow-down',
        'description' => 'Purchase orders and procurement',
        'color' => 'danger'
    ],
    'stock' => [
        'name' => 'Stock Management',
        'icon' => 'mdi-package-variant',
        'description' => 'Inventory and stock control',
        'color' => 'secondary'
    ],
    'expenses' => [
        'name' => 'Expenses',
        'icon' => 'mdi-wallet',
        'description' => 'Track and manage expenses',
        'color' => 'dark'
    ],
    'accounts' => [
        'name' => 'Accounts',
        'icon' => 'mdi-bank',
        'description' => 'Financial reports and accounting',
        'color' => 'primary'
    ],
    'reports' => [
        'name' => 'Reports',
        'icon' => 'mdi-chart-bar',
        'description' => 'Generate and analyze reports',
        'color' => 'success'
    ],
    'settings' => [
        'name' => 'Settings',
        'icon' => 'mdi-cog',
        'description' => 'System configuration',
        'color' => 'info'
    ]
];

// FAQs
$faqs = [
    'general' => [
        [
            'question' => 'How do I reset my password?',
            'answer' => 'Go to the login page and click on "Forgot Password". You will receive an email with instructions to reset your password.'
        ],
        [
            'question' => 'How do I change my profile information?',
            'answer' => 'Click on your profile picture in the top-right corner, select "Profile", and update your information.'
        ],
        [
            'question' => 'What should I do if I encounter an error?',
            'answer' => 'Take a screenshot of the error and contact support at support@yourcompany.com or call +91 1234567890.'
        ]
    ],
    'customers' => [
        [
            'question' => 'How do I add a new customer?',
            'answer' => 'Go to Customers → Add Customer and fill in the required details. Customer code will be auto-generated.'
        ],
        [
            'question' => 'How do I track customer outstanding?',
            'answer' => 'Navigate to Customers → Outstanding to view all pending payments and aging analysis.'
        ],
        [
            'question' => 'Can I import customers from Excel?',
            'answer' => 'Yes, go to Customers → Import and upload your Excel file. Download the sample template first.'
        ]
    ],
    'suppliers' => [
        [
            'question' => 'How do I add a new supplier?',
            'answer' => 'Go to Suppliers → Add Supplier and fill in the supplier details including GST number if applicable.'
        ],
        [
            'question' => 'How do I manage supplier payments?',
            'answer' => 'Use Suppliers → Outstanding to view and manage payments to suppliers.'
        ]
    ],
    'sales' => [
        [
            'question' => 'How do I create an invoice?',
            'answer' => 'Go to Sales → New Invoice, select customer, add products, and generate the invoice.'
        ],
        [
            'question' => 'How do I handle partial payments?',
            'answer' => 'When recording a payment, enter the amount received. The system will automatically update the outstanding balance.'
        ],
        [
            'question' => 'Can I customize my invoice template?',
            'answer' => 'Yes, go to Sales → Invoice Settings to customize your invoice layout and company details.'
        ]
    ],
    'purchases' => [
        [
            'question' => 'How do I create a purchase order?',
            'answer' => 'Go to Purchases → New PO, select supplier, add products, and create the purchase order.'
        ],
        [
            'question' => 'How do I track GST input credit?',
            'answer' => 'Use Purchases → GST Input Credit to track and claim input tax credit on purchases.'
        ]
    ],
    'stock' => [
        [
            'question' => 'How do I check current stock levels?',
            'answer' => 'Go to Stock Management → Current Stock to view all products and their quantities.'
        ],
        [
            'question' => 'What is reorder level and how to set it?',
            'answer' => 'Reorder level is the minimum stock quantity. When stock falls below this, you\'ll get an alert. Set it in Products → Edit Product.'
        ],
        [
            'question' => 'How do I track stock movements?',
            'answer' => 'Use Stock Management → Stock Transactions to view all stock in/out movements.'
        ]
    ],
    'expenses' => [
        [
            'question' => 'How do I record an expense?',
            'answer' => 'Go to Expenses → Add Expense, select category, enter amount, and save.'
        ],
        [
            'question' => 'Can I attach receipts to expenses?',
            'answer' => 'Yes, you can upload receipt images when adding or editing an expense.'
        ]
    ],
    'reports' => [
        [
            'question' => 'How do I generate a sales report?',
            'answer' => 'Go to Reports → Sales Report, select date range, and generate the report.'
        ],
        [
            'question' => 'How do I export reports to Excel?',
            'answer' => 'Most reports have an "Export CSV" button. Click it to download the data in CSV format.'
        ],
        [
            'question' => 'What is the difference between summary and detailed reports?',
            'answer' => 'Summary reports show aggregated data, while detailed reports show individual transactions.'
        ]
    ]
];

// Role-based help shortcuts
$role_shortcuts = [
    'admin' => [
        ['title' => 'User Management', 'link' => 'masters/users.php', 'icon' => 'mdi-account-multiple'],
        ['title' => 'System Settings', 'link' => 'system-settings.php', 'icon' => 'mdi-cog'],
        ['title' => 'Audit Logs', 'link' => 'audit-logs.php', 'icon' => 'mdi-history'],
        ['title' => 'Backup & Restore', 'link' => 'backup.php', 'icon' => 'mdi-backup-restore']
    ],
    'sales' => [
        ['title' => 'New Invoice', 'link' => 'create-invoice.php', 'icon' => 'mdi-file-plus'],
        ['title' => 'Add Customer', 'link' => 'add-customer.php', 'icon' => 'mdi-account-plus'],
        ['title' => 'Sales Report', 'link' => 'sales-report.php', 'icon' => 'mdi-chart-line'],
        ['title' => 'Customer Outstanding', 'link' => 'customer-outstanding.php', 'icon' => 'mdi-cash-clock']
    ],
    'manager' => [
        ['title' => 'All Reports', 'link' => '#reports', 'icon' => 'mdi-chart-bar'],
        ['title' => 'Stock Report', 'link' => 'stock-report.php', 'icon' => 'mdi-package-variant'],
        ['title' => 'Profit & Loss', 'link' => 'profit-loss.php', 'icon' => 'mdi-crown'],
        ['title' => 'Expense Report', 'link' => 'expense-report.php', 'icon' => 'mdi-wallet']
    ],
    'auditor' => [
        ['title' => 'Audit Logs', 'link' => 'audit-logs.php', 'icon' => 'mdi-history'],
        ['title' => 'Activity Logs', 'link' => 'activity-logs.php', 'icon' => 'mdi-clock'],
        ['title' => 'GST Reports', 'link' => 'gst-report.php', 'icon' => 'mdi-receipt'],
        ['title' => 'Financial Reports', 'link' => 'profit-loss.php', 'icon' => 'mdi-finance']
    ]
];

// Get shortcuts for current role
$current_role_shortcuts = $role_shortcuts[$user_role] ?? $role_shortcuts['sales'];

// Video tutorials
$video_tutorials = [
    [
        'title' => 'Getting Started with the System',
        'duration' => '5:30',
        'thumbnail' => 'assets/images/tutorials/getting-started.jpg',
        'url' => 'https://www.youtube.com/watch?v=example1'
    ],
    [
        'title' => 'How to Create an Invoice',
        'duration' => '3:45',
        'thumbnail' => 'assets/images/tutorials/invoice.jpg',
        'url' => 'https://www.youtube.com/watch?v=example2'
    ],
    [
        'title' => 'Managing Customers',
        'duration' => '4:15',
        'thumbnail' => 'assets/images/tutorials/customers.jpg',
        'url' => 'https://www.youtube.com/watch?v=example3'
    ],
    [
        'title' => 'Stock Management Guide',
        'duration' => '6:20',
        'thumbnail' => 'assets/images/tutorials/stock.jpg',
        'url' => 'https://www.youtube.com/watch?v=example4'
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
                            <h4 class="mb-0 font-size-18">Help & Support Center</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Help & Support</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Welcome Banner -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-lg-8">
                                        <h4 class="mb-3">Welcome to Help Center, <?= htmlspecialchars($user_name) ?>!</h4>
                                        <p class="mb-4">Find answers to your questions, browse documentation, or get in touch with our support team.</p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="#faq" class="btn btn-primary"><i class="mdi mdi-frequently-asked-questions me-2"></i>Browse FAQs</a>
                                            <a href="#contact" class="btn btn-outline-primary"><i class="mdi mdi-email me-2"></i>Contact Support</a>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 text-center">
                                        <img src="assets/images/help-center.svg" alt="Help Center" class="img-fluid" style="max-height: 150px;">
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
                                            <input type="text" class="form-control form-control-lg" id="helpSearch" placeholder="Search for help articles, FAQs, or documentation...">
                                            <button class="btn btn-primary" type="button" id="searchBtn">Search</button>
                                        </div>
                                        <div id="searchResults" class="mt-3" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Shortcuts for Your Role -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="mdi mdi-speedometer me-2 text-primary"></i>
                                    Quick Shortcuts for <?= ucfirst($user_role) ?>
                                </h5>
                                <div class="row">
                                    <?php foreach ($current_role_shortcuts as $shortcut): ?>
                                    <div class="col-md-3 col-6">
                                        <a href="<?= $shortcut['link'] ?>" class="text-decoration-none">
                                            <div class="card bg-light border-0 mb-3">
                                                <div class="card-body text-center">
                                                    <i class="mdi <?= $shortcut['icon'] ?> text-primary" style="font-size: 32px;"></i>
                                                    <h6 class="mt-2 mb-0"><?= $shortcut['title'] ?></h6>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help Categories -->
                <div class="row">
                    <?php foreach ($help_categories as $key => $category): ?>
                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <div class="card text-center h-100">
                            <div class="card-body">
                                <div class="avatar-sm mx-auto mb-3">
                                    <span class="avatar-title bg-soft-<?= $category['color'] ?> text-<?= $category['color'] ?> rounded-circle">
                                        <i class="mdi <?= $category['icon'] ?> font-size-24"></i>
                                    </span>
                                </div>
                                <h5 class="font-size-16"><?= $category['name'] ?></h5>
                                <p class="text-muted font-size-13"><?= $category['description'] ?></p>
                                <a href="#category-<?= $key ?>" class="stretched-link" data-bs-toggle="collapse"></a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Category Details (Collapsible) -->
                <?php foreach ($help_categories as $key => $category): ?>
                <div class="collapse mt-3" id="category-<?= $key ?>">
                    <div class="card">
                        <div class="card-header bg-soft-<?= $category['color'] ?>">
                            <h5 class="mb-0 text-<?= $category['color'] ?>">
                                <i class="mdi <?= $category['icon'] ?> me-2"></i>
                                <?= $category['name'] ?> - Detailed Guide
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6>Overview</h6>
                                    <p>Learn how to effectively manage <?= strtolower($category['name']) ?> in your business.</p>
                                    
                                    <h6 class="mt-4">Key Features</h6>
                                    <ul>
                                        <li>Easy to use interface</li>
                                        <li>Real-time updates</li>
                                        <li>Comprehensive reporting</li>
                                        <li>Data export capabilities</li>
                                    </ul>
                                    
                                    <h6 class="mt-4">Common Tasks</h6>
                                    <ul>
                                        <li><a href="#">How to add new <?= strtolower($category['name']) ?></a></li>
                                        <li><a href="#">Managing <?= strtolower($category['name']) ?> records</a></li>
                                        <li><a href="#">Generating <?= strtolower($category['name']) ?> reports</a></li>
                                    </ul>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6>Quick Actions</h6>
                                            <div class="d-grid gap-2">
                                                <a href="#" class="btn btn-sm btn-outline-primary">View Documentation</a>
                                                <a href="#" class="btn btn-sm btn-outline-success">Watch Tutorial</a>
                                                <a href="#" class="btn btn-sm btn-outline-info">Get Support</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Frequently Asked Questions -->
                <div class="row mt-4" id="faq">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-frequently-asked-questions me-2 text-primary"></i>
                                    Frequently Asked Questions
                                </h4>
                                
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="accordion" id="faqAccordion1">
                                            <?php 
                                            $faqCount = 0;
                                            foreach (array_slice($faqs['general'], 0, 3) as $faq): 
                                            ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="faqHeading<?= $faqCount ?>">
                                                    <button class="accordion-button <?= $faqCount > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse<?= $faqCount ?>" aria-expanded="<?= $faqCount == 0 ? 'true' : 'false' ?>" aria-controls="faqCollapse<?= $faqCount ?>">
                                                        <?= $faq['question'] ?>
                                                    </button>
                                                </h2>
                                                <div id="faqCollapse<?= $faqCount ?>" class="accordion-collapse collapse <?= $faqCount == 0 ? 'show' : '' ?>" aria-labelledby="faqHeading<?= $faqCount ?>" data-bs-parent="#faqAccordion1">
                                                    <div class="accordion-body">
                                                        <?= $faq['answer'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php 
                                            $faqCount++;
                                            endforeach; 
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="col-lg-6">
                                        <div class="accordion" id="faqAccordion2">
                                            <?php 
                                            foreach (array_slice($faqs['sales'], 0, 3) as $faq): 
                                            ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="faqHeading<?= $faqCount ?>">
                                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse<?= $faqCount ?>" aria-expanded="false" aria-controls="faqCollapse<?= $faqCount ?>">
                                                        <?= $faq['question'] ?>
                                                    </button>
                                                </h2>
                                                <div id="faqCollapse<?= $faqCount ?>" class="accordion-collapse collapse" aria-labelledby="faqHeading<?= $faqCount ?>" data-bs-parent="#faqAccordion2">
                                                    <div class="accordion-body">
                                                        <?= $faq['answer'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php 
                                            $faqCount++;
                                            endforeach; 
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <a href="#" class="btn btn-outline-primary" id="viewAllFaqs">
                                        <i class="mdi mdi-eye me-2"></i>View All FAQs
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Video Tutorials -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-play-circle me-2 text-primary"></i>
                                    Video Tutorials
                                </h4>
                                
                                <div class="row">
                                    <?php foreach ($video_tutorials as $video): ?>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border">
                                            <img src="<?= $video['thumbnail'] ?>" class="card-img-top" alt="<?= $video['title'] ?>" onerror="this.src='assets/images/tutorials/default.jpg'">
                                            <div class="card-body">
                                                <h6 class="card-title"><?= $video['title'] ?></h6>
                                                <p class="card-text"><small class="text-muted"><i class="mdi mdi-clock-outline me-1"></i><?= $video['duration'] ?></small></p>
                                                <a href="<?= $video['url'] ?>" class="btn btn-sm btn-primary" target="_blank">
                                                    <i class="mdi mdi-play me-1"></i>Watch Now
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documentation Links -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <i class="mdi mdi-file-document me-2 text-primary"></i>
                                    Documentation
                                </h4>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="list-group">
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-file-pdf text-danger me-2"></i>
                                                User Manual PDF
                                                <span class="badge bg-success float-end">New</span>
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-file-word text-primary me-2"></i>
                                                Quick Start Guide
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-file-excel text-success me-2"></i>
                                                Import Templates
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="list-group">
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-video text-danger me-2"></i>
                                                Video Tutorials
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-frequently-asked-questions text-warning me-2"></i>
                                                FAQ Database
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-forum text-info me-2"></i>
                                                Community Forum
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="list-group">
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-api text-primary me-2"></i>
                                                API Documentation
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-shield text-success me-2"></i>
                                                Security Guide
                                            </a>
                                            <a href="#" class="list-group-item list-group-item-action">
                                                <i class="mdi mdi-update text-info me-2"></i>
                                                Release Notes
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Support -->
                <div class="row mt-4" id="contact">
                    <div class="col-12">
                        <div class="card bg-soft-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h4 class="mb-3">Need Additional Help?</h4>
                                        <p class="mb-4">Our support team is available 24/7 to assist you with any questions or issues.</p>
                                        
                                        <div class="d-flex mb-3">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-email text-primary" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6>Email Support</h6>
                                                <p class="mb-0">support@yourcompany.com</p>
                                                <small class="text-muted">Response within 24 hours</small>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex mb-3">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-phone text-primary" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6>Phone Support</h6>
                                                <p class="mb-0">+91 1234567890</p>
                                                <small class="text-muted">Mon-Fri, 9:00 AM - 6:00 PM</small>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="mdi mdi-chat text-primary" style="font-size: 24px;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6>Live Chat</h6>
                                                <p class="mb-0">Click the chat button in the bottom-right corner</p>
                                                <small class="text-muted">Available during business hours</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title mb-3">Send us a message</h5>
                                                <form id="contactForm">
                                                    <div class="mb-3">
                                                        <label for="name" class="form-label">Name</label>
                                                        <input type="text" class="form-control" id="name" value="<?= htmlspecialchars($user_name) ?>" readonly>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="email" class="form-label">Email</label>
                                                        <input type="email" class="form-control" id="email" value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>" readonly>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="subject" class="form-label">Subject</label>
                                                        <input type="text" class="form-control" id="subject" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="message" class="form-label">Message</label>
                                                        <textarea class="form-control" id="message" rows="4" required></textarea>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="mdi mdi-send me-2"></i>Send Message
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">
                                    <i class="mdi mdi-heart-pulse me-2 text-primary"></i>
                                    System Status
                                </h4>
                                
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0 me-3">
                                                <span class="badge bg-success p-2"><i class="mdi mdi-check"></i></span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0">API Service</h6>
                                                <small class="text-success">Operational</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0 me-3">
                                                <span class="badge bg-success p-2"><i class="mdi mdi-check"></i></span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0">Database</h6>
                                                <small class="text-success">Operational</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0 me-3">
                                                <span class="badge bg-success p-2"><i class="mdi mdi-check"></i></span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0">Email Service</h6>
                                                <small class="text-success">Operational</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0 me-3">
                                                <span class="badge bg-success p-2"><i class="mdi mdi-check"></i></span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0">Storage</h6>
                                                <small class="text-success">85% Available</small>
                                            </div>
                                        </div>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

    document.getElementById('helpSearch')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });

    function performSearch() {
        const searchTerm = document.getElementById('helpSearch').value.toLowerCase();
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
        
        // Simulate search results (in real implementation, this would be an AJAX call)
        const results = [
            { title: 'How to create an invoice', category: 'Sales', url: '#' },
            { title: 'Adding a new customer', category: 'Customers', url: '#' },
            { title: 'Managing stock levels', category: 'Stock', url: '#' },
            { title: 'Generating reports', category: 'Reports', url: '#' }
        ].filter(item => item.title.toLowerCase().includes(searchTerm));
        
        if (results.length > 0) {
            let html = '<div class="list-group">';
            results.forEach(result => {
                html += `<a href="${result.url}" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">${result.title}</h6>
                        <small class="text-primary">${result.category}</small>
                    </div>
                </a>`;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        } else {
            resultsDiv.innerHTML = '<div class="alert alert-info">No results found for "' + searchTerm + '"</div>';
            resultsDiv.style.display = 'block';
        }
    }

    // Contact form submission
    document.getElementById('contactForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Message Sent!',
            text: 'Thank you for contacting us. Our support team will respond within 24 hours.',
            icon: 'success',
            confirmButtonColor: '#556ee6'
        }).then(() => {
            this.reset();
        });
    });

    // View all FAQs
    document.getElementById('viewAllFaqs')?.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Show all FAQs modal
        let faqHtml = '<div class="accordion" id="allFaqsAccordion">';
        let faqIndex = 0;
        
        <?php foreach ($faqs as $category => $categoryFaqs): ?>
        faqHtml += `<h6 class="mt-3 mb-2 text-primary"><?= ucfirst($category) ?></h6>`;
        <?php foreach ($categoryFaqs as $faq): ?>
        faqHtml += `
            <div class="accordion-item">
                <h2 class="accordion-header" id="faqAllHeading${faqIndex}">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqAllCollapse${faqIndex}">
                        <?= addslashes($faq['question']) ?>
                    </button>
                </h2>
                <div id="faqAllCollapse${faqIndex}" class="accordion-collapse collapse" data-bs-parent="#allFaqsAccordion">
                    <div class="accordion-body">
                        <?= addslashes($faq['answer']) ?>
                    </div>
                </div>
            </div>
        `;
        faqIndex++;
        <?php endforeach; ?>
        <?php endforeach; ?>
        
        faqHtml += '</div>';
        
        Swal.fire({
            title: 'All FAQs',
            html: faqHtml,
            width: '800px',
            showConfirmButton: true,
            confirmButtonText: 'Close',
            confirmButtonColor: '#556ee6'
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
@media print {
    .vertical-menu, .topbar, .footer, .btn, .modal, 
    .page-title-right, .card-title .btn, .action-buttons,
    form, #contactForm {
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

/* Category card hover effect */
.card.h-100 {
    transition: transform 0.3s, box-shadow 0.3s;
    cursor: pointer;
}

.card.h-100:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

/* Video tutorial cards */
.card.border {
    transition: all 0.3s;
}

.card.border:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

/* Search results */
#searchResults {
    max-height: 300px;
    overflow-y: auto;
    border-radius: 8px;
}

/* Accordion customization */
.accordion-button:not(.collapsed) {
    background-color: rgba(85, 110, 230, 0.1);
    color: #556ee6;
}

/* Status badges */
.badge.p-2 {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card.h-100 {
        margin-bottom: 15px;
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

/* Custom scrollbar for search results */
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

#searchResults::-webkit-scrollbar-thumb:hover {
    background: #3b5bdb;
}
</style>

</body>
</html>