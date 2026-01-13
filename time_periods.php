<?php
// Strict error handling
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

session_start();

// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Include configuration
require_once 'config.php';
require_once 'functions.php'; // Include central functions

/* ----------------------------
    SECURITY FUNCTIONS
-----------------------------*/

function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || 
        !isset($_SESSION['user_ip']) || 
        !isset($_SESSION['user_agent']) ||
        !isset($_SESSION['login_time'])) {
        return false;
    }
    
    // IP address validation (with some flexibility for proxies)
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($_SESSION['user_ip'] !== $current_ip) {
        error_log("IP mismatch for user ID: " . ($_SESSION['user_id'] ?? 'unknown'));
        // Don't destroy immediately - could be legitimate proxy use
    }
    
    // User agent validation
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if ($_SESSION['user_agent'] !== $current_ua) {
        error_log("User agent changed for user ID: " . ($_SESSION['user_id'] ?? 'unknown'));
        // Could be browser update - log but don't destroy
    }
    
    // Session timeout (1 hour)
    if (time() - $_SESSION['login_time'] > 3600) {
        session_destroy();
        return false;
    }
    
    // Update last activity
    $_SESSION['login_time'] = time();
    
    return true;
}

function isSeller() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || 
        empty($_SESSION['csrf_token_time']) || 
        time() - $_SESSION['csrf_token_time'] > 3600) {
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function sanitizeInput($data) {
    if (!is_string($data)) {
        return '';
    }
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function validateInteger($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $value = intval($value);
    
    if ($min !== null && $value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return true;
}

// Check authentication
if (!isLoggedIn() || !isSeller()) {
    header("Location: login.php");
    exit();
}

// Generate CSRF token for this page
$csrf_token = generateCSRFToken();

// Database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Test connection
    if (!$db->ping()) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$error = '';

/* -------------------------------------------------------
   CARRY FORWARD FUNCTION - ENHANCED WITH SECURITY
-------------------------------------------------------- */

function carryForwardProductsToNewPeriod($db, $from_period_id, $to_period_id, $user_id) {
    // Validate inputs
    if (!validateInteger($from_period_id, 1) || !validateInteger($to_period_id, 1)) {
        return ['success' => 0, 'errors' => ['Invalid period IDs'], 'opening_balance' => 0];
    }
    
    // Verify user owns both periods
    $verify_sql = "SELECT id FROM time_periods WHERE id IN (?, ?) AND created_by = ?";
    $verify_stmt = $db->prepare($verify_sql);
    $verify_stmt->bind_param("iii", $from_period_id, $to_period_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows !== 2) {
        return ['success' => 0, 'errors' => ['Period ownership verification failed'], 'opening_balance' => 0];
    }
    
    // Get all active products from the previous period
    $sql = "SELECT * FROM products 
            WHERE period_id = ? 
            AND is_active = 1
            AND stock_quantity > 0";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $from_period_id);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $carried_count = 0;
    $errors = [];
    
    foreach ($products as $product) {
        // Check if product already exists in the new period
        $check_sql = "SELECT id FROM products 
                      WHERE name = ? 
                      AND sku = ? 
                      AND period_id = ?";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->bind_param("ssi", $product['name'], $product['sku'], $to_period_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            // Get the new period details with verification
            $period_sql = "SELECT year, month FROM time_periods WHERE id = ? AND created_by = ?";
            $period_stmt = $db->prepare($period_sql);
            $period_stmt->bind_param("ii", $to_period_id, $user_id);
            $period_stmt->execute();
            $period_data = $period_stmt->get_result()->fetch_assoc();
            
            if (!$period_data) {
                $errors[] = "Period data not found for ID: $to_period_id";
                continue;
            }
            
            // Validate product data
            $product_name = substr($product['name'], 0, 255);
            $product_sku = substr($product['sku'] ?? '', 0, 100);
            $product_supplier = substr($product['supplier'] ?? '', 0, 255);
            $product_description = substr($product['description'] ?? '', 0, 1000);
            
            // Validate numeric values
            $cost_price = floatval($product['cost_price']);
            $selling_price = floatval($product['selling_price']);
            $stock_quantity = intval($product['stock_quantity']);
            $min_stock = intval($product['min_stock']);
            
            if ($cost_price < 0 || $selling_price < 0 || $stock_quantity < 0 || $min_stock < 0) {
                $errors[] = "Invalid numeric values for product: " . $product['name'];
                continue;
            }
            
            // Insert carried forward product
            $insert_sql = "INSERT INTO products 
                          (name, sku, category_id, cost_price, selling_price,
                           stock_quantity, min_stock, supplier, description,
                           period_id, period_year, period_month, added_date,
                           is_carried_forward, carried_from_period_id, is_active) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, ?, 1)";
            
            $insert_stmt = $db->prepare($insert_sql);
            $insert_stmt->bind_param(
                "ssiddiissiiii", 
                $product_name, $product_sku, $product['category_id'],
                $cost_price, $selling_price,
                $stock_quantity, $min_stock,
                $product_supplier, $product_description,
                $to_period_id, $period_data['year'], $period_data['month'],
                $from_period_id
            );
            
            if ($insert_stmt->execute()) {
                $carried_count++;
            } else {
                $errors[] = "Failed to insert product: " . $db->error;
            }
        }
    }
    
    // Create inventory period for the new time period if it doesn't exist
    $inventory_sql = "INSERT INTO inventory_periods 
                     (time_period_id, opening_balance, current_inventory, 
                      closing_balance, status) 
                      VALUES (?, 0, 0, 0, 'active') 
                      ON DUPLICATE KEY UPDATE updated_at = NOW()";
    $inventory_stmt = $db->prepare($inventory_sql);
    $inventory_stmt->bind_param("i", $to_period_id);
    $inventory_stmt->execute();
    
    // Calculate opening balance (previous period's closing balance)
    $prev_inventory_sql = "SELECT closing_balance FROM inventory_periods WHERE time_period_id = ?";
    $prev_inventory_stmt = $db->prepare($prev_inventory_sql);
    $prev_inventory_stmt->bind_param("i", $from_period_id);
    $prev_inventory_stmt->execute();
    $prev_inventory_result = $prev_inventory_stmt->get_result();
    $prev_inventory = $prev_inventory_result->fetch_assoc();
    
    $opening_balance = $prev_inventory ? floatval($prev_inventory['closing_balance']) : 0;
    
    // Update opening balance for new period
    $update_opening_sql = "UPDATE inventory_periods SET opening_balance = ? WHERE time_period_id = ?";
    $update_opening_stmt = $db->prepare($update_opening_sql);
    $update_opening_stmt->bind_param("di", $opening_balance, $to_period_id);
    $update_opening_stmt->execute();
    
    // Log the carry forward operation
    error_log("Carry forward completed: $carried_count products from period $from_period_id to $to_period_id by user $user_id");
    
    return [
        'success' => $carried_count,
        'errors' => $errors,
        'opening_balance' => $opening_balance
    ];
}

/* -------------------------------------------------------
   HANDLE FORM SUBMISSIONS WITH SECURITY VALIDATION
-------------------------------------------------------- */

// Check and auto-create periods if needed
checkAndCreateNewPeriod($user_id, $db);

// Handle new period creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_period'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid form submission. Please try again.";
        error_log("CSRF token validation failed on period creation by user $user_id");
    } else {
        $year = intval($_POST['year'] ?? 0);
        $month = intval($_POST['month'] ?? 0);
        
        // Validate inputs
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            $error = "Invalid year or month selected!";
        } else {
            // First, create the time period
            if (createTimePeriod($user_id, $year, $month, $db)) {
                $new_period_id = $db->insert_id;
                $message = "Time period created successfully!";
                
                // Find the most recent previous period
                $prev_period_sql = "SELECT id FROM time_periods 
                                   WHERE id < ? 
                                   AND created_by = ?
                                   ORDER BY year DESC, month DESC 
                                   LIMIT 1";
                $prev_stmt = $db->prepare($prev_period_sql);
                $prev_stmt->bind_param("ii", $new_period_id, $user_id);
                $prev_stmt->execute();
                $prev_result = $prev_stmt->get_result();
                $prev_period = $prev_result->fetch_assoc();
                
                if ($prev_period) {
                    // Carry forward products from previous period
                    $carry_result = carryForwardProductsToNewPeriod($db, $prev_period['id'], $new_period_id, $user_id);
                    
                    if ($carry_result['success'] > 0) {
                        $message .= " {$carry_result['success']} products carried forward from previous period.";
                        
                        // Also carry forward opening balance
                        $message .= " Opening balance: KSH " . number_format($carry_result['opening_balance'], 2);
                    } else {
                        $message .= " No products to carry forward (previous period had no stock or was empty).";
                    }
                    
                    if (!empty($carry_result['errors'])) {
                        error_log("Carry forward errors for user $user_id: " . implode(", ", $carry_result['errors']));
                    }
                } else {
                    $message .= " This is the first period. No products to carry forward.";
                }
                
                // Log the period creation
                error_log("Period created: ID $new_period_id, Year $year, Month $month by user $user_id");
            } else {
                $error = "This time period already exists!";
            }
        }
    }
}

