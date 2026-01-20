<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'security.php';

// Check authentication
checkAuth();

// Initialize session
initSecureSession();

// Generate CSRF token
$csrf_token = generateCSRFToken();

$database = new Database();
$db = $database->getConnection();

// Get current user ID
$user_id = $_SESSION['user_id'];

// Rate limiting for form submissions
$rateLimitKey = "expense_form_" . $user_id;
if (!checkRateLimit($rateLimitKey, 10, 60)) {
    $_SESSION['error_message'] = "Too many requests. Please wait a moment before trying again.";
    securityLog("Rate limit exceeded for expense form submissions", "WARNING", $user_id);
    header('Location: expenses.php');
    exit();
}

// Fetch all time periods for the dropdown
$timePeriodsSql = "SELECT id, period_name, year, month, is_active, is_locked, start_date, end_date, locked_at
                   FROM time_periods
                   ORDER BY year DESC, month DESC";
$timePeriodsResult = $db->query($timePeriodsSql);
$timePeriods = [];
if ($timePeriodsResult && $timePeriodsResult->num_rows > 0) {
    while ($row = $timePeriodsResult->fetch_assoc()) {
        $timePeriods[] = $row;
    }
}

// Determine selected time period
$selected_period_id = null;
if (isset($_GET['period_id']) && is_numeric($_GET['period_id'])) {
    $selected_period_id = (int)$_GET['period_id'];
    $valid_period = false;
    foreach ($timePeriods as $tp) {
        if ($tp['id'] == $selected_period_id) {
            $valid_period = true;
            break;
        }
    }
    if (!$valid_period) {
        $selected_period_id = null;
    }
}

if (!$selected_period_id) {
    $current_period = getCurrentTimePeriod($db);
    if ($current_period) {
        $selected_period_id = $current_period['id'];
    } elseif (!empty($timePeriods)) {
        $selected_period_id = $timePeriods[0]['id'];
    }
}

// Check if selected period is locked
$is_period_locked = false;
if ($selected_period_id) {
    foreach ($timePeriods as $tp) {
        if ($tp['id'] == $selected_period_id && $tp['is_locked']) {
            $is_period_locked = true;
            break;
        }
    }
}

