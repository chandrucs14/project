<div id="sidebar-menu">
    <!-- Left Menu Start -->
    <ul class="metismenu list-unstyled" id="side-menu">
        <li class="menu-title">Main</li>

        <!-- Dashboard -->
        <li>
            <a href="index.php" class="waves-effect">
                <i class="dripicons-device-desktop"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Masters Module -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
               <i class="dripicons-wallet"></i>
                <span> Masters </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="categories.php">Categories</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="vehicles.php">Vehicles</a></li>
                
                <!-- Admin only masters -->
                <li class="admin-only"><a href="users.php">Users</a></li>
                <li class="admin-only"><a href="activity-types.php">Activity Types</a></li>
            </ul>
        </li>

        <!-- Customers Module -->
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

        <!-- Suppliers Module -->
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

        <!-- Sales Module -->
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

        <!-- Purchases Module -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-shopping-bag"></i>
                <span> Purchases </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="purchase-orders.php">Purchase Orders</a></li>
                <li><a href="create-purchase.php">New Purchase</a></li>
                <li><a href="gst-input-credit.php">GST Input Credit</a></li>
            </ul>
        </li>

        <!-- Stock Management Module -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-stack"></i>
                <span> Stock Management </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="daybook.php">Daybook</a></li>
                 <li><a href="daybook-outstanding.php">Daybook Outstanding</a></li>
                 <li><a href="daybook-invoices.php">Daybook invoices</a></li>
                <li><a href="daybook-report.php">Daybook Report</a></li>
                <li><a href="current-stock.php">Current Stock</a></li>
                <li><a href="daywise-stock.php">Day-wise Stock</a></li>
                <li><a href="stock-transactions.php">Stock Transactions</a></li>
                <li><a href="stock-report.php">Stock Report</a></li>
            </ul>
        </li>

        <!-- Expenses Module -->
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

        <!-- Accounts Module -->
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-currency"></i>
                <span> Accounts </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                
                <li><a href="outstanding-report.php">Outstanding Report</a></li>
            </ul>
        </li>

        <!-- Reports Module -->
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

        <!-- Settings Module (Admin only) -->
        <li class="admin-only">
            <a href="javascript: void(0);" class="has-arrow waves-effect">
                <i class="dripicons-gear"></i>
                <span> Settings </span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="gst-settings.php">GST Settings</a></li>
            </ul>
        </li>

        <!-- Logout Option -->
        <li class="menu-title">Account</li>
        <li>
            <a href="logout.php" class="waves-effect" id="logoutBtn">
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
    const userRole = '<?php echo $_SESSION['user_role'] ?? "sales"; ?>'; // Default to sales if not set
    
    // Hide admin-only menu items for non-admin users
    if (userRole !== 'admin') {
        const adminItems = document.querySelectorAll('.admin-only');
        adminItems.forEach(item => {
            item.style.display = 'none';
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
/* Admin-only menu items styling */
.admin-only {
    border-left: 3px solid #ff5b5b;
    background-color: rgba(255, 91, 91, 0.05);
}

.admin-only > a {
    color: #ff5b5b !important;
}

/* Main menu item styling */
.metismenu li > a {
    font-weight: 500;
    transition: all 0.3s;
}

.metismenu li > a i {
    font-size: 18px;
    vertical-align: middle;
    margin-right: 10px;
    width: 20px;
    text-align: center;
}

/* Hover effects */
.metismenu li a:hover {
    background-color: rgba(59, 175, 218, 0.1);
    transform: translateX(5px);
}

/* Active menu item */
.metismenu li.active > a {
    background-color: #3bafda;
    color: #fff !important;
    box-shadow: 0 2px 6px rgba(59, 175, 218, 0.3);
}

.metismenu li.active > a i {
    color: #fff !important;
}

/* New item styling */
.metismenu .fw-bold {
    font-weight: 600;
}

.metismenu .text-primary {
    color: #3bafda !important;
}

/* Logout button styling */
#logoutBtn {
    color: #f46a6a !important;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 10px;
}

#logoutBtn:hover {
    background-color: rgba(244, 106, 106, 0.1) !important;
    transform: none;
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

/* Sub-menu styling */
.sub-menu {
    padding-left: 10px;
}

.sub-menu li a {
    padding: 8px 20px 8px 45px !important;
    font-size: 13px;
}

.sub-menu li a i {
    display: none; /* Hide icons in submenu */
}

/* Arrow indicators */
.metismenu .has-arrow::after {
    right: 20px;
}

/* Animation for menu expansion */
.metismenu .collapse.in {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>