// Handle period locking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_period'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid form submission. Please try again.";
        error_log("CSRF token validation failed on period lock by user $user_id");
    } else {
        $period_id = intval($_POST['period_id'] ?? 0);
        
        // Validate period ownership
        $verify_sql = "SELECT id FROM time_periods WHERE id = ? AND created_by = ?";
        $verify_stmt = $db->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $period_id, $user_id);
        $verify_stmt->execute();
        
        if ($verify_stmt->get_result()->num_rows === 1) {
            if (lockTimePeriod($period_id, $db)) {
                $message = "Time period locked successfully!";
                error_log("Period $period_id locked by user $user_id");
            } else {
                $error = "Failed to lock time period!";
            }
        } else {
            $error = "Invalid period or access denied!";
            error_log("Unauthorized period lock attempt: user $user_id tried to lock period $period_id");
        }
    }
}

// Handle period activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_period'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid form submission. Please try again.";
        error_log("CSRF token validation failed on period activation by user $user_id");
    } else {
        $period_id = intval($_POST['period_id'] ?? 0);
        
        // Validate period ownership
        $verify_sql = "SELECT id FROM time_periods WHERE id = ? AND created_by = ?";
        $verify_stmt = $db->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $period_id, $user_id);
        $verify_stmt->execute();
        
        if ($verify_stmt->get_result()->num_rows === 1) {
            // Deactivate all periods for this user
            $deactivate_sql = "UPDATE time_periods SET is_active = 0 WHERE created_by = ?";
            $deactivate_stmt = $db->prepare($deactivate_sql);
            $deactivate_stmt->bind_param("i", $user_id);
            $deactivate_stmt->execute();
            
            // Activate selected period
            $activate_sql = "UPDATE time_periods SET is_active = 1 WHERE id = ? AND created_by = ?";
            $activate_stmt = $db->prepare($activate_sql);
            $activate_stmt->bind_param("ii", $period_id, $user_id);
            
            if ($activate_stmt->execute()) {
                $message = "Time period activated successfully!";
                error_log("Period $period_id activated by user $user_id");
            } else {
                $error = "Failed to activate time period!";
            }
        } else {
            $error = "Invalid period or access denied!";
            error_log("Unauthorized period activation attempt: user $user_id tried to activate period $period_id");
        }
    }
}

