<?php
ob_start();
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once 'config/database.php';

if (!isset($pdo) || !$pdo) {
    die("Database connection not established. Please check config/database.php");
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function validDate(?string $date): bool
{
    if (!$date) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function getValidSessionUserId(PDO $pdo): ?int
{
    $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($sessionUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionUserId]);
    $exists = $stmt->fetchColumn();

    return $exists ? $sessionUserId : null;
}

function redirectWithFilters(array $extra = []): void
{
    $params = [
        'search'    => $_GET['search'] ?? '',
        'status'    => $_GET['status'] ?? 'all',
        'from_date' => $_GET['from_date'] ?? date('Y-m-01'),
        'to_date'   => $_GET['to_date'] ?? date('Y-m-d'),
        'page'      => $_GET['page'] ?? 1,
    ];

    $params = array_merge($params, $extra);

    header('Location: referrals.php?' . http_build_query($params));
    exit;
}

$userId = getValidSessionUserId($pdo);
if ($userId === null) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$from_date = isset($_GET['from_date']) && validDate($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) && validDate($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

/* =========================
   ADD REFERRAL
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_referral') {
    $referrer_name = trim($_POST['referrer_name'] ?? '');
    $referrer_phone = trim($_POST['referrer_phone'] ?? '');
    $referrer_email = trim($_POST['referrer_email'] ?? '');
    $referred_customer_id = !empty($_POST['referred_customer_id']) ? (int)$_POST['referred_customer_id'] : null;
    $referral_date = isset($_POST['referral_date']) && validDate($_POST['referral_date']) ? $_POST['referral_date'] : date('Y-m-d');
    $commission_amount = isset($_POST['commission_amount']) && $_POST['commission_amount'] !== '' ? (float)$_POST['commission_amount'] : 0.00;
    $notes = trim($_POST['notes'] ?? '');

    $referral_code = 'REF' . strtoupper(substr(uniqid(), -8));

    if ($referrer_name === '') {
        $error = "Referrer name is required.";
    } elseif ($referrer_phone === '') {
        $error = "Referrer phone number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $referrer_phone)) {
        $error = "Referrer phone number must be 10 digits.";
    } elseif ($referrer_email !== '' && !filter_var($referrer_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $pdo->beginTransaction();

            if ($referred_customer_id !== null) {
                $checkCustomer = $pdo->prepare("SELECT id FROM customers WHERE id = ? LIMIT 1");
                $checkCustomer->execute([$referred_customer_id]);
                if (!$checkCustomer->fetchColumn()) {
                    throw new Exception("Selected customer not found.");
                }
            }

            $checkStmt = $pdo->prepare("SELECT id FROM referrals WHERE referral_code = ? LIMIT 1");
            $checkStmt->execute([$referral_code]);
            if ($checkStmt->fetch()) {
                $referral_code = 'REF' . strtoupper(substr(uniqid(), -8)) . rand(10, 99);
            }

            $stmt = $pdo->prepare("
                INSERT INTO referrals (
                    referral_code,
                    referrer_name,
                    referrer_phone,
                    referrer_email,
                    referred_customer_id,
                    referral_date,
                    status,
                    commission_amount,
                    commission_paid,
                    notes,
                    created_by,
                    created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 'pending', ?, 0, ?, ?, NOW()
                )
            ");

            $stmt->execute([
                $referral_code,
                $referrer_name,
                $referrer_phone,
                $referrer_email !== '' ? $referrer_email : null,
                $referred_customer_id,
                $referral_date,
                $commission_amount,
                $notes !== '' ? $notes : null,
                $userId
            ]);

            $referral_id = (int)$pdo->lastInsertId();

            try {
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at, created_by
                    ) VALUES (?, 3, ?, ?, NOW(), ?)
                ");

                $activity_data = json_encode([
                    'referral_id' => $referral_id,
                    'referral_code' => $referral_code,
                    'referrer_name' => $referrer_name,
                    'commission' => $commission_amount
                ], JSON_UNESCAPED_UNICODE);

                $activity_stmt->execute([
                    $userId,
                    "New referral created: " . $referrer_name,
                    $activity_data,
                    $userId
                ]);
            } catch (Throwable $e) {
                error_log("Referral activity log insert failed: " . $e->getMessage());
            }

            $pdo->commit();

            $_SESSION['success_message'] = "Referral created successfully. Referral Code: " . $referral_code;
            header("Location: referrals.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Failed to create referral: " . $e->getMessage();
            error_log("Referral creation error: " . $e->getMessage());
            header("Location: referrals.php");
            exit;
        }
    }
}

/* =========================
   STATUS UPDATE
========================= */
if (isset($_GET['action'], $_GET['id'])) {
    $action = trim($_GET['action']);
    $referral_id = (int)$_GET['id'];
    $allowed_actions = ['pending', 'converted', 'expired'];

    if (in_array($action, $allowed_actions, true) && $referral_id > 0) {
        try {
            $pdo->beginTransaction();

            $getStmt = $pdo->prepare("SELECT id, referrer_name, referral_code, status FROM referrals WHERE id = ? LIMIT 1");
            $getStmt->execute([$referral_id]);
            $referral = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$referral) {
                throw new Exception("Referral not found.");
            }

            $stmt = $pdo->prepare("UPDATE referrals SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$action, $userId, $referral_id]);

            try {
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at, created_by
                    ) VALUES (?, 4, ?, ?, NOW(), ?)
                ");

                $activity_data = json_encode([
                    'referral_id' => $referral_id,
                    'referral_code' => $referral['referral_code'],
                    'old_status' => $referral['status'],
                    'new_status' => $action
                ], JSON_UNESCAPED_UNICODE);

                $activity_stmt->execute([
                    $userId,
                    "Referral status updated to {$action} for {$referral['referrer_name']}",
                    $activity_data,
                    $userId
                ]);
            } catch (Throwable $e) {
                error_log("Referral status activity log failed: " . $e->getMessage());
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Referral status updated successfully.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Failed to update referral status: " . $e->getMessage();
            error_log("Referral status update error: " . $e->getMessage());
        }
    }

    redirectWithFilters();
}

/* =========================
   COMMISSION TOGGLE
========================= */
if (isset($_GET['pay_commission'], $_GET['id'])) {
    $referral_id = (int)$_GET['id'];
    $pay = (int)$_GET['pay_commission'] === 1 ? 1 : 0;

    if ($referral_id > 0) {
        try {
            $pdo->beginTransaction();

            $getStmt = $pdo->prepare("
                SELECT id, referrer_name, referral_code, commission_amount, commission_paid
                FROM referrals
                WHERE id = ?
                LIMIT 1
            ");
            $getStmt->execute([$referral_id]);
            $referral = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$referral) {
                throw new Exception("Referral not found.");
            }

            $stmt = $pdo->prepare("
                UPDATE referrals
                SET commission_paid = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$pay, $userId, $referral_id]);

            try {
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at, created_by
                    ) VALUES (?, 4, ?, ?, NOW(), ?)
                ");

                $activity_data = json_encode([
                    'referral_id' => $referral_id,
                    'referral_code' => $referral['referral_code'],
                    'commission_amount' => $referral['commission_amount'],
                    'old_commission_paid' => (int)$referral['commission_paid'],
                    'new_commission_paid' => $pay
                ], JSON_UNESCAPED_UNICODE);

                $activity_stmt->execute([
                    $userId,
                    "Commission " . ($pay === 1 ? 'paid' : 'unpaid') . " for " . $referral['referrer_name'],
                    $activity_data,
                    $userId
                ]);
            } catch (Throwable $e) {
                error_log("Referral commission activity log failed: " . $e->getMessage());
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Commission status updated successfully.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Failed to update commission status: " . $e->getMessage();
            error_log("Commission update error: " . $e->getMessage());
        }
    }

    redirectWithFilters();
}

/* =========================
   DELETE
========================= */
if (isset($_GET['delete'], $_GET['id'])) {
    $referral_id = (int)$_GET['id'];

    if ($referral_id > 0) {
        try {
            $pdo->beginTransaction();

            $getStmt = $pdo->prepare("SELECT id, referrer_name, referral_code FROM referrals WHERE id = ? LIMIT 1");
            $getStmt->execute([$referral_id]);
            $referral = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$referral) {
                throw new Exception("Referral not found.");
            }

            $stmt = $pdo->prepare("DELETE FROM referrals WHERE id = ?");
            $stmt->execute([$referral_id]);

            try {
                $activity_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (
                        user_id, activity_type_id, description, activity_data, created_at, created_by
                    ) VALUES (?, 5, ?, ?, NOW(), ?)
                ");

                $activity_data = json_encode([
                    'referral_id' => $referral_id,
                    'referral_code' => $referral['referral_code'],
                    'referrer_name' => $referral['referrer_name']
                ], JSON_UNESCAPED_UNICODE);

                $activity_stmt->execute([
                    $userId,
                    "Referral deleted: " . $referral['referrer_name'],
                    $activity_data,
                    $userId
                ]);
            } catch (Throwable $e) {
                error_log("Referral delete activity log failed: " . $e->getMessage());
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Referral deleted successfully.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = "Failed to delete referral: " . $e->getMessage();
            error_log("Referral delete error: " . $e->getMessage());
        }
    }

    redirectWithFilters();
}

/* =========================
   LIST QUERY
========================= */
$query = "
    SELECT
        r.*,
        c.name AS referred_customer_name,
        c.customer_code AS referred_customer_code,
        c.phone AS referred_customer_phone,
        u.full_name AS created_by_name
    FROM referrals r
    LEFT JOIN customers c ON r.referred_customer_id = c.id
    LEFT JOIN users u ON r.created_by = u.id
    WHERE 1=1
";

$countQuery = "SELECT COUNT(*) FROM referrals r WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (r.referrer_name LIKE ? OR r.referrer_phone LIKE ? OR r.referrer_email LIKE ? OR r.referral_code LIKE ?)";
    $countQuery .= " AND (r.referrer_name LIKE ? OR r.referrer_phone LIKE ? OR r.referrer_email LIKE ? OR r.referral_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

if ($status !== 'all') {
    $query .= " AND r.status = ?";
    $countQuery .= " AND r.status = ?";
    $params[] = $status;
}

if ($from_date !== '' && $to_date !== '') {
    $query .= " AND DATE(r.referral_date) BETWEEN ? AND ?";
    $countQuery .= " AND DATE(r.referral_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
}

$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));

