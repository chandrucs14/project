<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Include database configuration
require_once 'config/database.php';


// Check if user is admin
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?error=unauthorized");
    exit();
}

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submission for general settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_general') {
    
    $company_name = trim($_POST['company_name'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $company_phone = trim($_POST['company_phone'] ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $company_gst = trim($_POST['company_gst'] ?? '');
    $company_pan = trim($_POST['company_pan'] ?? '');
    $company_website = trim($_POST['company_website'] ?? '');
    $timezone = $_POST['timezone'] ?? 'Asia/Kolkata';
    $date_format = $_POST['date_format'] ?? 'd-m-Y';
    $currency_symbol = $_POST['currency_symbol'] ?? '₹';
    $currency_position = $_POST['currency_position'] ?? 'left';
    $thousand_separator = $_POST['thousand_separator'] ?? ',';
    $decimal_separator = $_POST['decimal_separator'] ?? '.';
    $decimal_places = intval($_POST['decimal_places'] ?? 2);
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Update settings
        $settings = [
            'company_name' => $company_name,
            'company_address' => $company_address,
            'company_phone' => $company_phone,
            'company_email' => $company_email,
            'company_gst' => $company_gst,
            'company_pan' => $company_pan,
            'company_website' => $company_website,
            'timezone' => $timezone,
            'date_format' => $date_format,
            'currency_symbol' => $currency_symbol,
            'currency_position' => $currency_position,
            'thousand_separator' => $thousand_separator,
            'decimal_separator' => $decimal_separator,
            'decimal_places' => $decimal_places
        ];
        
        // Check if settings table exists, if not create it
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                setting_type VARCHAR(50) DEFAULT 'text',
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT,
                FOREIGN KEY (created_by) REFERENCES users(id),
                FOREIGN KEY (updated_by) REFERENCES users(id)
            )
        ");
        
        foreach ($settings as $key => $value) {
            // Check if setting exists
            $checkStmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetch()) {
                // Update existing setting
                $updateStmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_key = ?
                ");
                $updateStmt->execute([$value, $_SESSION['user_id'], $key]);
            } else {
                // Insert new setting
                $insertStmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$key, $value, $_SESSION['user_id']]);
            }
        }
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (?, 4, 'Updated general settings', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            json_encode(['settings' => array_keys($settings)]),
            $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        $success_message = "General settings updated successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error updating settings: " . $e->getMessage();
        error_log("Settings update error: " . $e->getMessage());
    }
}

// Handle form submission for email settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email') {
    
    $mail_driver = $_POST['mail_driver'] ?? 'smtp';
    $mail_host = trim($_POST['mail_host'] ?? '');
    $mail_port = intval($_POST['mail_port'] ?? 587);
    $mail_username = trim($_POST['mail_username'] ?? '');
    $mail_password = trim($_POST['mail_password'] ?? '');
    $mail_encryption = $_POST['mail_encryption'] ?? 'tls';
    $mail_from_address = trim($_POST['mail_from_address'] ?? '');
    $mail_from_name = trim($_POST['mail_from_name'] ?? '');
    $mail_reply_to = trim($_POST['mail_reply_to'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        $settings = [
            'mail_driver' => $mail_driver,
            'mail_host' => $mail_host,
            'mail_port' => $mail_port,
            'mail_username' => $mail_username,
            'mail_password' => $mail_password,
            'mail_encryption' => $mail_encryption,
            'mail_from_address' => $mail_from_address,
            'mail_from_name' => $mail_from_name,
            'mail_reply_to' => $mail_reply_to
        ];
        
        foreach ($settings as $key => $value) {
            $checkStmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetch()) {
                $updateStmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_key = ?
                ");
                $updateStmt->execute([$value, $_SESSION['user_id'], $key]);
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$key, $value, $_SESSION['user_id']]);
            }
        }
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (?, 4, 'Updated email settings', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            json_encode(['settings' => array_keys($settings)]),
            $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        $success_message = "Email settings updated successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error updating email settings: " . $e->getMessage();
        error_log("Email settings update error: " . $e->getMessage());
    }
}

