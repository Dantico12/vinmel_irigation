<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Check if user is logged in
checkAuth();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

checkAndCreateNewPeriod($user_id, $db);

$message = '';
$error = '';

// Handle period locking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_period'])) {
    $period_id = intval($_POST['period_id']);
    
    // Lock the period
    $sql = "UPDATE time_periods SET is_locked = 1, locked_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    
    if ($stmt->execute()) {
        // AUTO-ACTIVATE: When a period is locked, auto-activate the next available period
        autoActivateRecentPeriod($db);
        $message = "Time period locked successfully! The system has automatically activated the most recent open period.";
    } else {
        $error = "Failed to lock time period!";
    }
}

// Handle period activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_period'])) {
    $period_id = intval($_POST['period_id']);
    
    // Deactivate ALL periods first
    deactivateAllPeriodsExcept($db);
    
    // Activate selected period
    $activate_sql = "UPDATE time_periods SET is_active = 1 WHERE id = ?";
    $activate_stmt = $db->prepare($activate_sql);
    $activate_stmt->bind_param("i", $period_id);
    
    if ($activate_stmt->execute()) {
        $message = "Time period activated successfully!";
    } else {
        $error = "Failed to activate time period!";
    }
}

// Handle period unlocking (ADMIN ONLY FEATURE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_period'])) {
    $period_id = intval($_POST['period_id']);
    
    // Unlock the period
    $sql = "UPDATE time_periods SET is_locked = 0, locked_at = NULL WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    
    if ($stmt->execute()) {
        // AUTO-ACTIVATE: When a period is unlocked, consider auto-activating it if it's the most recent
        autoActivateRecentPeriod($db);
        $message = "Time period unlocked successfully!";
    } else {
        $error = "Failed to unlock time period!";
    }
}

// Handle period deletion (ADMIN ONLY FEATURE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period'])) {
    $period_id = intval($_POST['period_id']);
    
    // Check if period has associated data
    $check_sql = "SELECT COUNT(*) as product_count FROM products WHERE period_id = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("i", $period_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result()->fetch_assoc();
    
    if ($result['product_count'] > 0) {
        $error = "Cannot delete period with associated products!";
    } else {
        // Check if this is the active period
        $check_active_sql = "SELECT is_active FROM time_periods WHERE id = ?";
        $check_active_stmt = $db->prepare($check_active_sql);
        $check_active_stmt->bind_param("i", $period_id);
        $check_active_stmt->execute();
        $active_result = $check_active_stmt->get_result()->fetch_assoc();
        
        $delete_sql = "DELETE FROM time_periods WHERE id = ?";
        $delete_stmt = $db->prepare($delete_sql);
        $delete_stmt->bind_param("i", $period_id);
        
        if ($delete_stmt->execute()) {
            // AUTO-ACTIVATE: If we deleted the active period, auto-activate another one
            if ($active_result && $active_result['is_active']) {
                autoActivateRecentPeriod($db);
                $message = "Time period deleted successfully! The system has automatically activated the most recent open period.";
            } else {
                $message = "Time period deleted successfully!";
            }
        } else {
            $error = "Failed to delete time period!";
        }
    }
}

