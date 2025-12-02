<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Check authentication
checkAuth();

$database = new Database();
$db = $database->getConnection();
$expenseObj = new Expense($db);

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
    // Try to get current active period using our new function
    $current_period = getCurrentTimePeriod($db);
    if ($current_period) {
        $selected_period_id = $current_period['id'];
    } elseif (!empty($timePeriods)) {
        $selected_period_id = $timePeriods[0]['id'];
    }
}

// Handle form submissions
$success_msg = $error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        try {
            $expense = new Expense($db);
            $expense->date = $_POST['date'];
            $expense->category = $_POST['category'];
            $expense->description = $_POST['description'];
            $expense->amount = (float)$_POST['amount'];
            $expense->tax = isset($_POST['tax']) ? (float)$_POST['tax'] : 0.00;
            $expense->fees = isset($_POST['fees']) ? (float)$_POST['fees'] : 0.00;
            $expense->notes = $_POST['notes'] ?? '';
            $expense->created_by = $_SESSION['user_id'];
            $expense->time_period_id = (int)$_POST['time_period_id'];

            if ($expense->time_period_id != $selected_period_id) {
                $error_msg = "Invalid Time Period ID submitted.";
            } else {
                if ($expense->create()) {
                    $success_msg = "Expense added successfully!";
                    echo '<script>window.location.href = "expenses.php?period_id=' . $expense->time_period_id . '";</script>';
                    exit();
                } else {
                    $error_msg = "Failed to add expense. Please check the data.";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    }
    
    // Handle update expense
    if (isset($_POST['update_expense'])) {
        try {
            $expense_id = (int)$_POST['expense_id'];
            $expense = new Expense($db);
            
            // Check if expense exists and belongs to current period
            $existing_expense = $expense->getExpenseById($expense_id);
            if (!$existing_expense) {
                $error_msg = "Expense not found.";
            } elseif ($existing_expense['time_period_id'] != $selected_period_id) {
                $error_msg = "Expense does not belong to the current period.";
            } else {
                $expense->id = $expense_id;
                $expense->date = $_POST['date'];
                $expense->category = $_POST['category'];
                $expense->description = $_POST['description'];
                $expense->amount = (float)$_POST['amount'];
                $expense->tax = isset($_POST['tax']) ? (float)$_POST['tax'] : 0.00;
                $expense->fees = isset($_POST['fees']) ? (float)$_POST['fees'] : 0.00;
                $expense->notes = $_POST['notes'] ?? '';
                
                if ($expense->update()) {
                    $success_msg = "Expense updated successfully!";
                    echo '<script>window.location.href = "expenses.php?period_id=' . $selected_period_id . '";</script>';
                    exit();
                } else {
                    $error_msg = "Failed to update expense. Please check the data.";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    }
    
    // Handle delete expense
    if (isset($_POST['delete_expense'])) {
        try {
            $expense_id = (int)$_POST['expense_id'];
            $expense = new Expense($db);
            
            // Check if expense exists and belongs to current period
            $existing_expense = $expense->getExpenseById($expense_id);
            if (!$existing_expense) {
                $error_msg = "Expense not found.";
            } elseif ($existing_expense['time_period_id'] != $selected_period_id) {
                $error_msg = "Expense does not belong to the current period.";
            } else {
                if ($expense->delete($expense_id)) {
                    $success_msg = "Expense deleted successfully!";
                    echo '<script>window.location.href = "expenses.php?period_id=' . $selected_period_id . '";</script>';
                    exit();
                } else {
                    $error_msg = "Failed to delete expense.";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch expenses for selected period
$expenses = [];
if ($selected_period_id) {
    $result = $expenseObj->readByTimePeriod($selected_period_id);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $row;
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
include 'header.php';
include 'nav_bar.php';
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
    
    <style>
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .expense-actions {
            text-align: center;
        }
        .custom-tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 1000;
            max-width: 300px;
            word-wrap: break-word;
        }
        .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            z-index: 1050;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            display: none;
        }
        .modal.show {
            display: block;
        }
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            display: none;
        }
        .modal-backdrop.show {
            display: block;
        }
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        .modal-title {
            margin: 0;
            font-size: 1.25rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }
        .modal-body {
            padding: 1.5rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Current Period Info Styles */
        .current-period-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .current-period-info h5 {
            color: white;
            margin-bottom: 0.5rem;
        }
        .current-period-info .badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }
    </style>
</head>
<body>
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
                                        <button type="button" class="btn btn-primary" onclick="openAddExpenseModal()" <?php echo ($selected_period_details && $selected_period_details['is_locked']) ? 'disabled' : ''; ?>>
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
                $total_net = $total_amount + $total_tax + $total_fees;
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
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-list me-2"></i>
                                Expenses List
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container">
                                <table class="data-table">
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
                                                $net_amount = $exp['amount'] + $exp['tax'] + $exp['fees'];
                                            ?>
                                                <tr class="expense-row">
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
                                                            <span class="notes-tooltip" title="<?= htmlspecialchars($exp['notes']) ?>">
                                                                <i class="fas fa-sticky-note text-info"></i>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="expense-created-by">
                                                        <small class="text-muted"><?= htmlspecialchars($exp['created_by_name'] ?? 'N/A') ?></small>
                                                    </td>
                                                    <td class="expense-actions">
                                                        <div class="action-buttons">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="openEditExpenseModal(<?= $exp['id'] ?>, '<?= $exp['date'] ?>', '<?= $exp['category'] ?>', '<?= htmlspecialchars(addslashes($exp['description'])) ?>', <?= $exp['amount'] ?>, <?= $exp['tax'] ?>, <?= $exp['fees'] ?>, '<?= htmlspecialchars(addslashes($exp['notes'])) ?>')"
                                                                    <?php echo ($selected_period_details && $selected_period_details['is_locked']) ? 'disabled' : ''; ?>
                                                                    title="Edit Expense">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="openDeleteExpenseModal(<?= $exp['id'] ?>, '<?= htmlspecialchars(addslashes($exp['description'])) ?>')"
                                                                    <?php echo ($selected_period_details && $selected_period_details['is_locked']) ? 'disabled' : ''; ?>
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
                                                                <?php if ($selected_period_details && $selected_period_details['is_locked']): ?>
                                                                    <span class="text-warning d-block mt-2">
                                                                        <i class="fas fa-lock me-1"></i> This period is locked. No new entries can be added.
                                                                    </span>
                                                                <?php endif; ?>
                                                            </p>
                                                            <?php if (!$selected_period_details || !$selected_period_details['is_locked']): ?>
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
    <div class="modal-backdrop" id="expenseModalBackdrop" style="display: none;"></div>
    <div class="modal" id="addExpenseModal" style="display: none;">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-plus-circle me-2"></i>
                Add New Expense
            </h5>
            <button type="button" class="modal-close" onclick="closeAddExpenseModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="expense-form" id="expenseForm">
            <input type="hidden" name="add_expense" value="1">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($selected_period_details && $selected_period_details['is_locked']): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-lock me-2"></i> 
                        The selected period (<strong><?= htmlspecialchars($selected_period_name) ?></strong>) is locked. You cannot add new expenses.
                    </div>
                <?php else: ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control" required value="<?= date('Y-m-d') ?>" id="expenseDate">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" required id="expenseCategory">
                                <option value="">Select Category</option>
                                <option value="WAGES">WAGES</option>
                                <option value="RENT">RENT</option>
                                <option value="INSURANCE">INSURANCE</option>
                                <option value="INTERNET">INTERNET</option>
                                <option value="WATER BILL">WATER BILL</option>
                                <option value="ELECTRICITY BILL">ELECTRICITY BILL</option>
                                <option value="PROFESSINAL SERVICES">PROFESSINAL SERVICES</option>
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
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., January Rent" required id="expenseDescription">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Amount (KSH)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required min="0" id="expenseAmount">
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

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add any additional notes..." id="expenseNotes"></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeAddExpenseModal()">Cancel</button>
                <?php if (!$selected_period_details || !$selected_period_details['is_locked']): ?>
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
    <div class="modal" id="editExpenseModal" style="display: none;">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-edit me-2"></i>
                Edit Expense
            </h5>
            <button type="button" class="modal-close" onclick="closeEditExpenseModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="expense-form" id="editExpenseForm">
            <input type="hidden" name="update_expense" value="1">
            <input type="hidden" name="expense_id" id="editExpenseId">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($selected_period_details && $selected_period_details['is_locked']): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-lock me-2"></i> 
                        The selected period (<strong><?= htmlspecialchars($selected_period_name) ?></strong>) is locked. You cannot edit expenses.
                    </div>
                <?php else: ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control" required id="editExpenseDate">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" required id="editExpenseCategory">
                                <option value="">Select Category</option>
                                <option value="WAGES">WAGES</option>
                                <option value="RENT">RENT</option>
                                <option value="INSURANCE">INSURANCE</option>
                                <option value="INTERNET">INTERNET</option>
                                <option value="WATER BILL">WATER BILL</option>
                                <option value="ELECTRICITY BILL">ELECTRICITY BILL</option>
                                <option value="PROFESSINAL SERVICES">PROFESSINAL SERVICES</option>
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
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., January Rent" required id="editExpenseDescription">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Amount (KSH)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required min="0" id="editExpenseAmount">
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

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Add any additional notes..." id="editExpenseNotes"></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeEditExpenseModal()">Cancel</button>
                <?php if (!$selected_period_details || !$selected_period_details['is_locked']): ?>
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
    <div class="modal" id="deleteExpenseModal" style="display: none;">
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
            <input type="hidden" name="delete_expense" value="1">
            <input type="hidden" name="expense_id" id="deleteExpenseId">
            <input type="hidden" name="time_period_id" value="<?= $selected_period_id ?>">

            <div class="modal-body">
                <?php if ($selected_period_details && $selected_period_details['is_locked']): ?>
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
                <?php if (!$selected_period_details || !$selected_period_details['is_locked']): ?>
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

    <script>
    // Modal management functions
    function openAddExpenseModal() {
        console.log('Opening add modal...');
        document.getElementById('addExpenseModal').style.display = 'block';
        document.getElementById('addExpenseModal').classList.add('show');
        document.getElementById('expenseModalBackdrop').style.display = 'block';
        document.getElementById('expenseModalBackdrop').classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Reset form and set today's date
        document.getElementById('expenseForm').reset();
        document.getElementById('expenseDate').value = new Date().toISOString().split('T')[0];
    }

    function closeAddExpenseModal() {
        console.log('Closing add modal...');
        document.getElementById('addExpenseModal').style.display = 'none';
        document.getElementById('addExpenseModal').classList.remove('show');
        document.getElementById('expenseModalBackdrop').style.display = 'none';
        document.getElementById('expenseModalBackdrop').classList.remove('show');
        document.body.style.overflow = '';
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
        document.getElementById('editExpenseModal').style.display = 'block';
        document.getElementById('editExpenseModal').classList.add('show');
        document.getElementById('expenseModalBackdrop').style.display = 'block';
        document.getElementById('expenseModalBackdrop').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeEditExpenseModal() {
        console.log('Closing edit modal...');
        document.getElementById('editExpenseModal').style.display = 'none';
        document.getElementById('editExpenseModal').classList.remove('show');
        document.getElementById('expenseModalBackdrop').style.display = 'none';
        document.getElementById('expenseModalBackdrop').classList.remove('show');
        document.body.style.overflow = '';
    }

    function openDeleteExpenseModal(id, description) {
        console.log('Opening delete modal for expense:', id);
        
        // Populate form fields
        document.getElementById('deleteExpenseId').value = id;
        document.getElementById('deleteExpenseDescription').textContent = description;
        
        // Show modal
        document.getElementById('deleteExpenseModal').style.display = 'block';
        document.getElementById('deleteExpenseModal').classList.add('show');
        document.getElementById('expenseModalBackdrop').style.display = 'block';
        document.getElementById('expenseModalBackdrop').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeDeleteExpenseModal() {
        console.log('Closing delete modal...');
        document.getElementById('deleteExpenseModal').style.display = 'none';
        document.getElementById('deleteExpenseModal').classList.remove('show');
        document.getElementById('expenseModalBackdrop').style.display = 'none';
        document.getElementById('expenseModalBackdrop').classList.remove('show');
        document.body.style.overflow = '';
    }

    // Form validation and utility functions
    function validateExpenseForm(formId) {
        const form = document.getElementById(formId);
        const amountInput = form.querySelector('input[name="amount"]');
        const amount = parseFloat(amountInput.value);
        
        if (amount === 0 || isNaN(amount)) {
            showAlert('Please enter a valid amount greater than 0', 'warning');
            amountInput.focus();
            return false;
        }
        
        if (amount < 0) {
            showAlert('Amount cannot be negative', 'warning');
            amountInput.focus();
            return false;
        }
        
        return true;
    }

    function showAlert(message, type = 'info') {
        // Remove any existing custom alerts
        const existingAlert = document.querySelector('.custom-alert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        const alert = document.createElement('div');
        alert.className = `custom-alert alert alert-${type} alert-dismissible fade show`;
        alert.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        `;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }

    function formatCurrency(input) {
        // Remove any existing formatting
        let value = input.value.replace(/[^\d.]/g, '');
        
        // Ensure only two decimal places
        if (value.includes('.')) {
            const parts = value.split('.');
            value = parts[0] + '.' + parts[1].slice(0, 2);
        }
        
        // Format with commas
        const number = parseFloat(value);
        if (!isNaN(number)) {
            input.value = number.toLocaleString('en-KE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }

    function setupCurrencyFormatting() {
        const currencyInputs = document.querySelectorAll('input[name="amount"], input[name="tax"], input[name="fees"]');
        
        currencyInputs.forEach(input => {
            // Format on blur
            input.addEventListener('blur', function() {
                formatCurrency(this);
            });
            
            // Remove formatting on focus for easy editing
            input.addEventListener('focus', function() {
                this.value = this.value.replace(/[^\d.]/g, '');
            });
            
            // Prevent non-numeric input
            input.addEventListener('keypress', function(e) {
                const charCode = e.which ? e.which : e.keyCode;
                if (charCode === 46) { // Allow decimal point
                    if (this.value.includes('.')) {
                        e.preventDefault();
                    }
                    return;
                }
                if (charCode > 31 && (charCode < 48 || charCode > 57)) {
                    e.preventDefault();
                }
            });
        });
    }

    function calculateNetAmount() {
        const amountInputs = document.querySelectorAll('input[name="amount"], input[name="tax"], input[name="fees"]');
        const netDisplay = document.querySelector('.net-amount-display');
        
        if (!netDisplay) return;
        
        function updateNetAmount() {
            let total = 0;
            amountInputs.forEach(input => {
                const value = parseFloat(input.value.replace(/[^\d.]/g, '')) || 0;
                total += value;
            });
            
            netDisplay.textContent = 'KSH ' + total.toLocaleString('en-KE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        amountInputs.forEach(input => {
            input.addEventListener('input', updateNetAmount);
            input.addEventListener('blur', updateNetAmount);
        });
        
        // Initial calculation
        updateNetAmount();
    }

    function setupTooltips() {
        const notesTooltips = document.querySelectorAll('.notes-tooltip');
        
        notesTooltips.forEach(tooltip => {
            tooltip.addEventListener('mouseenter', function(e) {
                const title = this.getAttribute('title');
                if (title) {
                    // Remove any existing tooltip
                    const existingTooltip = document.querySelector('.custom-tooltip');
                    if (existingTooltip) {
                        existingTooltip.remove();
                    }
                    
                    const tooltipEl = document.createElement('div');
                    tooltipEl.className = 'custom-tooltip';
                    tooltipEl.textContent = title;
                    document.body.appendChild(tooltipEl);
                    
                    const rect = this.getBoundingClientRect();
                    tooltipEl.style.left = (rect.left + window.scrollX) + 'px';
                    tooltipEl.style.top = (rect.top + window.scrollY - tooltipEl.offsetHeight - 10) + 'px';
                    
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
    }

    function setupModalEvents() {
        const backdrop = document.getElementById('expenseModalBackdrop');
        
        // Close modals when clicking backdrop
        backdrop.addEventListener('click', function() {
            closeAddExpenseModal();
            closeEditExpenseModal();
            closeDeleteExpenseModal();
        });

        // Close modals when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddExpenseModal();
                closeEditExpenseModal();
                closeDeleteExpenseModal();
            }
        });

        // Prevent modal close when clicking inside modals
        const modals = ['addExpenseModal', 'editExpenseModal', 'deleteExpenseModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        });
    }

    function setupFormValidation() {
        // Add expense form validation
        const addForm = document.getElementById('expenseForm');
        if (addForm) {
            addForm.addEventListener('submit', function(e) {
                if (!validateExpenseForm('expenseForm')) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('#submitExpense');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
                }
            });
        }

        // Edit expense form validation
        const editForm = document.getElementById('editExpenseForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                if (!validateExpenseForm('editExpenseForm')) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('#updateExpense');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Updating...';
                }
            });
        }

        // Delete expense form confirmation
        const deleteForm = document.getElementById('deleteExpenseForm');
        if (deleteForm) {
            deleteForm.addEventListener('submit', function(e) {
                // Show loading state
                const submitBtn = this.querySelector('#confirmDelete');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Deleting...';
                }
            });
        }
    }

    function initializeExpenseManagement() {
        console.log('Initializing expense management...');
        
        // Set today's date as default for add form
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('#expenseForm input[name="date"]');
        if (dateInput) {
            dateInput.value = today;
        }

        // Setup all event listeners and utilities
        setupModalEvents();
        setupFormValidation();
        setupCurrencyFormatting();
        calculateNetAmount();
        setupTooltips();
        
        // Force close any open modals on page load
        closeAddExpenseModal();
        closeEditExpenseModal();
        closeDeleteExpenseModal();
        
        console.log('Expense management initialized successfully');
    }

    // Enhanced table row interactions
    function setupTableInteractions() {
        const expenseRows = document.querySelectorAll('.expense-row');
        
        expenseRows.forEach(row => {
            // Add hover effects
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f8f9fa';
                this.style.transition = 'background-color 0.2s ease';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
            
            // Add click effect for better UX
            row.addEventListener('click', function(e) {
                if (!e.target.closest('.action-buttons')) {
                    this.style.backgroundColor = '#e9ecef';
                    setTimeout(() => {
                        this.style.backgroundColor = '';
                    }, 200);
                }
            });
        });
    }

    // Search and filter functionality
    function setupSearchFilter() {
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search expenses...';
        searchInput.className = 'form-control mb-3';
        searchInput.style.maxWidth = '300px';
        
        const tableSection = document.querySelector('.expenses-table-section');
        const table = document.querySelector('.data-table');
        const tbody = table.querySelector('tbody');
        
        if (tableSection && tbody) {
            // Insert search input before the table
            const tableContainer = table.closest('.table-container');
            tableContainer.parentNode.insertBefore(searchInput, tableContainer);
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = tbody.querySelectorAll('tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
    }

    // Export functionality
    function setupExportFunctionality() {
        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-outline-success btn-sm';
        exportBtn.innerHTML = '<i class="fas fa-download me-2"></i>Export';
        
        const filterSection = document.querySelector('.filter-section .card-body');
        if (filterSection) {
            const filterGroup = filterSection.querySelector('.filter-group');
            if (filterGroup) {
                const exportItem = document.createElement('div');
                exportItem.className = 'filter-item';
                exportItem.appendChild(exportBtn);
                filterGroup.appendChild(exportItem);
                
                exportBtn.addEventListener('click', function() {
                    exportExpensesToCSV();
                });
            }
        }
    }

    function exportExpensesToCSV() {
        const expenses = <?php echo json_encode($expenses); ?>;
        const periodName = "<?php echo htmlspecialchars($selected_period_name); ?>";
        
        if (!expenses || expenses.length === 0) {
            showAlert('No expenses to export', 'warning');
            return;
        }
        
        let csvContent = 'data:text/csv;charset=utf-8,';
        csvContent += `Expenses for ${periodName}\n\n`;
        csvContent += 'Date,Category,Description,Amount,Tax,Fees,Net Expense,Notes\n';
        
        expenses.forEach(expense => {
            const netAmount = expense.amount + expense.tax + expense.fees;
            const row = [
                expense.date,
                `"${expense.category}"`,
                `"${expense.description}"`,
                expense.amount,
                expense.tax,
                expense.fees,
                netAmount,
                `"${expense.notes || ''}"`
            ];
            csvContent += row.join(',') + '\n';
        });
        
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', `expenses_${periodName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showAlert('Expenses exported successfully', 'success');
    }

    // Initialize everything when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded - initializing expense management system');
        
        initializeExpenseManagement();
        setupTableInteractions();
        setupSearchFilter();
        setupExportFunctionality();
        
        // Add net amount display to modals if not present
        addNetAmountDisplay();
    });

    // Add net amount display to forms
    function addNetAmountDisplay() {
        const addForm = document.getElementById('expenseForm');
        const editForm = document.getElementById('editExpenseForm');
        
        function createNetDisplay(form) {
            const amountGrid = form.querySelector('.form-grid');
            if (amountGrid && !form.querySelector('.net-amount-display')) {
                const netContainer = document.createElement('div');
                netContainer.className = 'form-group';
                netContainer.innerHTML = `
                    <label class="form-label">Net Amount (KSH)</label>
                    <div class="net-amount-display form-control" style="background-color: #e9ecef; font-weight: bold;">
                        KSH 0.00
                    </div>
                `;
                amountGrid.appendChild(netContainer);
            }
        }
        
        if (addForm) createNetDisplay(addForm);
        if (editForm) createNetDisplay(editForm);
    }

    // Double check on window load
    window.addEventListener('load', function() {
        console.log('Window loaded - final checks');
        
        // Ensure all modals are closed
        closeAddExpenseModal();
        closeEditExpenseModal();
        closeDeleteExpenseModal();
        
        // Re-initialize tooltips in case dynamic content was added
        setupTooltips();
    });
    </script>
</body>
</html>