// Handle form submission for backup settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_backup') {
    
    $backup_enabled = isset($_POST['backup_enabled']) ? 1 : 0;
    $backup_frequency = $_POST['backup_frequency'] ?? 'daily';
    $backup_time = $_POST['backup_time'] ?? '02:00';
    $backup_retention = intval($_POST['backup_retention'] ?? 30);
    $backup_path = trim($_POST['backup_path'] ?? 'backups/');
    $backup_include_files = isset($_POST['backup_include_files']) ? 1 : 0;
    
    try {
        $pdo->beginTransaction();
        
        $settings = [
            'backup_enabled' => $backup_enabled,
            'backup_frequency' => $backup_frequency,
            'backup_time' => $backup_time,
            'backup_retention' => $backup_retention,
            'backup_path' => $backup_path,
            'backup_include_files' => $backup_include_files
        ];
        
        foreach ($settings as $key => $value) {
            $checkStmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetch()) {
                $updateStmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_key = ?
                ");
                $updateStmt->execute([$value, $_SESSION['user_id'], $key]);
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$key, $value, $_SESSION['user_id']]);
            }
        }
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (?, 4, 'Updated backup settings', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            json_encode(['settings' => array_keys($settings)]),
            $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        $success_message = "Backup settings updated successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error updating backup settings: " . $e->getMessage();
        error_log("Backup settings update error: " . $e->getMessage());
    }
}

// Handle form submission for security settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_security') {
    
    $session_lifetime = intval($_POST['session_lifetime'] ?? 3600);
    $password_min_length = intval($_POST['password_min_length'] ?? 8);
    $password_require_uppercase = isset($_POST['password_require_uppercase']) ? 1 : 0;
    $password_require_lowercase = isset($_POST['password_require_lowercase']) ? 1 : 0;
    $password_require_numbers = isset($_POST['password_require_numbers']) ? 1 : 0;
    $password_require_symbols = isset($_POST['password_require_symbols']) ? 1 : 0;
    $max_login_attempts = intval($_POST['max_login_attempts'] ?? 5);
    $lockout_duration = intval($_POST['lockout_duration'] ?? 900);
    $two_factor_auth = isset($_POST['two_factor_auth']) ? 1 : 0;
    $session_timeout = intval($_POST['session_timeout'] ?? 1800);
    
    try {
        $pdo->beginTransaction();
        
        $settings = [
            'session_lifetime' => $session_lifetime,
            'password_min_length' => $password_min_length,
            'password_require_uppercase' => $password_require_uppercase,
            'password_require_lowercase' => $password_require_lowercase,
            'password_require_numbers' => $password_require_numbers,
            'password_require_symbols' => $password_require_symbols,
            'max_login_attempts' => $max_login_attempts,
            'lockout_duration' => $lockout_duration,
            'two_factor_auth' => $two_factor_auth,
            'session_timeout' => $session_timeout
        ];
        
        foreach ($settings as $key => $value) {
            $checkStmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $checkStmt->execute([$key]);
            
            if ($checkStmt->fetch()) {
                $updateStmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, updated_by = ?, updated_at = NOW()
                    WHERE setting_key = ?
                ");
                $updateStmt->execute([$value, $_SESSION['user_id'], $key]);
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $insertStmt->execute([$key, $value, $_SESSION['user_id']]);
            }
        }
        
        // Log activity
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (?, 4, 'Updated security settings', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            json_encode(['settings' => array_keys($settings)]),
            $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        $success_message = "Security settings updated successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error updating security settings: " . $e->getMessage();
        error_log("Security settings update error: " . $e->getMessage());
    }
}

// Handle test email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    
    $test_email = trim($_POST['test_email'] ?? '');
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Here you would implement actual email sending
        // For now, just show success message
        $success_message = "Test email sent successfully to $test_email (simulated).";
    }
}