$query .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;

$stmt = $pdo->prepare($query);

for ($i = 0; $i < count($listParams); $i++) {
    $paramIndex = $i + 1;
    $paramValue = $listParams[$i];

    if ($i >= count($listParams) - 2) {
        $stmt->bindValue($paramIndex, (int)$paramValue, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($paramIndex, $paramValue, PDO::PARAM_STR);
    }
}

$stmt->execute();
$referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   STATS
========================= */
$statsQuery = "
    SELECT
        COUNT(*) AS total_referrals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_referrals,
        SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted_referrals,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_referrals,
        COALESCE(SUM(commission_amount), 0) AS total_commission,
        COALESCE(SUM(CASE WHEN commission_paid = 1 THEN commission_amount ELSE 0 END), 0) AS paid_commission,
        COALESCE(SUM(CASE WHEN commission_paid = 0 AND status = 'converted' THEN commission_amount ELSE 0 END), 0) AS pending_commission
    FROM referrals
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* =========================
   CUSTOMERS
========================= */
$customersStmt = $pdo->query("
    SELECT id, name, customer_code, phone
    FROM customers
    WHERE is_active = 1
    ORDER BY name
");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   SESSION MESSAGES
========================= */
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">


                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i>
                        <?= h($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i>
                        <?= h($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2"><?= number_format((float)($stats['total_referrals'] ?? 0)) ?></h3>
                                Total Referrals
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2"><?= number_format((float)($stats['converted_referrals'] ?? 0)) ?></h3>
                                Converted
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">₹<?= number_format((float)($stats['total_commission'] ?? 0), 2) ?></h3>
                                Total Commission
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">₹<?= number_format((float)($stats['pending_commission'] ?? 0), 2) ?></h3>
                                Pending Commission
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-9">
                                        <form method="GET" action="" id="filterForm">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label class="form-label">Search</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
                                                            <input type="text" class="form-control" name="search" placeholder="Search by name, phone, email or code..." value="<?= h($search) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">From Date</label>
                                                        <input type="date" class="form-control" name="from_date" value="<?= h($from_date) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">To Date</label>
                                                        <input type="date" class="form-control" name="to_date" value="<?= h($to_date) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                                                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="converted" <?= $status === 'converted' ? 'selected' : '' ?>>Converted</option>
                                                            <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">&nbsp;</label>
                                                        <div class="d-flex gap-2">
                                                            <button type="submit" class="btn btn-primary w-50">
                                                                <i class="mdi mdi-filter me-1"></i> Filter
                                                            </button>
                                                            <a href="referrals.php" class="btn btn-secondary w-50">
                                                                <i class="mdi mdi-refresh me-1"></i> Reset
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-md-end mt-3 mt-md-0">
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReferralModal">
                                                <i class="mdi mdi-account-plus me-1"></i> Add Referral
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Referral List</h4>

                                <?php if (empty($referrals)): ?>
                                    <div class="text-center py-5">
                                        <i class="mdi mdi-account-multiple-off" style="font-size: 48px; color: #ccc;"></i>
                                        <h5 class="mt-3">No referrals found</h5>
                                        <p class="text-muted">Try adjusting your search or filter criteria or add a new referral</p>
                                        <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addReferralModal">
                                            <i class="mdi mdi-account-plus me-1"></i> Add New Referral
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Referral Code</th>
                                                    <th>Referrer</th>
                                                    <th>Contact</th>
                                                    <th>Referred Customer</th>
                                                    <th>Date</th>
                                                    <th>Commission</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($referrals as $referral): ?>
                                                    <tr>
                                                        <td><strong><?= h($referral['referral_code']) ?></strong></td>
                                                        <td>
                                                            <h6 class="mb-1"><?= h($referral['referrer_name']) ?></h6>
                                                            <?php if (!empty($referral['referrer_email'])): ?>
                                                                <small class="text-muted"><?= h($referral['referrer_email']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <i class="mdi mdi-phone me-1 text-muted"></i> <?= h($referral['referrer_phone']) ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($referral['referred_customer_id'])): ?>
                                                                <a href="view-customer.php?id=<?= (int)$referral['referred_customer_id'] ?>" class="text-primary">
                                                                    <?= h($referral['referred_customer_name']) ?>
                                                                </a><br>
                                                                <small class="text-muted"><?= h($referral['referred_customer_code']) ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not assigned</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= date('d-m-Y', strtotime($referral['referral_date'])) ?></td>
                                                        <td>
                                                            <div>₹<?= number_format((float)$referral['commission_amount'], 2) ?></div>
                                                            <?php if ((int)$referral['commission_paid'] === 1): ?>
                                                                <span class="badge bg-soft-success text-success">Paid</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-soft-warning text-warning">Unpaid</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $statusClass = 'secondary';
                                                            $statusIcon = 'help-circle';
                                                            switch ($referral['status']) {
                                                                case 'pending':
                                                                    $statusClass = 'warning';
                                                                    $statusIcon = 'clock-outline';
                                                                    break;
                                                                case 'converted':
                                                                    $statusClass = 'success';
                                                                    $statusIcon = 'check-circle';
                                                                    break;
                                                                case 'expired':
                                                                    $statusClass = 'danger';
                                                                    $statusIcon = 'alert-circle';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="badge bg-soft-<?= $statusClass ?> text-<?= $statusClass ?>">
                                                                <i class="mdi mdi-<?= $statusIcon ?> me-1"></i><?= ucfirst($referral['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button type="button" class="btn btn-sm btn-soft-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                                    <i class="mdi mdi-cog"></i>
                                                                </button>
                                                                <ul class="dropdown-menu">
                                                                    <li>
                                                                        <a class="dropdown-item" href="javascript:void(0);" onclick='viewReferral(<?= json_encode($referral, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                                                            <i class="mdi mdi-eye text-primary me-2"></i> View Details
                                                                        </a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li>
                                                                        <a class="dropdown-item <?= $referral['status'] === 'pending' ? '' : 'disabled' ?>" href="?action=converted&id=<?= (int)$referral['id'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&page=<?= (int)$page ?>">
                                                                            <i class="mdi mdi-check-circle text-success me-2"></i> Mark as Converted
                                                                        </a>
                                                                    </li>
                                                                    <li>
                                                                        <a class="dropdown-item <?= $referral['status'] === 'pending' ? '' : 'disabled' ?>" href="?action=expired&id=<?= (int)$referral['id'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&page=<?= (int)$page ?>">
                                                                            <i class="mdi mdi-clock-alert text-warning me-2"></i> Mark as Expired
                                                                        </a>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li>
                                                                        <?php if ((int)$referral['commission_paid'] === 1): ?>
                                                                            <a class="dropdown-item" href="?pay_commission=0&id=<?= (int)$referral['id'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&page=<?= (int)$page ?>">
                                                                                <i class="mdi mdi-cash-remove text-danger me-2"></i> Mark as Unpaid
                                                                            </a>
                                                                        <?php else: ?>
                                                                            <a class="dropdown-item" href="?pay_commission=1&id=<?= (int)$referral['id'] ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&page=<?= (int)$page ?>">
                                                                                <i class="mdi mdi-cash-check text-success me-2"></i> Mark as Paid
                                                                            </a>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li>
                                                                        <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="confirmDelete(<?= (int)$referral['id'] ?>, <?= json_encode($referral['referrer_name']) ?>)">
                                                                            <i class="mdi mdi-delete text-danger me-2"></i> Delete
                                                                        </a>
                                                                    </li>
                                                                </ul>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-sm-6">
                                                <div class="text-muted">
                                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <ul class="pagination justify-content-end">
                                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">
                                                            <i class="mdi mdi-chevron-left"></i>
                                                        </a>
                                                    </li>

                                                    <?php
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    for ($i = $startPage; $i <= $endPage; $i++):
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>

                                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">
                                                            <i class="mdi mdi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>

<div class="modal fade" id="addReferralModal" tabindex="-1" aria-labelledby="addReferralModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title text-white" id="addReferralModalLabel">
                    <i class="mdi mdi-account-plus me-2"></i> Add New Referral
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="referralForm">
                <input type="hidden" name="form_action" value="add_referral">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referrer Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="mdi mdi-account"></i></span>
                                    <input type="text" name="referrer_name" class="form-control" placeholder="Enter referrer name" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referrer Phone <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="mdi mdi-phone"></i></span>
                                    <input type="tel" name="referrer_phone" class="form-control" placeholder="10 digit mobile number" maxlength="10" pattern="[0-9]{10}" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referrer Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="mdi mdi-email"></i></span>
                                    <input type="email" name="referrer_email" class="form-control" placeholder="Email address">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referral Date</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="mdi mdi-calendar"></i></span>
                                    <input type="date" name="referral_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referred Customer</label>
                                <select name="referred_customer_id" class="form-control">
                                    <option value="">Select customer (optional)</option>
                                    <?php foreach ($customers as $cust): ?>
                                        <option value="<?= (int)$cust['id'] ?>">
                                            <?= h($cust['name']) ?> (<?= h($cust['customer_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Commission Amount (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="mdi mdi-currency-inr"></i></span>
                                    <input type="number" name="commission_amount" class="form-control" placeholder="0.00" min="0" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="mdi mdi-information me-2"></i>
                        A unique referral code will be automatically generated.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="mdi mdi-close me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="referralSubmitBtn">
                        <i class="mdi mdi-check me-1"></i>
                        <span id="referralBtnText">Add Referral</span>
                        <span id="referralLoading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                            Saving...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewReferralModal" tabindex="-1" aria-labelledby="viewReferralModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white" id="viewReferralModalLabel">
                    <i class="mdi mdi-account-details me-2"></i> Referral Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="text-muted">Referral Code</label>
                        <p class="font-weight-bold" id="view_code"></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted">Status</label>
                        <p id="view_status"></p>
                    </div>
                </div>

                <h6 class="mt-3 mb-2">Referrer Information</h6>
                <hr>
                <div class="row mb-2"><div class="col-4"><strong>Name:</strong></div><div class="col-8" id="view_name"></div></div>
                <div class="row mb-2"><div class="col-4"><strong>Phone:</strong></div><div class="col-8" id="view_phone"></div></div>
                <div class="row mb-2"><div class="col-4"><strong>Email:</strong></div><div class="col-8" id="view_email"></div></div>

                <h6 class="mt-3 mb-2">Referral Details</h6>
                <hr>
                <div class="row mb-2"><div class="col-4"><strong>Date:</strong></div><div class="col-8" id="view_date"></div></div>
                <div class="row mb-2"><div class="col-4"><strong>Commission:</strong></div><div class="col-8" id="view_commission"></div></div>
                <div class="row mb-2"><div class="col-4"><strong>Payment Status:</strong></div><div class="col-8" id="view_payment"></div></div>

                <h6 class="mt-3 mb-2">Referred Customer</h6>
                <hr>
                <div class="row mb-2"><div class="col-4"><strong>Customer:</strong></div><div class="col-8" id="view_customer"></div></div>
                <div class="row mb-2" id="notes_row" style="display:none;">
                    <div class="col-4"><strong>Notes:</strong></div>
                    <div class="col-8" id="view_notes"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/scripts.php'); ?>

<link rel="stylesheet" href="assets/libs/sweetalert2/sweetalert2.min.css">
<script src="assets/libs/sweetalert2/sweetalert2.min.js"></script>

<script>
document.getElementById('referralForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('referralSubmitBtn');
    const btnText = document.getElementById('referralBtnText');
    const loading = document.getElementById('referralLoading');

    if (btn) btn.disabled = true;
    if (btnText) btnText.style.display = 'none';
    if (loading) loading.style.display = 'inline-block';
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(function() {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 500);
    });
}, 5000);

document.querySelector('input[name="referrer_phone"]')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
});

function viewReferral(referral) {
    document.getElementById('view_code').textContent = referral.referral_code || 'N/A';

    let statusBadge = '';
    switch (referral.status) {
        case 'pending':
            statusBadge = '<span class="badge bg-warning">Pending</span>';
            break;
        case 'converted':
            statusBadge = '<span class="badge bg-success">Converted</span>';
            break;
        case 'expired':
            statusBadge = '<span class="badge bg-danger">Expired</span>';
            break;
        default:
            statusBadge = '<span class="badge bg-secondary">Unknown</span>';
    }
    document.getElementById('view_status').innerHTML = statusBadge;

    document.getElementById('view_name').textContent = referral.referrer_name || 'N/A';
    document.getElementById('view_phone').textContent = referral.referrer_phone || 'N/A';
    document.getElementById('view_email').textContent = referral.referrer_email || 'N/A';
    document.getElementById('view_date').textContent = referral.referral_date ? new Date(referral.referral_date).toLocaleDateString('en-IN') : 'N/A';
    document.getElementById('view_commission').textContent = '₹' + (parseFloat(referral.commission_amount) || 0).toFixed(2);
    document.getElementById('view_payment').innerHTML = parseInt(referral.commission_paid) === 1
        ? '<span class="badge bg-success">Paid</span>'
        : '<span class="badge bg-warning">Unpaid</span>';
    document.getElementById('view_customer').textContent = referral.referred_customer_name || 'Not assigned';

    if (referral.notes) {
        document.getElementById('view_notes').textContent = referral.notes;
        document.getElementById('notes_row').style.display = 'flex';
    } else {
        document.getElementById('notes_row').style.display = 'none';
    }

    $('#viewReferralModal').modal('show');
}

function confirmDelete(id, name) {
    Swal.fire({
        title: 'Delete Referral?',
        html: `Are you sure you want to delete referral for <strong>${name}</strong>?<br><br><span class="text-danger">This action cannot be undone!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f46a6a',
        cancelButtonColor: '#556ee6',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            const urlParams = new URLSearchParams(window.location.search);
            const search = urlParams.get('search') || '';
            const status = urlParams.get('status') || 'all';
            const from_date = urlParams.get('from_date') || '';
            const to_date = urlParams.get('to_date') || '';
            const page = urlParams.get('page') || 1;

            window.location.href =
                `referrals.php?delete=1&id=${id}` +
                `&search=${encodeURIComponent(search)}` +
                `&status=${encodeURIComponent(status)}` +
                `&from_date=${encodeURIComponent(from_date)}` +
                `&to_date=${encodeURIComponent(to_date)}` +
                `&page=${page}`;
        }
    });
}

document.querySelector('select[name="status"]')?.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

let searchTimeout;
document.querySelector('input[name="search"]')?.addEventListener('keyup', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (this.value.length >= 2 || this.value.length === 0) {
            document.getElementById('filterForm').submit();
        }
    }, 500);
});

document.querySelector('input[name="from_date"]')?.addEventListener('change', function() {
    const toDate = document.querySelector('input[name="to_date"]');
    if (toDate && toDate.value < this.value) {
        toDate.value = this.value;
    }
});

document.querySelector('input[name="to_date"]')?.addEventListener('change', function() {
    const fromDate = document.querySelector('input[name="from_date"]');
    if (fromDate && fromDate.value > this.value) {
        fromDate.value = this.value;
    }
});

document.addEventListener('keydown', function(e) {
    if (e.altKey && e.key === 'r') {
        e.preventDefault();
        $('#addReferralModal').modal('show');
    }

    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }

    if (e.key === 'Escape') {
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput && searchInput.value !== '') {
            searchInput.value = '';
            document.getElementById('filterForm').submit();
        }
    }
});

var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});
</script>

</body>
</html>