// Handle form submissions with CSRF protection
$success_msg = $error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_msg = "Security token validation failed. Please try again.";
        securityLog("CSRF token validation failed for expense form", "SECURITY", $user_id);
    } else {
        if (isset($_POST['add_expense'])) {
            try {
                // Validate and sanitize inputs
                $date = sanitizeInput($_POST['date'], 'date');
                $category = sanitizeInput($_POST['category'], 'string');
                $description = sanitizeInput($_POST['description'], 'string');
                $amount = (float)sanitizeInput($_POST['amount'], 'float');
                $tax = isset($_POST['tax']) ? (float)sanitizeInput($_POST['tax'], 'float') : 0.00;
                $fees = isset($_POST['fees']) ? (float)sanitizeInput($_POST['fees'], 'float') : 0.00;
                $notes = sanitizeInput($_POST['notes'] ?? '', 'string');
                $time_period_id = (int)sanitizeInput($_POST['time_period_id'], 'int');
                
                // Calculate net amount
                $net_amount = $amount + $tax + $fees;
                
                // Validation rules
                $validationRules = [
                    'date' => 'required|date',
                    'category' => 'required|min:2|max:100',
                    'description' => 'required|min:2|max:500',
                    'amount' => 'required|numeric|min:0.01',
                    'tax' => 'numeric|min:0',
                    'fees' => 'numeric|min:0'
                ];
                
                $validationErrors = validateInput($_POST, $validationRules);
                
                if (!empty($validationErrors)) {
                    $error_msg = implode('<br>', $validationErrors);
                } else {
                    // Check if period is locked (simplified check)
                    if ($is_period_locked) {
                        $error_msg = "This period is locked. You cannot add new expenses.";
                    } else {
                        // Insert expense using prepared statement with net_amount
                        $sql = "INSERT INTO expenses (date, category, description, amount, tax, fees, net_amount, notes, created_by, time_period_id, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        
                        $stmt = prepareStatement($db, $sql, [
                            $date, $category, $description, $amount, $tax, $fees, $net_amount, $notes, 
                            $user_id, $time_period_id
                        ], "sssddddssi");
                        
                        if ($stmt && $stmt->execute()) {
                            $success_msg = "Expense added successfully!";
                            securityLog("Expense created: $description - Amount: $amount", "INFO", $user_id);
                            header('Location: expenses.php?period_id=' . $time_period_id);
                            exit();
                        } else {
                            $error_msg = "Failed to add expense: " . $db->error;
                            securityLog("Failed to create expense: " . $db->error, "ERROR", $user_id);
                        }
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error: " . $e->getMessage();
                securityLog("Expense creation error: " . $e->getMessage(), "ERROR", $user_id);
            }
        }
        
        // Handle update expense
        if (isset($_POST['update_expense'])) {
            try {
                $expense_id = (int)sanitizeInput($_POST['expense_id'], 'int');
                $date = sanitizeInput($_POST['date'], 'date');
                $category = sanitizeInput($_POST['category'], 'string');
                $description = sanitizeInput($_POST['description'], 'string');
                $amount = (float)sanitizeInput($_POST['amount'], 'float');
                $tax = isset($_POST['tax']) ? (float)sanitizeInput($_POST['tax'], 'float') : 0.00;
                $fees = isset($_POST['fees']) ? (float)sanitizeInput($_POST['fees'], 'float') : 0.00;
                $notes = sanitizeInput($_POST['notes'] ?? '', 'string');
                
                // Calculate net amount
                $net_amount = $amount + $tax + $fees;
                
                // Validation
                $validationRules = [
                    'date' => 'required|date',
                    'category' => 'required|min:2|max:100',
                    'description' => 'required|min:2|max:500',
                    'amount' => 'required|numeric|min:0.01',
                    'tax' => 'numeric|min:0',
                    'fees' => 'numeric|min:0'
                ];
                
                $validationErrors = validateInput($_POST, $validationRules);
                
                if (!empty($validationErrors)) {
                    $error_msg = implode('<br>', $validationErrors);
                } else {
                    // Check if period is locked
                    if ($is_period_locked) {
                        $error_msg = "This period is locked. You cannot edit expenses.";
                    } else {
                        // Update expense with net_amount
                        $sql = "UPDATE expenses SET date = ?, category = ?, description = ?, amount = ?, 
                                tax = ?, fees = ?, net_amount = ?, notes = ?, updated_at = NOW() 
                                WHERE id = ?";
                        
                        $stmt = prepareStatement($db, $sql, [
                            $date, $category, $description, $amount, $tax, $fees, $net_amount, $notes, $expense_id
                        ], "sssddddssi");
                        
                        if ($stmt && $stmt->execute()) {
                            $success_msg = "Expense updated successfully!";
                            securityLog("Expense updated ID: $expense_id", "INFO", $user_id);
                            header('Location: expenses.php?period_id=' . $selected_period_id);
                            exit();
                        } else {
                            $error_msg = "Failed to update expense: " . $db->error;
                            securityLog("Failed to update expense ID: $expense_id - " . $db->error, "ERROR", $user_id);
                        }
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error: " . $e->getMessage();
                securityLog("Expense update error: " . $e->getMessage(), "ERROR", $user_id);
            }
        }
        
        // Handle delete expense
        if (isset($_POST['delete_expense'])) {
            try {
                $expense_id = (int)sanitizeInput($_POST['expense_id'], 'int');
                
                if (!validateInt($expense_id, 1)) {
                    $error_msg = "Invalid expense ID";
                } else {
                    // Check if period is locked
                    if ($is_period_locked) {
                        $error_msg = "This period is locked. You cannot delete expenses.";
                    } else {
                        $sql = "DELETE FROM expenses WHERE id = ?";
                        $stmt = prepareStatement($db, $sql, [$expense_id], "i");
                        
                        if ($stmt && $stmt->execute()) {
                            $success_msg = "Expense deleted successfully!";
                            securityLog("Expense deleted ID: $expense_id", "INFO", $user_id);
                            header('Location: expenses.php?period_id=' . $selected_period_id);
                            exit();
                        } else {
                            $error_msg = "Failed to delete expense: " . $db->error;
                            securityLog("Failed to delete expense ID: $expense_id - " . $db->error, "ERROR", $user_id);
                        }
                    }
                }
            } catch (Exception $e) {
                $error_msg = "Database error: " . $e->getMessage();
                securityLog("Expense deletion error: " . $e->getMessage(), "ERROR", $user_id);
            }
        }
    }
}

// Fetch expenses for selected period with user information
$expenses = [];
if ($selected_period_id) {
    $sql = "SELECT e.*, u.name as created_by_name 
            FROM expenses e 
            LEFT JOIN users u ON e.created_by = u.id 
            WHERE e.time_period_id = ? 
            ORDER BY e.date DESC, e.created_at DESC";
    
    $stmt = prepareStatement($db, $sql, [$selected_period_id], "i");
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // Sanitize data for display
                $row['category'] = sanitizeInput($row['category'], 'string');
                $row['description'] = sanitizeInput($row['description'], 'string');
                $row['notes'] = sanitizeInput($row['notes'], 'string');
                $row['created_by_name'] = sanitizeInput($row['created_by_name'] ?? 'Unknown', 'string');
                // Calculate net amount if not in database
                if (!isset($row['net_amount']) || empty($row['net_amount'])) {
                    $row['net_amount'] = $row['amount'] + $row['tax'] + $row['fees'];
                }
                $expenses[] = $row;
            }
        }
    }
}