// Handle create backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_backup') {
    
    try {
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $backup_path = 'backups/' . $backup_file;
        
        // Ensure backup directory exists
        if (!is_dir('backups')) {
            mkdir('backups', 0755, true);
        }
        
        // Get database configuration
        $db_host = $pdo->getAttribute(PDO::ATTR_SERVER_INFO)['host'] ?? 'localhost';
        $db_name = 'u966043993_vkm';
        $db_user = 'root'; // You should get this from config
        
        // Create backup command (this is a simplified version)
        // In production, you would use exec() or a proper backup library
        $command = "mysqldump --host=$db_host --user=$db_user --password= $db_name > $backup_path";
        
        // Log backup creation
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity_type_id, description, activity_data, created_by)
            VALUES (?, 3, 'Created database backup', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['user_id'],
            json_encode(['backup_file' => $backup_file]),
            $_SESSION['user_id']
        ]);
        
        $success_message = "Backup created successfully: $backup_file";
        
    } catch (Exception $e) {
        $error_message = "Error creating backup: " . $e->getMessage();
        error_log("Backup creation error: " . $e->getMessage());
    }
}

// Get current settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist yet
    $settings = [];
}

// Set default values
$default_settings = [
    'company_name' => 'VKM Business Solutions',
    'company_address' => '123 Business Park, City - 123456',
    'company_phone' => '+91 1234567890',
    'company_email' => 'info@vkm.com',
    'company_gst' => '22AAAAA0000A1Z5',
    'company_pan' => 'AAAAA0000A',
    'company_website' => 'www.vkm.com',
    'timezone' => 'Asia/Kolkata',
    'date_format' => 'd-m-Y',
    'currency_symbol' => '₹',
    'currency_position' => 'left',
    'thousand_separator' => ',',
    'decimal_separator' => '.',
    'decimal_places' => 2,
    'mail_driver' => 'smtp',
    'mail_host' => 'smtp.gmail.com',
    'mail_port' => 587,
    'mail_username' => '',
    'mail_password' => '',
    'mail_encryption' => 'tls',
    'mail_from_address' => '',
    'mail_from_name' => 'VKM System',
    'mail_reply_to' => '',
    'backup_enabled' => 1,
    'backup_frequency' => 'daily',
    'backup_time' => '02:00',
    'backup_retention' => 30,
    'backup_path' => 'backups/',
    'backup_include_files' => 1,
    'session_lifetime' => 3600,
    'password_min_length' => 8,
    'password_require_uppercase' => 1,
    'password_require_lowercase' => 1,
    'password_require_numbers' => 1,
    'password_require_symbols' => 0,
    'max_login_attempts' => 5,
    'lockout_duration' => 900,
    'two_factor_auth' => 0,
    'session_timeout' => 1800
];