// Handle bulk operations (ADMIN ONLY FEATURE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selected_periods = $_POST['selected_periods'] ?? [];
    
    if (empty($selected_periods)) {
        $error = "No periods selected for bulk action! Please select at least one period.";
    } else {
        $bulk_action = $_POST['bulk_action'];
        
        // Convert to integers for safety
        $period_ids = array_map('intval', $selected_periods);
        
        switch ($bulk_action) {
            case 'lock':
                $sql = "UPDATE time_periods SET is_locked = 1, locked_at = NOW() WHERE id IN (" . implode(',', $period_ids) . ")";
                $success_message = count($period_ids) . " period(s) locked successfully!";
                
                if ($stmt = $db->prepare($sql)) {
                    if ($stmt->execute()) {
                        // AUTO-ACTIVATE: After bulk lock, auto-activate recent period
                        autoActivateRecentPeriod($db);
                        $message = $success_message . " The system has automatically activated the most recent open period.";
                    } else {
                        $error = "Failed to perform bulk lock!";
                    }
                }
                break;
                
            case 'unlock':
                $sql = "UPDATE time_periods SET is_locked = 0, locked_at = NULL WHERE id IN (" . implode(',', $period_ids) . ")";
                $success_message = count($period_ids) . " period(s) unlocked successfully!";
                
                if ($stmt = $db->prepare($sql)) {
                    if ($stmt->execute()) {
                        // AUTO-ACTIVATE: After bulk unlock, auto-activate recent period
                        autoActivateRecentPeriod($db);
                        $message = $success_message;
                    } else {
                        $error = "Failed to perform bulk unlock!";
                    }
                }
                break;
                
            case 'activate':
                if (count($period_ids) > 1) {
                    $error = "You can only activate one period at a time! Please select only one period for activation.";
                } else {
                    $period_id = $period_ids[0];
                    deactivateAllPeriodsExcept($db);
                    
                    $sql = "UPDATE time_periods SET is_active = 1 WHERE id = " . $period_id;
                    if ($stmt = $db->prepare($sql)) {
                        if ($stmt->execute()) {
                            $message = "Period activated successfully!";
                        } else {
                            $error = "Failed to activate period!";
                        }
                    }
                }
                break;
                
            default:
                $error = "Invalid bulk action!";
                break;
        }
    }
}

// Get all time periods (ADMIN MODE - shows all periods)
$time_periods = getTimePeriods($user_id, $db);
$current_period = getCurrentTimePeriod($db);

// Auto-activate a period if none is active
$active_periods = array_filter($time_periods, fn($p) => $p['is_active']);
if (empty($active_periods) && !empty($time_periods)) {
    autoActivateRecentPeriod($db);
    // Reload periods after auto-activation
    $time_periods = getTimePeriods($user_id, $db);
    $current_period = getCurrentTimePeriod($db);
}