// Get selected period name for display
$selected_period_name = "No Period Selected";
$selected_period_details = null;
foreach ($timePeriods as $tp) {
    if ($tp['id'] == $selected_period_id) {
        $selected_period_name = $tp['period_name'];
        $selected_period_details = $tp;
        break;
    }
}

// Get current period info for display
$current_period = getCurrentTimePeriod($db);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VINMEL IRRIGATION - Expenses Dashboard</title>
    
    <!-- Font Awesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'nav_bar.php'; ?>
    
    <!-- Main Content Wrapper -->
    <div class="main-content">
        <div class="content-area">
            <div class="container-fluid">
                
                <!-- Page Header Section -->
                <section class="page-header-section">
                    <div class="content-header">
                        <div class="header-content">
                            <h1 class="content-title">
                                <i class="fas fa-receipt me-2"></i>
                                Expenses Management
                            </h1>
                            <p class="content-subtitle">Track and manage your business expenses</p>
                        </div>
                    </div>
                </section>

                <!-- Current Period Info -->
                <?php if ($current_period): ?>
                <section class="current-period-info-section">
                    <div class="current-period-info">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-2">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Current Active Period
                                </h5>
                                <p class="mb-1">
                                    <strong><?= htmlspecialchars($current_period['period_name']) ?></strong>
                                    <span class="mx-2">|</span>
                                    <?= date('M j, Y', strtotime($current_period['start_date'])) ?> - 
                                    <?= date('M j, Y', strtotime($current_period['end_date'])) ?>
                                </p>
                                <small class="opacity-75">
                                    Expenses will be added to this period by default
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($current_period['is_locked']): ?>
                                    <span class="badge bg-warning">
                                        <i class="fas fa-lock me-1"></i> Locked
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle me-1"></i> Active
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Messages Section -->
                <?php if ($success_msg || $error_msg): ?>
                <section class="messages-section">
                    <?php if ($success_msg): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= htmlspecialchars($success_msg) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= htmlspecialchars($error_msg) ?>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <!-- Filter Section -->
                <section class="filter-section">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-filter me-2"></i>
                                Filter Expenses
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="filter-group">
                                <div class="filter-item">
                                    <label for="periodSelect" class="form-label">Select Time Period</label>
                                    <select id="periodSelect" class="form-select" onchange="location = '?period_id=' + this.value;">
                                        <option value="">-- Select Period --</option>
                                        <?php foreach ($timePeriods as $tp): ?>
                                            <option value="<?= $tp['id'] ?>" <?= $tp['id'] == $selected_period_id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tp['period_name']) ?>
                                                <?php if ($tp['is_active']): ?> (Active)<?php endif; ?>
                                                <?php if ($tp['is_locked']): ?> (Locked)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-item">
                                    <?php if ($selected_period_id): ?>
                                        <button type="button" class="btn btn-primary" onclick="openAddExpenseModal()" <?php echo $is_period_locked ? 'disabled' : ''; ?>>
                                            <i class="fas fa-plus me-2"></i> Add New Expense
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Selected Period Info -->
                <?php if ($selected_period_id && $selected_period_details): ?>
                <section class="period-info-section">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <div class="period-info">
                                <div class="period-info-content">
                                    <div class="period-main-info">
                                        <h6 class="period-name">
                                            <i class="fas fa-calendar-alt me-2"></i>
                                            Current Period: <strong><?= htmlspecialchars($selected_period_name) ?></strong>
                                        </h6>
                                        <?php if ($selected_period_details['start_date'] && $selected_period_details['end_date']): ?>
                                            <p class="period-dates">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= date('M j, Y', strtotime($selected_period_details['start_date'])) ?> - 
                                                <?= date('M j, Y', strtotime($selected_period_details['end_date'])) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="period-status">
                                        <?php if ($selected_period_details['is_locked']): ?>
                                            <span class="badge bg-warning status-badge">
                                                <i class="fas fa-lock me-1"></i> Locked
                                            </span>
                                        <?php elseif ($selected_period_details['is_active']): ?>
                                            <span class="badge bg-success status-badge">
                                                <i class="fas fa-check-circle me-1"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary status-badge">
                                                <i class="fas fa-pause-circle me-1"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Expenses Summary Cards -->
                <?php if ($selected_period_id && !empty($expenses)): ?>
                <?php
                $total_amount = array_sum(array_column($expenses, 'amount'));
                $total_tax = array_sum(array_column($expenses, 'tax'));
                $total_fees = array_sum(array_column($expenses, 'fees'));
                $total_net = array_sum(array_column($expenses, 'net_amount'));
                ?>
                <section class="summary-section">
                    <div class="stats-grid">
                        <div class="stat-card primary">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value">KSH <?= number_format($total_amount, 2) ?></div>
                                <div class="stat-label">Total Amount</div>
                            </div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value">KSH <?= number_format($total_tax, 2) ?></div>
                                <div class="stat-label">Total Tax</div>
                            </div>
                        </div>
                        <div class="stat-card info">
                            <div class="stat-icon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value">KSH <?= number_format($total_fees, 2) ?></div>
                                <div class="stat-label">Total Fees</div>
                            </div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-icon">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value">KSH <?= number_format($total_net, 2) ?></div>
                                <div class="stat-label">Net Expense</div>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Expenses Table Section -->
                <section class="expenses-table-section">
                    <div class="dashboard-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title">
                                <i class="fas fa-list me-2"></i>
                                Expenses List
                            </h5>
                            <div class="search-filter">
                                <input type="text" id="searchExpenses" class="form-control search-expenses" placeholder="Search expenses...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container">
                                <table class="data-table" id="expensesTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">Tax</th>
                                            <th class="text-right">Fees</th>
                                            <th class="text-right">Net Expense</th>
                                            <th>Notes</th>
                                            <th>Added By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($expenses)): ?>
                                            <?php foreach ($expenses as $exp): 
                                                // Use net_amount from database or calculate it
                                                $net_amount = $exp['net_amount'] ?? ($exp['amount'] + $exp['tax'] + $exp['fees']);
                                            ?>
                                                <tr class="expense-row" data-search="<?= strtolower(htmlspecialchars($exp['description'] . ' ' . $exp['category'] . ' ' . $exp['notes'])) ?>">
                                                    <td class="expense-date">
                                                        <i class="fas fa-calendar text-muted me-2"></i>
                                                        <?= htmlspecialchars($exp['date']) ?>
                                                    </td>
                                                    <td class="expense-category">
                                                        <span class="badge bg-primary category-badge"><?= htmlspecialchars($exp['category']) ?></span>
                                                    </td>
                                                    <td class="expense-description"><?= htmlspecialchars($exp['description']) ?></td>
                                                    <td class="expense-amount text-right">KSH <?= number_format($exp['amount'], 2) ?></td>
                                                    <td class="expense-tax text-right">KSH <?= number_format($exp['tax'], 2) ?></td>
                                                    <td class="expense-fees text-right">KSH <?= number_format($exp['fees'], 2) ?></td>
                                                    <td class="expense-net text-right fw-bold">KSH <?= number_format($net_amount, 2) ?></td>
                                                    <td class="expense-notes">
                                                        <?php if (!empty($exp['notes'])): ?>
                                                            <span class="notes-tooltip" data-toggle="tooltip" title="<?= htmlspecialchars($exp['notes']) ?>">
                                                                <i class="fas fa-sticky-note text-info"></i>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="expense-created-by">
                                                        <small class="text-muted"><?= htmlspecialchars($exp['created_by_name']) ?></small>
                                                    </td>
                                                    <td class="expense-actions">
                                                        <div class="action-buttons">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="openEditExpenseModal(<?= $exp['id'] ?>, '<?= $exp['date'] ?>', '<?= $exp['category'] ?>', '<?= htmlspecialchars(addslashes($exp['description'])) ?>', <?= $exp['amount'] ?>, <?= $exp['tax'] ?>, <?= $exp['fees'] ?>, '<?= htmlspecialchars(addslashes($exp['notes'])) ?>')"
                                                                    <?php echo $is_period_locked ? 'disabled' : ''; ?>
                                                                    title="Edit Expense">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="openDeleteExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['description'])) ?>')"
                                                                    <?php echo $is_period_locked ? 'disabled' : ''; ?>
                                                                    title="Delete Expense">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-5">
                                                    <div class="empty-state">
                                                        <i class="fas fa-receipt fa-4x text-muted mb-3"></i>
                                                        <h5 class="text-muted">No Expenses Found</h5>
                                                        <?php if ($selected_period_id): ?>
                                                            <p class="text-muted mb-3">
                                                                No expenses recorded for <strong><?= htmlspecialchars($selected_period_name) ?></strong>.
                                                                <?php if ($is_period_locked): ?>
                                                                    <span class="text-warning d-block mt-2">
                                                                        <i class="fas fa-lock me-1"></i> This period is locked. No new entries can be added.
                                                                    </span>
                                                                <?php endif; ?>
                                                            </p>
                                                            <?php if (!$is_period_locked): ?>
                                                                <button type="button" class="btn btn-primary" onclick="openAddExpenseModal()">
                                                                    <i class="fas fa-plus me-2"></i> Add Your First Expense
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <p class="text-muted">Please select a time period from the dropdown above.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal" id="addExpenseModal">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-plus-circle me-2"></i>
                Add New Expense
            </h5>
            <button type="button" class="modal-close" onclick="closeAddExpenseModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="expense-form" id="expenseForm" onsubmit="return validateExpenseForm()">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="add_expense" value="1">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($is_period_locked): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-lock me-2"></i> 
                        The selected period (<strong><?= htmlspecialchars($selected_period_name) ?></strong>) is locked. You cannot add new expenses.
                    </div>
                <?php else: ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>" id="expenseDate" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-select" required id="expenseCategory">
                                <option value="">Select Category</option>
                                <option value="WAGES">WAGES</option>
                                <option value="RENT">RENT</option>
                                <option value="INSURANCE">INSURANCE</option>
                                <option value="INTERNET">INTERNET</option>
                                <option value="WATER BILL">WATER BILL</option>
                                <option value="ELECTRICITY BILL">ELECTRICITY BILL</option>
                                <option value="PROFESSIONAL SERVICES">PROFESSIONAL SERVICES</option>
                                <option value="LEVIS/PERMITS/OTHERS">LEVIS/PERMITS/OTHERS</option>
                                <option value="ADVERTISMENT">ADVERTISMENT</option>
                                <option value="TRANSPORT CHARGES">TRANSPORT CHARGES</option>
                                <option value="BANK CHARGES">BANK CHARGES</option>
                                <option value="POSTAL CHARGES">POSTAL CHARGES</option>
                                <option value="CARRIER SERVICES CHARGES">CARRIER SERVICES CHARGES</option>
                                <option value="STAFF UNIFORM">STAFF UNIFORM</option>
                                <option value="TOOL/EQUIPMENT MAINTENANCE FEE">TOOL/EQUIPMENT MAINTENANCE FEE</option>
                                <option value="FUEL/OILS">FUEL/OILS</option>
                                <option value="OFFICE/TEA/HOSPITALITY">OFFICE/TEA/HOSPITALITY</option>
                                <option value="LOGISTICS/HIRE/LEASE">LOGISTICS/HIRE/LEASE</option>
                                <option value="OFFICE">OFFICE</option>
                                <option value="WELFARE/CORPORATE SOCIAL RESPONSIBILITY">WELFARE/CORPORATE SOCIAL RESPONSIBILITY</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., January Rent" required id="expenseDescription" maxlength="500">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Amount (KSH) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required min="0.01" id="expenseAmount">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tax (KSH)</label>
                            <input type="number" step="0.01" name="tax" class="form-control" placeholder="0.00" value="0.00" min="0" id="expenseTax">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fees (KSH)</label>
                            <input type="number" step="0.01" name="fees" class="form-control" placeholder="0.00" value="0.00" min="0" id="expenseFees">
                        </div>
                    </div>

                    <div class="form-group net-amount-group">
                        <label class="form-label">Net Amount (KSH)</label>
                        <div class="net-amount-display" id="netAmountDisplay">KSH 0.00</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add any additional notes..." id="expenseNotes" maxlength="1000"></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeAddExpenseModal()">Cancel</button>
                <?php if (!$is_period_locked): ?>
                    <button type="submit" class="btn btn-primary" id="submitExpense">
                        <i class="fas fa-save me-2"></i> Save Expense
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled>
                        <i class="fas fa-lock me-2"></i> Period Locked
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Edit Expense Modal -->
    <div class="modal" id="editExpenseModal">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-edit me-2"></i>
                Edit Expense
            </h5>
            <button type="button" class="modal-close" onclick="closeEditExpenseModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="expense-form" id="editExpenseForm" onsubmit="return validateEditExpenseForm()">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="update_expense" value="1">
            <input type="hidden" name="expense_id" id="editExpenseId">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($is_period_locked): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-lock me-2"></i> 
                        The selected period (<strong><?= htmlspecialchars($selected_period_name) ?></strong>) is locked. You cannot edit expenses.
                    </div>
                <?php else: ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <input type="date" name="date" class="form-control" required id="editExpenseDate" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-select" required id="editExpenseCategory">
                                <option value="">Select Category</option>
                                <option value="WAGES">WAGES</option>
                                <option value="RENT">RENT</option>
                                <option value="INSURANCE">INSURANCE</option>
                                <option value="INTERNET">INTERNET</option>
                                <option value="WATER BILL">WATER BILL</option>
                                <option value="ELECTRICITY BILL">ELECTRICITY BILL</option>
                                <option value="PROFESSIONAL SERVICES">PROFESSIONAL SERVICES</option>
                                <option value="LEVIS/PERMITS/OTHERS">LEVIS/PERMITS/OTHERS</option>
                                <option value="ADVERTISMENT">ADVERTISMENT</option>
                                <option value="TRANSPORT CHARGES">TRANSPORT CHARGES</option>
                                <option value="BANK CHARGES">BANK CHARGES</option>
                                <option value="POSTAL CHARGES">POSTAL CHARGES</option>
                                <option value="CARRIER SERVICES CHARGES">CARRIER SERVICES CHARGES</option>
                                <option value="STAFF UNIFORM">STAFF UNIFORM</option>
                                <option value="TOOL/EQUIPMENT MAINTENANCE FEE">TOOL/EQUIPMENT MAINTENANCE FEE</option>
                                <option value="FUEL/OILS">FUEL/OILS</option>
                                <option value="OFFICE/TEA/HOSPITALITY">OFFICE/TEA/HOSPITALITY</option>
                                <option value="LOGISTICS/HIRE/LEASE">LOGISTICS/HIRE/LEASE</option>
                                <option value="OFFICE">OFFICE</option>
                                <option value="WELFARE/CORPORATE SOCIAL RESPONSIBILITY">WELFARE/CORPORATE SOCIAL RESPONSIBILITY</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., January Rent" required id="editExpenseDescription" maxlength="500">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Amount (KSH) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required min="0.01" id="editExpenseAmount">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tax (KSH)</label>
                            <input type="number" step="0.01" name="tax" class="form-control" placeholder="0.00" value="0.00" min="0" id="editExpenseTax">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fees (KSH)</label>
                            <input type="number" step="0.01" name="fees" class="form-control" placeholder="0.00" value="0.00" min="0" id="editExpenseFees">
                        </div>
                    </div>

                    <div class="form-group net-amount-group">
                        <label class="form-label">Net Amount (KSH)</label>
                        <div class="net-amount-display" id="editNetAmountDisplay">KSH 0.00</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add any additional notes..." id="editExpenseNotes" maxlength="1000"></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeEditExpenseModal()">Cancel</button>
                <?php if (!$is_period_locked): ?>
                    <button type="submit" class="btn btn-primary" id="updateExpense">
                        <i class="fas fa-save me-2"></i> Update Expense
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled>
                        <i class="fas fa-lock me-2"></i> Period Locked
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Delete Expense Modal -->
    <div class="modal" id="deleteExpenseModal">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-trash-alt me-2"></i>
                Delete Expense
            </h5>
            <button type="button" class="modal-close" onclick="closeDeleteExpenseModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="expense-form" id="deleteExpenseForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="delete_expense" value="1">
            <input type="hidden" name="expense_id" id="deleteExpenseId">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($is_period_locked): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-lock me-2"></i> 
                        The selected period (<strong><?= htmlspecialchars($selected_period_name) ?></strong>) is locked. You cannot delete expenses.
                    </div>
                <?php else: ?>
                    <div class="text-center">
                        <div class="delete-icon mb-3">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning"></i>
                        </div>
                        <h6>Are you sure you want to delete this expense?</h6>
                        <p class="text-muted" id="deleteExpenseDescription"></p>
                        <p class="text-danger"><small>This action cannot be undone.</small></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeDeleteExpenseModal()">Cancel</button>
                <?php if (!$is_period_locked): ?>
                    <button type="submit" class="btn btn-danger" id="confirmDelete">
                        <i class="fas fa-trash me-2"></i> Delete Expense
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-danger" disabled>
                        <i class="fas fa-lock me-2"></i> Period Locked
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Modal Backdrop -->
    <div class="modal-backdrop" id="globalModalBackdrop" onclick="closeAllModals()"></div>

    <!-- JavaScript -->
    <script>
    // Simple modal management system
    const modals = {
        add: document.getElementById('addExpenseModal'),
        edit: document.getElementById('editExpenseModal'),
        delete: document.getElementById('deleteExpenseModal'),
        backdrop: document.getElementById('globalModalBackdrop')
    };

    // Open modal functions
    function openAddExpenseModal() {
        console.log('Opening add expense modal...');
        
        // Reset form
        const form = document.getElementById('expenseForm');
        if (form) form.reset();
        
        // Set today's date
        const dateInput = document.getElementById('expenseDate');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
        
        // Show modal and backdrop
        if (modals.add && modals.backdrop) {
            modals.add.classList.add('show');
            modals.backdrop.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Update net amount display
            updateNetAmount();
        } else {
            console.error('Modal elements not found');
            alert('Modal elements not found. Please check the console for errors.');
        }
    }

    function openEditExpenseModal(id, date, category, description, amount, tax, fees, notes) {
        console.log('Opening edit modal for expense:', id);
        
        // Populate form fields
        document.getElementById('editExpenseId').value = id;
        document.getElementById('editExpenseDate').value = date;
        document.getElementById('editExpenseCategory').value = category;
        document.getElementById('editExpenseDescription').value = description;
        document.getElementById('editExpenseAmount').value = amount;
        document.getElementById('editExpenseTax').value = tax;
        document.getElementById('editExpenseFees').value = fees;
        document.getElementById('editExpenseNotes').value = notes;
        
        // Show modal
        if (modals.edit && modals.backdrop) {
            modals.edit.classList.add('show');
            modals.backdrop.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Update net amount display
            updateEditNetAmount();
        }
    }

    function openDeleteExpenseModal(id, description) {
        console.log('Opening delete modal for expense:', id);
        
        // Populate form fields
        document.getElementById('deleteExpenseId').value = id;
        document.getElementById('deleteExpenseDescription').textContent = description;
        
        // Show modal
        if (modals.delete && modals.backdrop) {
            modals.delete.classList.add('show');
            modals.backdrop.classList.add('show');
            document.body.classList.add('modal-open');
        }
    }

    // Close modal functions
    function closeAllModals() {
        console.log('Closing all modals...');
        
        if (modals.add) modals.add.classList.remove('show');
        if (modals.edit) modals.edit.classList.remove('show');
        if (modals.delete) modals.delete.classList.remove('show');
        if (modals.backdrop) modals.backdrop.classList.remove('show');
        document.body.classList.remove('modal-open');
    }

    function closeAddExpenseModal() {
        if (modals.add) modals.add.classList.remove('show');
        if (modals.backdrop) modals.backdrop.classList.remove('show');
        document.body.classList.remove('modal-open');
    }

    function closeEditExpenseModal() {
        if (modals.edit) modals.edit.classList.remove('show');
        if (modals.backdrop) modals.backdrop.classList.remove('show');
        document.body.classList.remove('modal-open');
    }

    function closeDeleteExpenseModal() {
        if (modals.delete) modals.delete.classList.remove('show');
        if (modals.backdrop) modals.backdrop.classList.remove('show');
        document.body.classList.remove('modal-open');
    }

    // Net amount calculation
    function updateNetAmount() {
        const amount = parseFloat(document.getElementById('expenseAmount').value) || 0;
        const tax = parseFloat(document.getElementById('expenseTax').value) || 0;
        const fees = parseFloat(document.getElementById('expenseFees').value) || 0;
        const netAmount = amount + tax + fees;
        
        const display = document.getElementById('netAmountDisplay');
        if (display) {
            display.textContent = 'KSH ' + netAmount.toFixed(2);
        }
    }

    function updateEditNetAmount() {
        const amount = parseFloat(document.getElementById('editExpenseAmount').value) || 0;
        const tax = parseFloat(document.getElementById('editExpenseTax').value) || 0;
        const fees = parseFloat(document.getElementById('editExpenseFees').value) || 0;
        const netAmount = amount + tax + fees;
        
        const display = document.getElementById('editNetAmountDisplay');
        if (display) {
            display.textContent = 'KSH ' + netAmount.toFixed(2);
        }
    }

    // Form validation
    function validateExpenseForm() {
        const amount = parseFloat(document.getElementById('expenseAmount').value) || 0;
        const description = document.getElementById('expenseDescription').value.trim();
        
        if (amount <= 0) {
            alert('Please enter a valid amount greater than 0');
            document.getElementById('expenseAmount').focus();
            return false;
        }
        
        if (!description) {
            alert('Please enter a description');
            document.getElementById('expenseDescription').focus();
            return false;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitExpense');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
        }
        
        return true;
    }

    function validateEditExpenseForm() {
        const amount = parseFloat(document.getElementById('editExpenseAmount').value) || 0;
        const description = document.getElementById('editExpenseDescription').value.trim();
        
        if (amount <= 0) {
            alert('Please enter a valid amount greater than 0');
            document.getElementById('editExpenseAmount').focus();
            return false;
        }
        
        if (!description) {
            alert('Please enter a description');
            document.getElementById('editExpenseDescription').focus();
            return false;
        }
        
        const submitBtn = document.getElementById('updateExpense');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Updating...';
        }
        
        return true;
    }

    // Search functionality
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchExpenses');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('#expensesTable tbody tr');
                
                rows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
        
        // Setup amount calculation listeners
        const amountInputs = ['expenseAmount', 'expenseTax', 'expenseFees'];
        amountInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', updateNetAmount);
            }
        });
        
        const editAmountInputs = ['editExpenseAmount', 'editExpenseTax', 'editExpenseFees'];
        editAmountInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', updateEditNetAmount);
            }
        });
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });
        
        // Debug: Log modal elements
        console.log('Modal elements loaded:');
        console.log('- Add modal:', modals.add ? 'Found ✓' : 'Not found ✗');
        console.log('- Edit modal:', modals.edit ? 'Found ✓' : 'Not found ✗');
        console.log('- Delete modal:', modals.delete ? 'Found ✓' : 'Not found ✗');
        console.log('- Backdrop:', modals.backdrop ? 'Found ✓' : 'Not found ✗');
    });

    // Tooltip functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tooltips = document.querySelectorAll('.notes-tooltip');
        tooltips.forEach(tooltip => {
            tooltip.addEventListener('mouseenter', function(e) {
                const title = this.getAttribute('title');
                if (title) {
                    const tooltipEl = document.createElement('div');
                    tooltipEl.className = 'custom-tooltip';
                    tooltipEl.textContent = title;
                    document.body.appendChild(tooltipEl);
                    
                    tooltipEl.style.left = (e.pageX + 10) + 'px';
                    tooltipEl.style.top = (e.pageY - tooltipEl.offsetHeight - 10) + 'px';
                    
                    this._tooltip = tooltipEl;
                }
            });
            
            tooltip.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    this._tooltip = null;
                }
            });
            
            tooltip.addEventListener('mousemove', function(e) {
                if (this._tooltip) {
                    this._tooltip.style.left = (e.pageX + 10) + 'px';
                    this._tooltip.style.top = (e.pageY - this._tooltip.offsetHeight - 10) + 'px';
                }
            });
        });
    });
    </script>
</body>
</html>