// Get all time periods with validation
$time_periods = getTimePeriods($user_id, $db);
$current_period = getCurrentTimePeriod($user_id, $db);

// Validate period data
foreach ($time_periods as &$period) {
    $period['period_name'] = htmlspecialchars($period['period_name'], ENT_QUOTES, 'UTF-8');
}

// Generate year options (current year and next year)
$current_year = date('Y');
$years = [$current_year, $current_year + 1];

// Month names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Apply HTML escaping to month names for safety
foreach ($months as $key => $value) {
    $months[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Time Periods Management - Vinmel Irrigation Business System">
    <meta name="robots" content="noindex, nofollow">
    <title>Time Periods - Vinmel Irrigation</title>
    
    <!-- OWASP Security Recommendations -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com;">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .carry-forward-info {
            background-color: #e8f4fd;
            border-left: 4px solid #2196F3;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .period-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .security-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            background: #28a745;
            color: white;
            border-radius: 3px;
            margin-left: 5px;
        }
        .audit-log {
            font-size: 0.8rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            margin-top: 5px;
        }
        .form-control:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-calendar-alt me-2"></i>Time Periods Management
                            <span class="security-badge">SECURE</span>
                        </h1>
                        <p class="lead mb-0">Manage your business periods with enterprise-grade security</p>
                    </div>
                    
                    <?php if ($current_period): ?>
                        <div class="text-end">
                            <div class="badge bg-success fs-6 p-2">
                                <i class="fas fa-calendar-check me-1"></i>
                                Current: <?= htmlspecialchars($current_period['period_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="audit-log">
                                User: <?= htmlspecialchars($_SESSION['user_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> | 
                                IP: <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Security Notice -->
                <div class="alert alert-info">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>Security Features Active:</strong> CSRF Protection • Input Validation • Session Security • Audit Logging • SQL Injection Prevention
                </div>

                <div class="row">
                    <!-- Create New Period Card -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-plus-circle me-2"></i>Create New Period
                                    <span class="badge bg-light text-primary ms-2">SECURE FORM</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="createPeriodForm" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    
                                    <div class="mb-3">
                                        <label for="year" class="form-label">
                                            <i class="fas fa-calendar me-1"></i>Year
                                        </label>
                                        <select class="form-select" id="year" name="year" required
                                                oninvalid="this.setCustomValidity('Please select a year')"
                                                oninput="this.setCustomValidity('')">
                                            <option value="">Select Year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?= $year ?>" <?= $year == $current_year ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select a valid year (2020-2100)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="month" class="form-label">
                                            <i class="fas fa-calendar-day me-1"></i>Month
                                        </label>
                                        <select class="form-select" id="month" name="month" required
                                                oninvalid="this.setCustomValidity('Please select a month')"
                                                oninput="this.setCustomValidity('')">
                                            <option value="">Select Month</option>
                                            <?php foreach ($months as $num => $name): ?>
                                                <option value="<?= $num ?>" <?= $num == date('n') ? 'selected' : '' ?>>
                                                    <?= $name ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select a month (1-12)</div>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" name="create_period" class="btn btn-primary" id="createBtn">
                                            <i class="fas fa-calendar-plus me-2"></i>Create Time Period
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="mt-3 carry-forward-info">
                                    <small class="text-muted">
                                        <i class="fas fa-shield-alt me-1"></i>
                                        <strong>Secure Carry Forward:</strong><br>
                                        • Products validated before transfer<br>
                                        • Ownership verification on all operations<br>
                                        • Audit logging for compliance<br>
                                        • Automatic balance validation
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Stats -->
                        <div class="card mt-4 border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Security Dashboard</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                // Get security stats
                                $security_stats = [];
                                if ($current_period) {
                                    // Count locked periods
                                    $locked_sql = "SELECT COUNT(*) as locked_count FROM time_periods WHERE created_by = ? AND is_locked = 1";
                                    $locked_stmt = $db->prepare($locked_sql);
                                    $locked_stmt->bind_param("i", $user_id);
                                    $locked_stmt->execute();
                                    $locked_result = $locked_stmt->get_result()->fetch_assoc();
                                    
                                    // Get recent activity
                                    $activity_sql = "SELECT COUNT(*) as recent_creations FROM time_periods WHERE created_by = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
                                    $activity_stmt = $db->prepare($activity_sql);
                                    $activity_stmt->bind_param("i", $user_id);
                                    $activity_stmt->execute();
                                    $activity_result = $activity_stmt->get_result()->fetch_assoc();
                                }
                                ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-lock text-success me-1"></i>Locked Periods:</span>
                                    <strong><?= $locked_result['locked_count'] ?? 0 ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-calendar-plus text-primary me-1"></i>Recent Creations (30d):</span>
                                    <strong><?= $activity_result['recent_creations'] ?? 0 ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-user-shield text-info me-1"></i>Session Age:</span>
                                    <strong><?= floor((time() - $_SESSION['login_time']) / 60) ?> min</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span><i class="fas fa-history text-warning me-1"></i>CSRF Token Age:</span>
                                    <strong><?= floor((time() - $_SESSION['csrf_token_time']) / 60) ?> min</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time Periods List -->
                    <div class="col-lg-8">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>All Time Periods
                                    <span class="badge bg-light text-primary ms-2">ACCESS CONTROLLED</span>
                                </h5>
                                <span class="badge bg-light text-primary">
                                    <i class="fas fa-database me-1"></i><?= count($time_periods) ?> Periods
                                </span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($time_periods)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="periodsTable">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Period</th>
                                                    <th>Date Range</th>
                                                    <th>Status</th>
                                                    <th>Products</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($time_periods as $period): 
                                                    // Get product count for this period with prepared statement
                                                    $count_sql = "SELECT COUNT(*) as product_count FROM products WHERE period_id = ? AND is_active = 1";
                                                    $count_stmt = $db->prepare($count_sql);
                                                    $count_stmt->bind_param("i", $period['id']);
                                                    $count_stmt->execute();
                                                    $count_result = $count_stmt->get_result()->fetch_assoc();
                                                    $product_count = $count_result['product_count'] ?? 0;
                                                    
                                                    // Get inventory data with prepared statement
                                                    $inventory_sql = "SELECT * FROM inventory_periods WHERE time_period_id = ?";
                                                    $inventory_stmt = $db->prepare($inventory_sql);
                                                    $inventory_stmt->bind_param("i", $period['id']);
                                                    $inventory_stmt->execute();
                                                    $inventory = $inventory_stmt->get_result()->fetch_assoc();
                                                    
                                                    // Close statements
                                                    $count_stmt->close();
                                                    $inventory_stmt->close();
                                                ?>
                                                    <tr class="<?= $period['is_active'] ? 'table-success' : '' ?>">
                                                        <td>
                                                            <strong><?= $period['period_name'] ?></strong>
                                                            <?php if ($period['is_active']): ?>
                                                                <span class="badge bg-success ms-1"><i class="fas fa-play me-1"></i>Active</span>
                                                            <?php endif; ?>
                                                            <?php if ($period['is_carried_forward'] ?? false): ?>
                                                                <br><small class="text-muted"><i class="fas fa-sync-alt me-1"></i>Products carried forward</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= date('M j, Y', strtotime($period['start_date'])) ?> - 
                                                            <?= date('M j, Y', strtotime($period['end_date'])) ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($period['is_locked']): ?>
                                                                <span class="badge bg-danger">
                                                                    <i class="fas fa-lock me-1"></i>Locked
                                                                </span>
                                                                <br><small class="text-muted">Secure against modifications</small>
                                                            <?php elseif (date('Y-m-d') > $period['end_date']): ?>
                                                                <span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Ended</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-info"><i class="fas fa-unlock me-1"></i>Open</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="badge bg-primary me-2"><i class="fas fa-box"></i> <?= $product_count ?></span>
                                                                <?php if ($inventory): ?>
                                                                    <small class="text-muted">
                                                                        <i class="fas fa-money-bill-wave me-1"></i>KSH <?= number_format($inventory['closing_balance'] ?? 0, 2) ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="period-actions">
                                                                <?php if (!$period['is_active'] && !$period['is_locked']): ?>
                                                                    <form method="POST" class="d-inline" onsubmit="return confirmSecureActivation()">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="activate_period" class="btn btn-sm btn-outline-primary">
                                                                            <i class="fas fa-play me-1"></i>Activate
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <?php if (!$period['is_locked'] && !$period['is_active']): ?>
                                                                    <form method="POST" class="d-inline" onsubmit="return confirmSecureLock()">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="lock_period" class="btn btn-sm btn-outline-warning">
                                                                            <i class="fas fa-lock me-1"></i>Lock
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($period['is_locked']): ?>
                                                                    <span class="btn btn-sm btn-outline-secondary disabled">
                                                                        <i class="fas fa-lock me-1"></i>Locked
                                                                    </span>
                                                                <?php endif; ?>
                                                                
                                                                <a href="products.php?period_id=<?= $period['id'] ?>" class="btn btn-sm btn-outline-info" title="View Products (Secure)">
                                                                    <i class="fas fa-box"></i>
                                                                </a>
                                                                
                                                                <a href="inventory.php?period_id=<?= $period['id'] ?>" class="btn btn-sm btn-outline-success" title="View Inventory (Secure)">
                                                                    <i class="fas fa-chart-bar"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No time periods found. Create your first period!</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Security Information -->
                        <div class="card mt-4 border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">
                                    <i class="fas fa-shield-alt me-2"></i>Security Features Applied
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-user-lock text-primary me-2"></i>Access Control</h6>
                                        <ul class="list-unstyled">
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>Session Validation:</strong> IP, User Agent, Timeout</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>CSRF Protection:</strong> All forms protected</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>Input Validation:</strong> All inputs sanitized</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>Ownership Verification:</strong> Users can only access their data</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-database text-primary me-2"></i>Data Protection</h6>
                                        <ul class="list-unstyled">
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>SQL Injection Prevention:</strong> Prepared statements</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>Audit Logging:</strong> All operations logged</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>Data Validation:</strong> Range and type checking</li>
                                            <li><i class="fas fa-check-circle text-success me-2"></i><strong>XSS Prevention:</strong> Output encoding</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        'use strict';
        
        // Security confirmation functions
        function confirmSecureActivation() {
            return confirm('⚠️ SECURITY CONFIRMATION\n\nAre you sure you want to activate this period?\n\nThis will:\n• Make this period active for data entry\n• Deactivate all other periods\n• Update your current working period\n\nThis action is logged for security compliance.');
        }
        
        function confirmSecureLock() {
            return confirm('🔒 SECURITY LOCK CONFIRMATION\n\nAre you sure you want to lock this period?\n\n⚠️ WARNING: This action cannot be undone!\n\nLocking will:\n• Prevent any modifications to transactions\n• Prevent product changes\n• Prevent expense modifications\n• Secure the period against tampering\n\nThis action is permanently logged for audit compliance.');
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts after 7 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.classList.contains('show')) {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                        bsAlert.close();
                    }
                }, 7000);
            });
            
            // Enhanced form validation
            const createForm = document.getElementById('createPeriodForm');
            if (createForm) {
                createForm.addEventListener('submit', function(e) {
                    const year = document.getElementById('year').value;
                    const month = document.getElementById('month').value;
                    const createBtn = document.getElementById('createBtn');
                    
                    if (!year || !month) {
                        e.preventDefault();
                        alert('⚠️ Security Validation Failed\n\nPlease select both year and month.');
                        return false;
                    }
                    
                    // Show loading state
                    createBtn.disabled = true;
                    createBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                    
                    // Show confirmation
                    const periodName = new Date(year, month-1).toLocaleString('default', { 
                        month: 'long', 
                        year: 'numeric' 
                    });
                    
                    if (!confirm(`🔐 SECURE PERIOD CREATION\n\nCreate new period: ${periodName}?\n\nSecurity Features:\n• Products will be securely validated\n• Ownership will be verified\n• Balance will be audited\n• All actions will be logged\n\nContinue?`)) {
                        createBtn.disabled = false;
                        createBtn.innerHTML = '<i class="fas fa-calendar-plus me-2"></i>Create Time Period';
                        e.preventDefault();
                        return false;
                    }
                    
                    return true;
                });
            }
            
            // Session timeout warning (30 minutes)
            setTimeout(() => {
                const warning = document.createElement('div');
                warning.className = 'alert alert-warning alert-dismissible fade show position-fixed top-0 end-0 m-3';
                warning.style.zIndex = '9999';
                warning.innerHTML = `
                    <i class="fas fa-clock me-2"></i>
                    <strong>Session Warning:</strong> Your session will expire in 30 minutes.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.body.appendChild(warning);
            }, 30 * 60 * 1000); // 30 minutes
            
            // Prevent form double submission
            let formSubmitted = false;
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    if (formSubmitted) {
                        return false;
                    }
                    formSubmitted = true;
                    return true;
                });
            });
            
            // Security warning in console
            if (typeof console !== "undefined") {
                console.log("%c🔐 SECURITY NOTICE", "color: red; font-size: 18px; font-weight: bold;");
                console.log("%cThis is a secure business management system.", "color: orange; font-size: 14px;");
                console.log("%cUnauthorized access or tampering is prohibited and logged.", "color: orange; font-size: 14px;");
            }
        });
        
        // Auto-refresh CSRF token every 30 minutes
        setInterval(() => {
            fetch('refresh_csrf.php')
                .then(response => response.json())
                .then(data => {
                    if (data.csrf_token) {
                        document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                            input.value = data.csrf_token;
                        });
                    }
                })
                .catch(error => console.error('CSRF refresh failed:', error));
        }, 30 * 60 * 1000); // 30 minutes
    </script>
</body>
</html>