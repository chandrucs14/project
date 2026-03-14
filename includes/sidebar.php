<div id="sidebar-menu">
    <!-- Left Menu Start -->
    <ul class="metismenu list-unstyled" id="side-menu">
        <li class="menu-title">Main</li>

       
        <li>
            <a href="index.php" class="waves-effect">
                <i class="dripicons-device-desktop"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Masters Module - Full access to Admin, Limited to Sales -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-database"></i>
                <span> Masters </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="categories.php">Categories</a></li>
                <li><a href="products.php">Products</a></li>
              
                <li><a href="vehicles.php">Vehicles</a></li>
                  <li><a href="gst-settings.php">GST manage</a></li>
                <!-- Admin only masters -->
                <li class="admin-only"><a href="masters/users.php">Users</a></li>
                <li class="admin-only"><a href="masters/activity-types.php">Activity Types</a></li>
            </ul>
        </li>

        <!-- Customer Management - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-user"></i>
                <span> Customers </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="add-customer.php">Add Customer</a></li>
                <li><a href="manage-customers.php">Manage Customers</a></li>
                <li><a href="customer-outstanding.php">Outstanding</a></li>
                <li><a href="referrals.php">Referrals</a></li>
            </ul>
        </li>

        <!-- Supplier Management - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-box"></i>
                <span> Suppliers </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="add-supplier.php">Add Supplier</a></li>
                <li><a href="manage-suppliers.php">Manage Suppliers</a></li>
                <li><a href="supplier-outstanding.php">Outstanding</a></li>
            </ul>
        </li>

        <!-- Sales Module - Primary for Sales staff -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-cart"></i>
                <span> Sales </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="orders.php">Orders</a></li>
                <li><a href="invoices.php">Invoices</a></li>
                <li><a href="create-invoice.php" class="fw-bold text-primary">+ New Invoice</a></li>
                <li><a href="invoice-settings.php">Invoice Settings</a></li>
                <li><a href="sales-analytics.php">Sales Analytics</a></li>
            </ul>
        </li>

        <!-- Purchase Module - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-shopping-bag"></i>
                <span> Purchases </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="purchase-orders.php">Purchase Orders</a></li>
                <li><a href="create-po.php" class="fw-bold text-primary">+ New PO</a></li>
                <li><a href="gst-input-credit.php">GST Input Credit</a></li>
            </ul>
        </li>

        <!-- Stock Management - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-stack"></i>
                <span> Stock Management </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="current-stock.php">Current Stock</a></li>
                <li><a href="daywise-stock.php">Day-wise Stock</a></li>
                <li><a href="stock-transactions.php">Stock Transactions</a></li>
                <li><a href="stock-report.php">Stock Report</a></li>
            </ul>
        </li>

        <!-- Expenses - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-wallet"></i>
                <span> Expenses </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="add-expense.php">Add Expense</a></li>
                <li><a href="manage-expenses.php">Manage Expenses</a></li>
                <li><a href="expense-report.php">Expense Report</a></li>
            </ul>
        </li>

        <!-- Accounts - Primarily Admin -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-currency"></i>
                <span> Accounts </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="daywise-amounts.php">Day-wise Amounts</a></li>
                
                <li><a href="outstanding-report.php">Outstanding Report</a></li>
                <!-- Admin only -->
                <li class="admin-only"><a href="audit-logs.php">Audit Logs</a></li>
            </ul>
        </li>

        <!-- Reports - Both roles -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-graph-line"></i>
                <span> Reports </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="sales-report.php">Sales Report</a></li>
                <li><a href="purchase-report.php">Purchase Report</a></li>
                <li><a href="gst-report.php">GST Report</a></li>
                <li><a href="customer-report.php">Customer Report</a></li>
                <li><a href="supplier-report.php">Supplier Report</a></li>
                <li><a href="profit-loss.php">Profit & Loss</a></li>
            </ul>
        </li>

        <!-- Activity & Logs - Admin only -->
        <li class="admin-only">
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-clock"></i>
                <span> Activity Logs </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="activity-logs.php">User Activity</a></li>
                <li><a href="audit-logs.php">Audit Trail</a></li>
                <li><a href="login-history.php">Login History</a></li>
            </ul>
        </li>

        <!-- Settings - Admin only -->
        <li class="admin-only">
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-gear"></i>
                <span> Settings </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="company-settings.php">Company Settings</a></li>
                <li><a href="invoice-settings.php">Invoice Settings</a></li>
                <li><a href="gst-management.php">GST Settings</a></li>
                <li><a href="backup.php">Backup & Restore</a></li>
                <li><a href="/system-settings.php">System Settings</a></li>
            </ul>
        </li>

    
       

        <!-- Logout Option -->
        <li class="menu-title">Account</li>
        <li>
            <a href="login.php" class="waves-effect" id="logoutBtn">
                <i class="dripicons-exit"></i>
                <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<!-- Add this JavaScript at the bottom of your page to handle role-based menu visibility and logout confirmation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get current user role from session/global variable
    // This should be set in your PHP session and passed to JavaScript
    const userRole = '<?php echo $_SESSION['user_role'] ?? "sales"; ?>'; // Default to sales if not set
    
    // Hide admin-only menu items for non-admin users
    if (userRole !== 'admin') {
        const adminItems = document.querySelectorAll('.admin-only');
        adminItems.forEach(item => {
            item.style.display = 'none';
        });
    }
    
    // For sales staff, maybe highlight frequently used items
    if (userRole === 'sales') {
        // Add a badge or highlight to sales-related menus
        const salesMenus = document.querySelectorAll('a[href*="sales/"], a[href*="customers/"]');
        salesMenus.forEach(menu => {
            // You could add a small indicator or just let them be normally visible
        });
    }

    // Add logout confirmation
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Show SweetAlert2 confirmation if available, otherwise use confirm
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Are you sure?',
                    text: 'You will be logged out of the system',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#556ee6',
                    cancelButtonColor: '#f46a6a',
                    confirmButtonText: 'Yes, logout',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'logout.php';
                    }
                });
            } else {
                // Fallback to confirm dialog if SweetAlert not available
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = 'logout.php';
                }
            }
        });
    }
});
</script>

<!-- Optional: Add some CSS to style the menu -->
<style>
.admin-only {
    border-left: 3px solid #ff5b5b;
    background-color: rgba(255, 91, 91, 0.05);
}

.admin-only > a {
    color: #ff5b5b !important;
}

.metismenu .fw-bold {
    font-weight: 600;
}

.metismenu .text-primary {
    color: #3bafda !important;
}

/* Hover effects */
.metismenu li a:hover {
    background-color: rgba(59, 175, 218, 0.1);
}

/* Active menu item */
.metismenu li.active > a {
    background-color: #3bafda;
    color: #fff !important;
}

/* Logout button styling */
#logoutBtn {
    color: #f46a6a !important;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 10px;
}

#logoutBtn:hover {
    background-color: rgba(244, 106, 106, 0.1) !important;
}

#logoutBtn i {
    color: #f46a6a;
}

/* Menu title styling */
.menu-title {
    padding: 12px 20px !important;
    letter-spacing: .05em;
    pointer-events: none;
    cursor: default;
    font-size: 11px;
    text-transform: uppercase;
    color: #878a99 !important;
    font-weight: 600;
}

/* Sub-menu indentation */
.sub-menu {
    padding-left: 10px;
}

.sub-menu li a {
    padding: 8px 20px 8px 40px !important;
}
</style>