// DEBUG: Show what's being loaded
echo "<!-- DEBUG: Current User ID: " . $user_id . " -->";
echo "<!-- DEBUG: Time periods loaded: " . count($time_periods) . " -->";
echo "<!-- DEBUG: Active periods: " . count(array_filter($time_periods, fn($p) => $p['is_active'])) . " -->";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Time Periods - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .bulk-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .activation-note {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 8px 12px;
            margin-top: 10px;
            font-size: 0.85rem;
        }
        .period-checkbox:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .multiple-active-warning {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .auto-activation-info {
            background-color: #d1edff;
            border: 1px solid #b6d7ff;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .periods-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .periods-info-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
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
                            <i class="fas fa-cogs me-2"></i>Admin Time Periods Management
                        </h1>
                        <p class="lead mb-0">Automatic period management with smart activation</p>
                    </div>
                    
                    <?php if ($current_period): ?>
                        <div class="text-end">
                            <div class="badge bg-success fs-6 p-2">
                                <i class="fas fa-calendar-check me-1"></i>
                                Current: <?= $current_period['period_name'] ?>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-robot me-1"></i>
                                    Auto-Activation: Enabled
                                </small>
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

                <!-- Multiple Active Periods Warning -->
                <?php 
                $active_periods = array_filter($time_periods, fn($p) => $p['is_active']);
                if (count($active_periods) > 1): ?>
                    <div class="multiple-active-warning">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger me-3"></i>
                            <div>
                                <h5 class="text-danger mb-2">Multiple Active Periods Detected!</h5>
                                <p class="mb-2">There are currently <strong><?= count($active_periods) ?> periods marked as active</strong>. 
                                The system should only have ONE active period at a time.</p>
                                <p class="mb-0"><strong>Solution:</strong> The system will automatically fix this on the next action.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Info Card for Automatic Period Creation -->
                    <div class="col-lg-4 mb-4">
                        <div class="periods-info-card text-center">
                            <i class="fas fa-calendar-alt"></i>
                            <h4 class="mb-3">Automatic Period Creation</h4>
                            <p class="mb-0">
                                New time periods are automatically created for sellers when needed. 
                                This ensures a seamless workflow without manual intervention.
                            </p>
                        </div>

                        <!-- Quick Stats Card -->
                        <div class="card mt-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Period Statistics
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="metric-value text-primary"><?= count($time_periods) ?></div>
                                        <div class="metric-label">Total Periods</div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="metric-value <?= count($active_periods) == 1 ? 'text-success' : 'text-danger' ?>">
                                            <?= count($active_periods) ?>
                                        </div>
                                        <div class="metric-label">Active</div>
                                        <?php if (count($active_periods) != 1): ?>
                                            <small class="text-danger">Auto-fix enabled</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-value text-warning"><?= count(array_filter($time_periods, fn($p) => $p['is_locked'])) ?></div>
                                        <div class="metric-label">Locked</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="metric-value text-info"><?= count(array_filter($time_periods, fn($p) => !$p['is_locked'])) ?></div>
                                        <div class="metric-label">Open</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Current Active Period Card -->
                        <?php if ($current_period): ?>
                        <div class="card mt-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-star me-2"></i>Currently Active
                                </h6>
                            </div>
                            <div class="card-body">
                                <h5 class="text-success"><?= $current_period['period_name'] ?></h5>
                                <p class="mb-1"><strong>Date Range:</strong> 
                                    <?= date('M j, Y', strtotime($current_period['start_date'])) ?> - 
                                    <?= date('M j, Y', strtotime($current_period['end_date'])) ?>
                                </p>
                                <p class="mb-0"><strong>Status:</strong> 
                                    <?= $current_period['is_locked'] ? 
                                        '<span class="badge bg-danger">Locked</span>' : 
                                        '<span class="badge bg-success">Open</span>' ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Time Periods List -->
                    <div class="col-lg-8">
                        <!-- Bulk Actions Card -->
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-tasks me-2"></i>Bulk Operations
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="bulk-warning">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    <strong>Note:</strong> For activation, please select only ONE period. Multiple periods can be locked/unlocked at once.
                                </div>
                                
                                <form method="POST" id="bulkForm">
                                    <div class="row align-items-end">
                                        <div class="col-md-6">
                                            <label class="form-label">Select Action:</label>
                                            <select class="form-select" name="bulk_action" id="bulkActionSelect" required>
                                                <option value="">Choose action...</option>
                                                <option value="lock">Lock Selected Periods</option>
                                                <option value="unlock">Unlock Selected Periods</option>
                                                <option value="activate">Activate Selected Period</option>
                                            </select>
                                            <div class="activation-note" id="activationNote" style="display: none;">
                                                <i class="fas fa-info-circle text-info me-1"></i>
                                                Only one period can be active at a time
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mt-3">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                                <label class="form-check-label" for="selectAll">
                                                    Select All Periods
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-warning w-100">
                                                <i class="fas fa-play me-1"></i>Execute
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Hidden container for selected periods -->
                                    <div id="selectedPeriodsContainer"></div>
                                </form>
                            </div>
                        </div>

                        <!-- Periods Table Card -->
                        <div class="card">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>All Time Periods (Admin View)
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($time_periods)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="periodsTable">
                                            <thead>
                                                <tr>
                                                    <th width="30">
                                                        <input type="checkbox" id="selectAllTable">
                                                    </th>
                                                    <th>Period</th>
                                                    <th>Date Range</th>
                                                    <th>Status</th>
                                                    <th>Locked</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($time_periods as $period): ?>
                                                    <tr class="<?= $period['is_active'] ? 'table-success' : '' ?>" data-period-id="<?= $period['id'] ?>">
                                                        <td>
                                                            <input type="checkbox" class="period-checkbox form-check-input" 
                                                                   value="<?= $period['id'] ?>">
                                                        </td>
                                                        <td>
                                                            <strong><?= htmlspecialchars($period['period_name']) ?></strong>
                                                            <?php if ($period['is_active']): ?>
                                                                <span class="badge bg-success ms-1">
                                                                    <i class="fas fa-star me-1"></i>Active
                                                                </span>
                                                            <?php endif; ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                Created by: <?= htmlspecialchars($period['creator_name'] ?? 'User ' . $period['created_by']) ?>
                                                            </small>
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
                                                                    <?= $period['locked_at'] ? date('M j, Y g:i A', strtotime($period['locked_at'])) : 'Unknown' ?>
                                                                </small>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <?php if (!$period['is_active']): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="activate_period" class="btn btn-outline-primary">
                                                                            <i class="fas fa-play me-1"></i>Activate
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="btn btn-success btn-sm disabled">
                                                                        <i class="fas fa-check me-1"></i>Active
                                                                    </span>
                                                                <?php endif; ?>
                                                                
                                                                <?php if (!$period['is_locked']): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="lock_period" class="btn btn-outline-warning" 
                                                                                onclick="return confirm('Locking this period will automatically activate the next available period. Continue?')">
                                                                            <i class="fas fa-lock me-1"></i>Lock
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <!-- ADMIN UNLOCK FEATURE -->
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="unlock_period" class="btn btn-outline-success" 
                                                                                onclick="return confirm('ADMIN: Unlock this period?')">
                                                                            <i class="fas fa-unlock me-1"></i>Unlock
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <!-- ADMIN DELETE FEATURE -->
                                                                <?php if (!$period['is_active']): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                                        <button type="submit" name="delete_period" class="btn btn-outline-danger" 
                                                                                onclick="return confirm('ADMIN: Delete this period? If active, another period will be auto-activated.')">
                                                                            <i class="fas fa-trash me-1"></i>Delete
                                                                        </button>
                                                                    </form>
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
                                        <p class="text-muted">No time periods found.</p>
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            New periods will be automatically created when needed by sellers.
                                        </div>
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

            // Bulk selection functionality
            const selectAll = document.getElementById('selectAll');
            const selectAllTable = document.getElementById('selectAllTable');
            const periodCheckboxes = document.querySelectorAll('.period-checkbox');
            const bulkActionSelect = document.getElementById('bulkActionSelect');
            const activationNote = document.getElementById('activationNote');
            const bulkForm = document.getElementById('bulkForm');
            const selectedPeriodsContainer = document.getElementById('selectedPeriodsContainer');

            // Show/hide activation note
            if (bulkActionSelect && activationNote) {
                bulkActionSelect.addEventListener('change', function() {
                    if (this.value === 'activate') {
                        activationNote.style.display = 'block';
                    } else {
                        activationNote.style.display = 'none';
                    }
                });
            }

            function updateSelectAll() {
                const allChecked = Array.from(periodCheckboxes).every(cb => cb.checked);
                const anyChecked = Array.from(periodCheckboxes).some(cb => cb.checked);
                
                if (selectAll) selectAll.checked = allChecked;
                if (selectAllTable) selectAllTable.checked = allChecked;
                
                // Update select all text
                if (selectAll) {
                    const label = selectAll.nextElementSibling;
                    if (label) {
                        label.textContent = anyChecked ? 'Deselect All' : 'Select All Periods';
                    }
                }
            }

            function toggleAll(checked) {
                periodCheckboxes.forEach(cb => {
                    cb.checked = checked;
                });
                updateSelectAll();
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    toggleAll(this.checked);
                });
            }

            if (selectAllTable) {
                selectAllTable.addEventListener('change', function() {
                    toggleAll(this.checked);
                });
            }

            periodCheckboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectAll);
            });

            // Bulk form submission - dynamically add hidden inputs for selected periods
            if (bulkForm && selectedPeriodsContainer) {
                bulkForm.addEventListener('submit', function(e) {
                    // Clear previous hidden inputs
                    selectedPeriodsContainer.innerHTML = '';
                    
                    // Get selected checkboxes
                    const selectedCheckboxes = Array.from(periodCheckboxes).filter(cb => cb.checked);
                    
                    if (selectedCheckboxes.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one period for bulk action.');
                        return;
                    }
                    
                    // Add hidden inputs for each selected period
                    selectedCheckboxes.forEach(checkbox => {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'selected_periods[]';
                        hiddenInput.value = checkbox.value;
                        selectedPeriodsContainer.appendChild(hiddenInput);
                    });
                    
                    const action = this.bulk_action.value;
                    const selectedCount = selectedCheckboxes.length;
                    
                    // Special validation for activation
                    if (action === 'activate' && selectedCount > 1) {
                        e.preventDefault();
                        alert('For activation, please select only ONE period. You currently have ' + selectedCount + ' periods selected.');
                        return;
                    }
                    
                    const actionText = {
                        'lock': 'lock',
                        'unlock': 'unlock',
                        'activate': 'activate'
                    }[action];
                    
                    const periodText = selectedCount === 1 ? 'period' : 'periods';
                    
                    if (!confirm(`ADMIN: Are you sure you want to ${actionText} ${selectedCount} ${periodText}?`)) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>