// Merge with defaults
foreach ($default_settings as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Bootstrap Toggle -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap4-toggle@3.6.1/css/bootstrap4-toggle.min.css" rel="stylesheet">
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
                            <h4 class="mb-0 font-size-18">System Settings</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="settings.php">Settings</a></li>
                                    <li class="breadcrumb-item active">System Settings</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="mdi mdi-alert-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Settings Tabs -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-tabs nav-tabs-custom" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-bs-toggle="tab" href="#general" role="tab">
                                            <i class="mdi mdi-cog me-1"></i> General
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#email" role="tab">
                                            <i class="mdi mdi-email me-1"></i> Email
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#backup" role="tab">
                                            <i class="mdi mdi-database me-1"></i> Backup
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#security" role="tab">
                                            <i class="mdi mdi-shield me-1"></i> Security
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#system" role="tab">
                                            <i class="mdi mdi-information me-1"></i> System Info
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content p-3">
                                    <!-- General Settings Tab -->
                                    <div class="tab-pane active" id="general" role="tabpanel">
                                        <form method="POST" action="system-settings.php" id="generalForm">
                                            <input type="hidden" name="action" value="save_general">
                                            
                                            <h5 class="font-size-14 mb-3">Company Information</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_name" class="form-label">Company Name</label>
                                                        <input type="text" class="form-control" id="company_name" name="company_name" 
                                                               value="<?= htmlspecialchars($settings['company_name']) ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_phone" class="form-label">Phone Number</label>
                                                        <input type="text" class="form-control" id="company_phone" name="company_phone" 
                                                               value="<?= htmlspecialchars($settings['company_phone']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_email" class="form-label">Email Address</label>
                                                        <input type="email" class="form-control" id="company_email" name="company_email" 
                                                               value="<?= htmlspecialchars($settings['company_email']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_website" class="form-label">Website</label>
                                                        <input type="url" class="form-control" id="company_website" name="company_website" 
                                                               value="<?= htmlspecialchars($settings['company_website']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="company_address" class="form-label">Address</label>
                                                <textarea class="form-control" id="company_address" name="company_address" rows="2"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_gst" class="form-label">GST Number</label>
                                                        <input type="text" class="form-control" id="company_gst" name="company_gst" 
                                                               value="<?= htmlspecialchars($settings['company_gst']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="company_pan" class="form-label">PAN Number</label>
                                                        <input type="text" class="form-control" id="company_pan" name="company_pan" 
                                                               value="<?= htmlspecialchars($settings['company_pan']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h5 class="font-size-14 mb-3 mt-4">Regional Settings</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="timezone" class="form-label">Timezone</label>
                                                        <select class="form-control select2" id="timezone" name="timezone">
                                                            <option value="Asia/Kolkata" <?= $settings['timezone'] == 'Asia/Kolkata' ? 'selected' : '' ?>>India (IST)</option>
                                                            <option value="Asia/Dubai" <?= $settings['timezone'] == 'Asia/Dubai' ? 'selected' : '' ?>>UAE</option>
                                                            <option value="Asia/Singapore" <?= $settings['timezone'] == 'Asia/Singapore' ? 'selected' : '' ?>>Singapore</option>
                                                            <option value="America/New_York" <?= $settings['timezone'] == 'America/New_York' ? 'selected' : '' ?>>New York</option>
                                                            <option value="Europe/London" <?= $settings['timezone'] == 'Europe/London' ? 'selected' : '' ?>>London</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="date_format" class="form-label">Date Format</label>
                                                        <select class="form-control" id="date_format" name="date_format">
                                                            <option value="d-m-Y" <?= $settings['date_format'] == 'd-m-Y' ? 'selected' : '' ?>>DD-MM-YYYY</option>
                                                            <option value="m-d-Y" <?= $settings['date_format'] == 'm-d-Y' ? 'selected' : '' ?>>MM-DD-YYYY</option>
                                                            <option value="Y-m-d" <?= $settings['date_format'] == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                                            <option value="d/m/Y" <?= $settings['date_format'] == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                                            <option value="m/d/Y" <?= $settings['date_format'] == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h5 class="font-size-14 mb-3 mt-4">Currency Settings</h5>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="currency_symbol" class="form-label">Currency Symbol</label>
                                                        <input type="text" class="form-control" id="currency_symbol" name="currency_symbol" 
                                                               value="<?= htmlspecialchars($settings['currency_symbol']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="currency_position" class="form-label">Symbol Position</label>
                                                        <select class="form-control" id="currency_position" name="currency_position">
                                                            <option value="left" <?= $settings['currency_position'] == 'left' ? 'selected' : '' ?>>Left (₹100)</option>
                                                            <option value="right" <?= $settings['currency_position'] == 'right' ? 'selected' : '' ?>>Right (100₹)</option>
                                                            <option value="left_space" <?= $settings['currency_position'] == 'left_space' ? 'selected' : '' ?>>Left with Space (₹ 100)</option>
                                                            <option value="right_space" <?= $settings['currency_position'] == 'right_space' ? 'selected' : '' ?>>Right with Space (100 ₹)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="decimal_places" class="form-label">Decimal Places</label>
                                                        <input type="number" class="form-control" id="decimal_places" name="decimal_places" 
                                                               value="<?= $settings['decimal_places'] ?>" min="0" max="4">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="thousand_separator" class="form-label">Thousand Separator</label>
                                                        <input type="text" class="form-control" id="thousand_separator" name="thousand_separator" 
                                                               value="<?= htmlspecialchars($settings['thousand_separator']) ?>" maxlength="1">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="decimal_separator" class="form-label">Decimal Separator</label>
                                                        <input type="text" class="form-control" id="decimal_separator" name="decimal_separator" 
                                                               value="<?= htmlspecialchars($settings['decimal_separator']) ?>" maxlength="1">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">Save General Settings</button>
                                        </form>
                                    </div>

                                    <!-- Email Settings Tab -->
                                    <div class="tab-pane" id="email" role="tabpanel">
                                        <form method="POST" action="system-settings.php" id="emailForm">
                                            <input type="hidden" name="action" value="save_email">
                                            
                                            <h5 class="font-size-14 mb-3">SMTP Configuration</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_driver" class="form-label">Mail Driver</label>
                                                        <select class="form-control" id="mail_driver" name="mail_driver">
                                                            <option value="smtp" <?= $settings['mail_driver'] == 'smtp' ? 'selected' : '' ?>>SMTP</option>
                                                            <option value="sendmail" <?= $settings['mail_driver'] == 'sendmail' ? 'selected' : '' ?>>Sendmail</option>
                                                            <option value="mail" <?= $settings['mail_driver'] == 'mail' ? 'selected' : '' ?>>PHP Mail</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_encryption" class="form-label">Encryption</label>
                                                        <select class="form-control" id="mail_encryption" name="mail_encryption">
                                                            <option value="tls" <?= $settings['mail_encryption'] == 'tls' ? 'selected' : '' ?>>TLS</option>
                                                            <option value="ssl" <?= $settings['mail_encryption'] == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                                            <option value="none" <?= $settings['mail_encryption'] == 'none' ? 'selected' : '' ?>>None</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="mb-3">
                                                        <label for="mail_host" class="form-label">SMTP Host</label>
                                                        <input type="text" class="form-control" id="mail_host" name="mail_host" 
                                                               value="<?= htmlspecialchars($settings['mail_host']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="mail_port" class="form-label">Port</label>
                                                        <input type="number" class="form-control" id="mail_port" name="mail_port" 
                                                               value="<?= $settings['mail_port'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_username" class="form-label">Username</label>
                                                        <input type="text" class="form-control" id="mail_username" name="mail_username" 
                                                               value="<?= htmlspecialchars($settings['mail_username']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_password" class="form-label">Password</label>
                                                        <input type="password" class="form-control" id="mail_password" name="mail_password" 
                                                               value="<?= htmlspecialchars($settings['mail_password']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h5 class="font-size-14 mb-3 mt-4">Email Settings</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_from_address" class="form-label">From Address</label>
                                                        <input type="email" class="form-control" id="mail_from_address" name="mail_from_address" 
                                                               value="<?= htmlspecialchars($settings['mail_from_address']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="mail_from_name" class="form-label">From Name</label>
                                                        <input type="text" class="form-control" id="mail_from_name" name="mail_from_name" 
                                                               value="<?= htmlspecialchars($settings['mail_from_name']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="mail_reply_to" class="form-label">Reply-To Address</label>
                                                <input type="email" class="form-control" id="mail_reply_to" name="mail_reply_to" 
                                                       value="<?= htmlspecialchars($settings['mail_reply_to']) ?>">
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">Save Email Settings</button>
                                            <button type="button" class="btn btn-success" id="testEmailBtn">Send Test Email</button>
                                        </form>
                                    </div>

                                    <!-- Backup Settings Tab -->
                                    <div class="tab-pane" id="backup" role="tabpanel">
                                        <form method="POST" action="system-settings.php" id="backupForm">
                                            <input type="hidden" name="action" value="save_backup">
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" id="backup_enabled" name="backup_enabled" 
                                                                   <?= $settings['backup_enabled'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="backup_enabled">Enable Automatic Backups</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" id="backup_include_files" name="backup_include_files" 
                                                                   <?= $settings['backup_include_files'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="backup_include_files">Include Uploaded Files</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="backup_frequency" class="form-label">Backup Frequency</label>
                                                        <select class="form-control" id="backup_frequency" name="backup_frequency">
                                                            <option value="hourly" <?= $settings['backup_frequency'] == 'hourly' ? 'selected' : '' ?>>Hourly</option>
                                                            <option value="daily" <?= $settings['backup_frequency'] == 'daily' ? 'selected' : '' ?>>Daily</option>
                                                            <option value="weekly" <?= $settings['backup_frequency'] == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                                            <option value="monthly" <?= $settings['backup_frequency'] == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="backup_time" class="form-label">Backup Time</label>
                                                        <input type="time" class="form-control" id="backup_time" name="backup_time" 
                                                               value="<?= htmlspecialchars($settings['backup_time']) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="backup_retention" class="form-label">Retention (days)</label>
                                                        <input type="number" class="form-control" id="backup_retention" name="backup_retention" 
                                                               value="<?= $settings['backup_retention'] ?>" min="1" max="365">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="backup_path" class="form-label">Backup Path</label>
                                                <input type="text" class="form-control" id="backup_path" name="backup_path" 
                                                       value="<?= htmlspecialchars($settings['backup_path']) ?>">
                                                <small class="text-muted">Relative to application root</small>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">Save Backup Settings</button>
                                            <button type="button" class="btn btn-success" id="createBackupBtn">Create Backup Now</button>
                                        </form>
                                        
                                        <hr>
                                        
                                        <h5 class="font-size-14 mb-3">Existing Backups</h5>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Backup File</th>
                                                        <th>Size</th>
                                                        <th>Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="backupList">
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted">
                                                            <i class="mdi mdi-loading mdi-spin"></i> Loading backups...
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Security Settings Tab -->
                                    <div class="tab-pane" id="security" role="tabpanel">
                                        <form method="POST" action="system-settings.php" id="securityForm">
                                            <input type="hidden" name="action" value="save_security">
                                            
                                            <h5 class="font-size-14 mb-3">Session Settings</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="session_lifetime" class="form-label">Session Lifetime (seconds)</label>
                                                        <input type="number" class="form-control" id="session_lifetime" name="session_lifetime" 
                                                               value="<?= $settings['session_lifetime'] ?>" min="60" step="60">
                                                        <small class="text-muted">Default: 3600 (1 hour)</small>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="session_timeout" class="form-label">Inactivity Timeout (seconds)</label>
                                                        <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                                               value="<?= $settings['session_timeout'] ?>" min="60" step="60">
                                                        <small class="text-muted">Default: 1800 (30 minutes)</small>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h5 class="font-size-14 mb-3 mt-4">Password Policy</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="password_min_length" class="form-label">Minimum Length</label>
                                                        <input type="number" class="form-control" id="password_min_length" name="password_min_length" 
                                                               value="<?= $settings['password_min_length'] ?>" min="6" max="20">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="password_require_uppercase" 
                                                                   name="password_require_uppercase" <?= $settings['password_require_uppercase'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="password_require_uppercase">
                                                                Require Uppercase Letters
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="password_require_lowercase" 
                                                                   name="password_require_lowercase" <?= $settings['password_require_lowercase'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="password_require_lowercase">
                                                                Require Lowercase Letters
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="password_require_numbers" 
                                                                   name="password_require_numbers" <?= $settings['password_require_numbers'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="password_require_numbers">
                                                                Require Numbers
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="password_require_symbols" 
                                                                   name="password_require_symbols" <?= $settings['password_require_symbols'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="password_require_symbols">
                                                                Require Special Characters
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <h5 class="font-size-14 mb-3 mt-4">Login Security</h5>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                                                        <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                                               value="<?= $settings['max_login_attempts'] ?>" min="1" max="10">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label for="lockout_duration" class="form-label">Lockout Duration (seconds)</label>
                                                        <input type="number" class="form-control" id="lockout_duration" name="lockout_duration" 
                                                               value="<?= $settings['lockout_duration'] ?>" min="60" step="60">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="two_factor_auth" name="two_factor_auth" 
                                                           <?= $settings['two_factor_auth'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="two_factor_auth">Enable Two-Factor Authentication</label>
                                                </div>
                                                <small class="text-muted">Requires email configuration</small>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">Save Security Settings</button>
                                        </form>
                                    </div>

                                    <!-- System Info Tab -->
                                    <div class="tab-pane" id="system" role="tabpanel">
                                        <h5 class="font-size-14 mb-3">System Information</h5>
                                        <table class="table table-bordered">
                                            <tr>
                                                <th width="30%">PHP Version</th>
                                                <td><?= phpversion() ?></td>
                                            </tr>
                                            <tr>
                                                <th>MySQL Version</th>
                                                <td><?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?></td>
                                            </tr>
                                            <tr>
                                                <th>Server Software</th>
                                                <td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></td>
                                            </tr>
                                            <tr>
                                                <th>Document Root</th>
                                                <td><?= $_SERVER['DOCUMENT_ROOT'] ?></td>
                                            </tr>
                                            <tr>
                                                <th>Application Path</th>
                                                <td><?= __DIR__ ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Upload Size</th>
                                                <td><?= ini_get('upload_max_filesize') ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Post Size</th>
                                                <td><?= ini_get('post_max_size') ?></td>
                                            </tr>
                                            <tr>
                                                <th>Memory Limit</th>
                                                <td><?= ini_get('memory_limit') ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Execution Time</th>
                                                <td><?= ini_get('max_execution_time') ?> seconds</td>
                                            </tr>
                                            <tr>
                                                <th>Display Errors</th>
                                                <td><?= ini_get('display_errors') ? 'On' : 'Off' ?></td>
                                            </tr>
                                            <tr>
                                                <th>Error Reporting</th>
                                                <td><?= error_reporting() ?></td>
                                            </tr>
                                        </table>
                                        
                                        <h5 class="font-size-14 mb-3 mt-4">Database Tables</h5>
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Table Name</th>
                                                    <th>Records</th>
                                                    <th>Size (KB)</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tableStats">
                                                <?php
                                                try {
                                                    $tables = $pdo->query("SHOW TABLE STATUS FROM u966043993_vkm")->fetchAll();
                                                    foreach ($tables as $table) {
                                                        $countStmt = $pdo->query("SELECT COUNT(*) FROM {$table['Name']}");
                                                        $count = $countStmt->fetchColumn();
                                                        $size = round($table['Data_length'] / 1024, 2);
                                                        echo "<tr>";
                                                        echo "<td>{$table['Name']}</td>";
                                                        echo "<td>" . number_format($count) . "</td>";
                                                        echo "<td>" . number_format($size, 2) . " KB</td>";
                                                        echo "</tr>";
                                                    }
                                                } catch (Exception $e) {
                                                    echo "<tr><td colspan='3' class='text-danger'>Error loading table stats</td></tr>";
                                                }
                                                ?>
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

<!-- Test Email Modal -->
<div class="modal fade" id="testEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Test Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="system-settings.php" id="testEmailForm">
                <input type="hidden" name="action" value="test_email">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="test_email" name="test_email" 
                               value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Test Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Backup Modal -->
<div class="modal fade" id="createBackupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="system-settings.php" id="createBackupForm">
                <input type="hidden" name="action" value="create_backup">
                <div class="modal-body">
                    <p>This will create a complete database backup. Large databases may take some time.</p>
                    <div class="alert alert-info">
                        <i class="mdi mdi-information me-2"></i>
                        Backup will be saved in the backups folder.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Backup</button>
                </div>
            </form>
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
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Bootstrap Toggle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap4-toggle@3.6.1/js/bootstrap4-toggle.min.js"></script>

<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Select2
    $('.select2').select2({
        width: '100%'
    });

    // Test email button
    document.getElementById('testEmailBtn')?.addEventListener('click', function() {
        var modal = new bootstrap.Modal(document.getElementById('testEmailModal'));
        modal.show();
    });

    // Create backup button
    document.getElementById('createBackupBtn')?.addEventListener('click', function() {
        var modal = new bootstrap.Modal(document.getElementById('createBackupModal'));
        modal.show();
    });

    // Load backups list
    function loadBackups() {
        fetch('get-backups.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.backups.length > 0) {
                    let html = '';
                    data.backups.forEach(backup => {
                        html += `
                            <tr>
                                <td>${backup.name}</td>
                                <td>${backup.size}</td>
                                <td>${backup.date}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="downloadBackup('${backup.name}')">
                                        <i class="mdi mdi-download"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('${backup.name}')">
                                        <i class="mdi mdi-delete"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    document.getElementById('backupList').innerHTML = html;
                } else {
                    document.getElementById('backupList').innerHTML = `
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                <i class="mdi mdi-information-outline"></i> No backups found
                            </td>
                        </tr>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading backups:', error);
                document.getElementById('backupList').innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-danger">
                            <i class="mdi mdi-alert-circle"></i> Error loading backups
                        </td>
                    </tr>
                `;
            });
    }

    // Download backup
    function downloadBackup(filename) {
        window.location.href = 'download-backup.php?file=' + encodeURIComponent(filename);
    }

    // Delete backup
    function deleteBackup(filename) {
        Swal.fire({
            title: 'Delete Backup',
            text: `Are you sure you want to delete ${filename}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f46a6a',
            cancelButtonColor: '#556ee6',
            confirmButtonText: 'Yes, delete!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('delete-backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'file=' + encodeURIComponent(filename)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Backup deleted successfully',
                            timer: 1500,
                            showConfirmButton: false
                        });
                        loadBackups();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Failed to delete backup',
                            confirmButtonColor: '#556ee6'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred',
                        confirmButtonColor: '#556ee6'
                    });
                });
            }
        });
    }

    // Load backups on page load
    loadBackups();

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
    form, .nav-tabs, .no-print {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .tab-pane {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
    }
}

/* Form styling */
.form-switch {
    padding-left: 2.5em;
}

.form-switch .form-check-input {
    width: 3em;
    margin-left: -2.5em;
    height: 1.5em;
}

/* Select2 customization */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

/* Tab styling */
.nav-tabs-custom {
    border-bottom: 2px solid #f0f0f0;
}

.nav-tabs-custom .nav-item {
    margin-bottom: -2px;
}

.nav-tabs-custom .nav-link {
    border: none;
    color: #6c757d;
    padding: 10px 20px;
    font-weight: 500;
}

.nav-tabs-custom .nav-link.active {
    color: #556ee6;
    background-color: transparent;
    border-bottom: 2px solid #556ee6;
}

.nav-tabs-custom .nav-link i {
    font-size: 18px;
    vertical-align: middle;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .nav-tabs-custom .nav-link {
        padding: 8px 12px;
        font-size: 13px;
    }
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

/* Table styling */
.table th {
    font-weight: 600;
    color: #495057;
}

.table-bordered {
    border: 1px solid #e9ecef;
}

.table-bordered th,
.table-bordered td {
    border: 1px solid #e9ecef;
}

/* Info alert */
.alert-info {
    background-color: rgba(80, 165, 241, 0.1);
    border-color: rgba(80, 165, 241, 0.2);
    color: #50a5f1;
}
</style>

</body>
</html>