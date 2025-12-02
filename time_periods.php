<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php'; // Include central functions
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSeller() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

if (!isLoggedIn() || !isSeller()) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';

/* -------------------------------------------------------
   HANDLE FORM SUBMISSIONS
-------------------------------------------------------- */

// Check and auto-create periods if needed
checkAndCreateNewPeriod($user_id, $db);

// Handle new period creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_period'])) {
    $year = intval($_POST['year']);
    $month = intval($_POST['month']);
    
    if ($year >= date('Y') && $month >= 1 && $month <= 12) {
        if (createTimePeriod($user_id, $year, $month, $db)) {
            $message = "Time period created successfully!";
        } else {
            $error = "This time period already exists!";
        }
    } else {
        $error = "Invalid year or month selected!";
    }
}

// Handle period locking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_period'])) {
    $period_id = intval($_POST['period_id']);
    
    if (lockTimePeriod($period_id, $db)) {
        $message = "Time period locked successfully!";
    } else {
        $error = "Failed to lock time period!";
    }
}

// Handle period activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_period'])) {
    $period_id = intval($_POST['period_id']);
    
    // Deactivate all periods
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
    } else {
        $error = "Failed to activate time period!";
    }
}

// Get all time periods
$time_periods = getTimePeriods($user_id, $db);
$current_period = getCurrentTimePeriod($user_id, $db);

// Generate year options (current year and next year)
$current_year = date('Y');
$years = [$current_year, $current_year + 1];

// Month names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Periods - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
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
                        <h1 class="h2">Time Periods Management</h1>
                        <p class="lead mb-0">Manage your business periods and data locking</p>
                    </div>
                    
                    <?php if ($current_period): ?>
                        <div class="text-end">
                            <div class="badge bg-success fs-6 p-2">
                                <i class="fas fa-calendar-check me-1"></i>
                                Current: <?= $current_period['period_name'] ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Create New Period Card -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-plus-circle me-2"></i>Create New Period
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="year" class="form-label">Year</label>
                                        <select class="form-select" id="year" name="year" required>
                                            <option value="">Select Year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?= $year ?>" <?= $year == $current_year ? 'selected' : '' ?>>
                                                    <?= $year ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="month" class="form-label">Month</label>
                                        <select class="form-select" id="month" name="month" required>
                                            <option value="">Select Month</option>
                                            <?php foreach ($months as $num => $name): ?>
                                                <option value="<?= $num ?>" <?= $num == date('n') ? 'selected' : '' ?>>
                                                    <?= $name ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" name="create_period" class="btn btn-primary">
                                            <i class="fas fa-calendar-plus me-2"></i>Create Time Period
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="mt-3 p-3 bg-light rounded">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        The system automatically locks periods when they end and creates new ones.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time Periods List -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>All Time Periods
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($time_periods)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Period</th>
                                                    <th>Date Range</th>
                                                    <th>Status</th>
                                                    <th>Locked</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($time_periods as $period): ?>
                                                    <tr class="<?= $period['is_active'] ? 'table-success' : '' ?>">
                                                        <td>
                                                            <strong><?= htmlspecialchars($period['period_name']) ?></strong>
                                                            <?php if ($period['is_active']): ?>
                                                                <span class="badge bg-success ms-1">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= date('M j, Y', strtotime($period['start_date'])) ?> - 
                                                            <?= date('M j, Y', strtotime($period['end_date'])) ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($period['is_locked']): ?>
                                                                <span class="badge bg-danger">Locked</span>
                                                            <?php elseif (date('Y-m-d') > $period['end_date']): ?>
                                                                <span class="badge bg-warning">Ended</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-info">Open</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($period['is_locked']): ?>
                                                                <small class="text-muted">
                                                                    <?= date('M j, Y g:i A', strtotime($period['locked_at'])) ?>
                                                                </small>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <?php if (!$period['is_active'] && !$period['is_locked']): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="activate_period" class="btn btn-outline-primary">
                                                                            <i class="fas fa-play me-1"></i>Activate
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <?php if (!$period['is_locked'] && !$period['is_active']): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="lock_period" class="btn btn-outline-warning" 
                                                                                onclick="return confirm('Are you sure you want to lock this period? This will prevent any modifications.')">
                                                                            <i class="fas fa-lock me-1"></i>Lock
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($period['is_locked']): ?>
                                                                    <span class="btn btn-outline-secondary disabled">
                                                                        <i class="fas fa-lock me-1"></i>Locked
                                                                    </span>
                                                                <?php endif; ?>
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
                        
                        <!-- System Information -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-cog me-2"></i>System Information
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Automatic Period Management</h6>
                                        <ul class="list-unstyled">
                                            <li><i class="fas fa-check text-success me-2"></i>Auto-lock when period ends</li>
                                            <li><i class="fas fa-check text-success me-2"></i>Auto-create new periods</li>
                                            <li><i class="fas fa-check text-success me-2"></i>Data protection for locked periods</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Current Status</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Today's Date:</strong> <?= date('F j, Y') ?></li>
                                            <li><strong>Active Periods:</strong> <?= count(array_filter($time_periods, fn($p) => $p['is_active'])) ?></li>
                                            <li><strong>Locked Periods:</strong> <?= count(array_filter($time_periods, fn($p) => $p['is_locked'])) ?></li>
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
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>