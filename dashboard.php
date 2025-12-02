<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'period_security.php';
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSeller() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

if (!isLoggedIn() || !isSeller()) {
    header("Location:login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Initialize variables with default values to prevent undefined errors
$today_sales = ['total' => 0];
$monthly_sales = ['total' => 0];
$low_stock = ['count' => 0];
$recent_activities = [];



/* -------------------------------------------------------
   TODAY'S SALES (MySQLi) - WITH ERROR HANDLING
-------------------------------------------------------- */
try {
    $sql = "SELECT COALESCE(SUM(net_amount), 0) AS total 
            FROM transactions 
            WHERE user_id = ? 
            AND DATE(transaction_date) = CURDATE()";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $today_sales = $result->fetch_assoc();
        if (!$today_sales) {
            $today_sales = ['total' => 0];
        }
    }
} catch (Exception $e) {
    error_log("Today's sales query error: " . $e->getMessage());
    $today_sales = ['total' => 0];
}

/* -------------------------------------------------------
   MONTHLY SALES - WITH ERROR HANDLING
-------------------------------------------------------- */
try {
    $sql = "SELECT COALESCE(SUM(net_amount), 0) AS total 
            FROM transactions 
            WHERE user_id = ? 
            AND MONTH(transaction_date) = MONTH(CURDATE()) 
            AND YEAR(transaction_date) = YEAR(CURDATE())";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $monthly_sales = $result->fetch_assoc();
        if (!$monthly_sales) {
            $monthly_sales = ['total' => 0];
        }
    }
} catch (Exception $e) {
    error_log("Monthly sales query error: " . $e->getMessage());
    $monthly_sales = ['total' => 0];
}

/* -------------------------------------------------------
   LOW STOCK - WITH ERROR HANDLING
-------------------------------------------------------- */
try {
    $sql = "SELECT COUNT(*) AS count 
            FROM products 
            WHERE stock_quantity <= min_stock 
            AND created_by = ?";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $low_stock = $result->fetch_assoc();
        if (!$low_stock) {
            $low_stock = ['count' => 0];
        }
    }
} catch (Exception $e) {
    error_log("Low stock query error: " . $e->getMessage());
    $low_stock = ['count' => 0];
}

/* -------------------------------------------------------
   RECENT ACTIVITIES - WITH ERROR HANDLING
-------------------------------------------------------- */
try {
    $sql = "SELECT receipt_number, net_amount, transaction_date 
            FROM transactions 
            WHERE user_id = ? 
            ORDER BY transaction_date DESC 
            LIMIT 5";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $recent_activities = $result->fetch_all(MYSQLI_ASSOC);
        if (!$recent_activities) {
            $recent_activities = [];
        }
    }
} catch (Exception $e) {
    error_log("Recent activities query error: " . $e->getMessage());
    $recent_activities = [];
}

// Debug: Check if queries are working
// error_log("Today sales: " . $today_sales['total']);
// error_log("Monthly sales: " . $monthly_sales['total']);
// error_log("Low stock: " . $low_stock['count']);
// error_log("Recent activities count: " . count($recent_activities));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <!-- NEW STRUCTURE: Navigation First -->
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <!-- Header Inside Main Content -->
        <?php include 'header.php'; ?>

        <!-- Your Dashboard Content -->
        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="h2">Seller Dashboard</h1>
                        <p class="lead mb-0">Welcome back! Here's your business overview.</p>
                    </div>

                    <div>
                        <a href="pos.php" class="btn btn-success">
                            <i class="fas fa-cash-register me-2"></i>New Sale
                        </a>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card card-primary h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Today's Sales</h5>
                                        <h2 class="display-6 fw-bold">KSH <?= number_format($today_sales['total'], 2) ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-primary">
                                        <i class="fas fa-shopping-cart fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Total sales for today</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-success h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Monthly Total</h5>
                                        <h2 class="display-6 fw-bold">KSH <?= number_format($monthly_sales['total'], 2) ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-success">
                                        <i class="fas fa-chart-line fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Current month revenue</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-warning h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Stock Alerts</h5>
                                        <h2 class="display-6 fw-bold"><?= $low_stock['count'] ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-warning">
                                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Products needing restock</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-info h-100">
                            <div class="card-body">
                                <h5 class="card-title">Quick Actions</h5>
                                <div class="d-grid gap-2">
                                    <a href="products.php" class="btn quick-action-btn">
                                        <i class="fas fa-boxes me-2"></i>Add Products
                                    </a>
                                    <a href="pos.php" class="btn quick-action-btn">
                                        <i class="fas fa-cash-register me-2"></i>New Sale
                                    </a>
                                    <a href="inventory.php" class="btn quick-action-btn">
                                        <i class="fas fa-warehouse me-2"></i>View Inventory
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header bg-white border-bottom-0">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>Recent Activities
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_activities)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_activities as $activity): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center border-0 py-3">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <i class="fas fa-receipt me-2"></i>Sale: <?= htmlspecialchars($activity['receipt_number']) ?>
                                                    </h6>
                                                    <p class="mb-0 text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?= date("M j, g:i A", strtotime($activity['transaction_date'])) ?>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <span class="fw-bold">
                                                        KSH <?= number_format($activity['net_amount'], 2) ?>
                                                    </span>
                                                    <div class="mt-1">
                                                        <span class="badge bg-success">Completed</span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No recent activities found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>