<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Check if user is logged in
checkAuth();

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get current user ID
$user_id = $_SESSION['user_id'] ?? null;

// Get current period information
$current_period = getCurrentTimePeriod($db);

// Handle period selection
$selected_period_id = $_GET['period_id'] ?? ($current_period['id'] ?? null);

// FIX: Pass both user_id and db connection to getTimePeriods
$periods = getTimePeriods($user_id, $db);

// Handle form submissions for adding assets and liabilities
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_asset'])) {
        $asset_name = $_POST['asset_name'];
        $asset_value = $_POST['asset_value'];
        $asset_type = $_POST['asset_type'];
        
        $stmt = $db->prepare("INSERT INTO balance_sheet_assets (period_id, asset_name, asset_value, asset_type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isds", $selected_period_id, $asset_name, $asset_value, $asset_type);
        $stmt->execute();
        
        $_SESSION['success_message'] = "Asset added successfully!";
        header("Location: balance_sheet.php?period_id=" . $selected_period_id);
        exit();
    }
    
    if (isset($_POST['add_liability'])) {
        $liability_name = $_POST['liability_name'];
        $liability_value = $_POST['liability_value'];
        $liability_type = $_POST['liability_type'];
        
        $stmt = $db->prepare("INSERT INTO balance_sheet_liabilities (period_id, liability_name, liability_value, liability_type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isds", $selected_period_id, $liability_name, $liability_value, $liability_type);
        $stmt->execute();
        
        $_SESSION['success_message'] = "Liability added successfully!";
        header("Location: balance_sheet.php?period_id=" . $selected_period_id);
        exit();
    }
    
    if (isset($_POST['delete_asset'])) {
        $asset_id = $_POST['asset_id'];
        $stmt = $db->prepare("DELETE FROM balance_sheet_assets WHERE id = ?");
        $stmt->bind_param("i", $asset_id);
        $stmt->execute();
        
        $_SESSION['success_message'] = "Asset deleted successfully!";
        header("Location: balance_sheet.php?period_id=" . $selected_period_id);
        exit();
    }
    
    if (isset($_POST['delete_liability'])) {
        $liability_id = $_POST['liability_id'];
        $stmt = $db->prepare("DELETE FROM balance_sheet_liabilities WHERE id = ?");
        $stmt->bind_param("i", $liability_id);
        $stmt->execute();
        
        $_SESSION['success_message'] = "Liability deleted successfully!";
        header("Location: balance_sheet.php?period_id=" . $selected_period_id);
        exit();
    }
}

// Create tables if they don't exist
$create_assets_table = "CREATE TABLE IF NOT EXISTS balance_sheet_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    asset_value DECIMAL(15,2) NOT NULL,
    asset_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (period_id) REFERENCES time_periods(id)
)";

$create_liabilities_table = "CREATE TABLE IF NOT EXISTS balance_sheet_liabilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    liability_name VARCHAR(255) NOT NULL,
    liability_value DECIMAL(15,2) NOT NULL,
    liability_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (period_id) REFERENCES time_periods(id)
)";

$db->query($create_assets_table);
$db->query($create_liabilities_table);

// Get balance sheet data for selected period
$balance_data = getBalanceSheetData($selected_period_id, $db);

// Function to get balance sheet data
function getBalanceSheetData($period_id, $db) {
    $data = [
        'period_info' => [],
        'assets' => [],
        'liabilities' => [],
        'equity' => [],
        'income' => [],
        'expenses' => [],
        'totals' => [],
        'manual_assets' => [],
        'manual_liabilities' => []
    ];
    
    // Get period info
    if ($period_id) {
        $stmt = $db->prepare("SELECT * FROM time_periods WHERE id = ?");
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['period_info'] = $result->fetch_assoc() ?: [];
    }
    
    // Get manual assets
    $assets_stmt = $db->prepare("SELECT * FROM balance_sheet_assets WHERE period_id = ?");
    $assets_stmt->bind_param("i", $period_id);
    $assets_stmt->execute();
    $assets_result = $assets_stmt->get_result();
    while ($row = $assets_result->fetch_assoc()) {
        $data['manual_assets'][] = $row;
    }
    
    // Get manual liabilities
    $liabilities_stmt = $db->prepare("SELECT * FROM balance_sheet_liabilities WHERE period_id = ?");
    $liabilities_stmt->bind_param("i", $period_id);
    $liabilities_stmt->execute();
    $liabilities_result = $liabilities_stmt->get_result();
    while ($row = $liabilities_result->fetch_assoc()) {
        $data['manual_liabilities'][] = $row;
    }
    
    // Get income data (from transactions)
    $income_stmt = $db->prepare("
        SELECT 
            SUM(t.net_amount) as total_income,
            COUNT(t.id) as transaction_count
        FROM transactions t
        WHERE t.time_period_id = ?
    ");
    $income_stmt->bind_param("i", $period_id);
    $income_stmt->execute();
    $income_result = $income_stmt->get_result();
    $income_data = $income_result->fetch_assoc();
    
    $data['income'] = [
        'total' => $income_data['total_income'] ?? 0,
        'count' => $income_data['transaction_count'] ?? 0
    ];
    
    // Get expenses data
    $expenses_stmt = $db->prepare("
        SELECT 
            SUM(e.net_amount) as total_expenses,
            COUNT(e.id) as expense_count
        FROM expenses e
        WHERE e.time_period_id = ?
    ");
    $expenses_stmt->bind_param("i", $period_id);
    $expenses_stmt->execute();
    $expenses_result = $expenses_stmt->get_result();
    $expenses_data = $expenses_result->fetch_assoc();
    
    $data['expenses'] = [
        'total' => $expenses_data['total_expenses'] ?? 0,
        'count' => $expenses_data['expense_count'] ?? 0
    ];
    
    // Calculate net income
    $net_income = ($data['income']['total'] - $data['expenses']['total']);
    
    // For this example, we'll use simplified calculations
    // In a real system, you'd have more complex asset/liability tracking
    
    // Assets (simplified - inventory value + cash from sales)
    $inventory_stmt = $db->prepare("
        SELECT 
            SUM(p.stock_quantity * p.cost_price) as inventory_value,
            COUNT(p.id) as product_count
        FROM products p
        WHERE p.period_id = ?
    ");
    $inventory_stmt->bind_param("i", $period_id);
    $inventory_stmt->execute();
    $inventory_result = $inventory_stmt->get_result();
    $inventory_data = $inventory_result->fetch_assoc();
    
    // Calculate total manual assets
    $total_manual_assets = 0;
    foreach ($data['manual_assets'] as $asset) {
        $total_manual_assets += $asset['asset_value'];
    }
    
    $data['assets'] = [
        'cash' => $data['income']['total'] * 0.7, // Assume 70% of income is cash
        'inventory' => $inventory_data['inventory_value'] ?? 0,
        'equipment' => 150000.00, // Fixed asset value (would come from a fixed assets table)
        'receivables' => $data['income']['total'] * 0.3, // Assume 30% receivables
        'manual_assets' => $total_manual_assets
    ];
    
    // Calculate total manual liabilities
    $total_manual_liabilities = 0;
    foreach ($data['manual_liabilities'] as $liability) {
        $total_manual_liabilities += $liability['liability_value'];
    }
    
    // Liabilities (simplified)
    $data['liabilities'] = [
        'accounts_payable' => $data['expenses']['total'] * 0.4, // Assume 40% unpaid expenses
        'loans' => 50000.00, // Fixed loan amount (would come from loans table)
        'tax_payable' => $net_income * 0.2, // Assume 20% tax on profit
        'manual_liabilities' => $total_manual_liabilities
    ];
    
    // Equity
    $previous_equity = 200000.00; // Would come from previous period
    $data['equity'] = [
        'capital' => $previous_equity,
        'retained_earnings' => $net_income,
        'drawings' => $data['expenses']['total'] * 0.1 // Assume 10% of expenses are owner drawings
    ];
    
    // Calculate totals
    $data['totals'] = [
        'total_assets' => array_sum($data['assets']),
        'total_liabilities' => array_sum($data['liabilities']),
        'total_equity' => $data['equity']['capital'] + $data['equity']['retained_earnings'] - $data['equity']['drawings'],
        'net_income' => $net_income
    ];
    
    return $data;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet - Vinmel Irrigation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .balance-sheet-section {
            margin-bottom: 2rem;
        }
        
        .balance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .balance-table th {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .balance-table td {
            padding: 10px 15px;
            border-bottom: 1px solid var(--light-border);
        }
        
        .balance-table tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .amount-cell {
            text-align: right;
            font-weight: 500;
        }
        
        .total-row {
            background-color: var(--light-bg);
            font-weight: bold;
        }
        
        .total-row td {
            border-top: 2px solid var(--primary-color);
            padding-top: 12px;
        }
        
        .section-header {
            background-color: var(--primary-light);
            color: white;
            padding: 10px 15px;
            border-radius: 5px 5px 0 0;
            margin-bottom: 0;
        }
        
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-color);
        }
        
        .summary-card.income {
            border-left-color: var(--success-color);
        }
        
        .summary-card.expenses {
            border-left-color: var(--danger-color);
        }
        
        .summary-card.net {
            border-left-color: var(--info-color);
        }
        
        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin: 0.5rem 0;
        }
        
        .summary-value.positive {
            color: var(--success-color);
        }
        
        .summary-value.negative {
            color: var(--danger-color);
        }
        
        .period-selector {
            max-width: 300px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .manual-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--light-border);
        }
        
        .manual-item:last-child {
            border-bottom: none;
        }
        
        .manual-item-actions {
            display: flex;
            gap: 5px;
        }
        
        @media print {
            .no-print, .period-selector, .btn, .action-buttons {
                display: none !important;
            }
            
            .balance-table {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'nav_bar.php'; ?>
    
    <main class="main-content">
        <div class="container-fluid">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-balance-scale"></i>
                        Balance Sheet
                    </h1>
                    <p class="content-subtitle">Financial position overview for selected period</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="period-selector">
                        <select class="form-select" id="periodSelect" onchange="changePeriod(this.value)">
                            <option value="">Select Period</option>
                            <?php foreach($periods as $period): ?>
                                <option value="<?= $period['id'] ?>" 
                                    <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>>
                                    <?= $period['period_name'] ?>
                                    <?= $period['is_locked'] ? ' (Locked)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-outline-primary no-print" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if(empty($selected_period_id) || empty($balance_data['period_info'])): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Please select a time period to view the balance sheet.
                </div>
            <?php else: ?>
                <!-- Period Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-alt"></i>
                            Period: <?= $balance_data['period_info']['period_name'] ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Date Range:</strong> 
                                <?= date('M j, Y', strtotime($balance_data['period_info']['start_date'])) ?> - 
                                <?= date('M j, Y', strtotime($balance_data['period_info']['end_date'])) ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Status:</strong> 
                                <?= $balance_data['period_info']['is_locked'] ? 
                                    '<span class="badge bg-warning">Locked</span>' : 
                                    '<span class="badge bg-success">Active</span>' ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Report Date:</strong> <?= date('F j, Y') ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="action-buttons no-print">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                        <i class="fas fa-plus-circle"></i> Add Asset
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addLiabilityModal">
                        <i class="fas fa-plus-circle"></i> Add Liability
                    </button>
                </div>
                
                <!-- Financial Summary -->
                <div class="financial-summary">
                    <div class="summary-card income">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6>Total Income</h6>
                                <div class="summary-value positive">
                                    KES <?= number_format($balance_data['income']['total'], 2) ?>
                                </div>
                                <small><?= $balance_data['income']['count'] ?> transactions</small>
                            </div>
                            <i class="fas fa-arrow-up text-success fa-2x"></i>
                        </div>
                    </div>
                    
                    <div class="summary-card expenses">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6>Total Expenses</h6>
                                <div class="summary-value negative">
                                    KES <?= number_format($balance_data['expenses']['total'], 2) ?>
                                </div>
                                <small><?= $balance_data['expenses']['count'] ?> expenses</small>
                            </div>
                            <i class="fas fa-arrow-down text-danger fa-2x"></i>
                        </div>
                    </div>
                    
                    <div class="summary-card net">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6>Net Income</h6>
                                <div class="summary-value <?= $balance_data['totals']['net_income'] >= 0 ? 'positive' : 'negative' ?>">
                                    KES <?= number_format($balance_data['totals']['net_income'], 2) ?>
                                </div>
                                <small>Income - Expenses</small>
                            </div>
                            <i class="fas fa-chart-line text-info fa-2x"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Balance Sheet -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="balance-sheet-section">
                            <div class="card">
                                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-wallet"></i> Assets
                                    </h5>
                                    <span class="badge bg-light text-dark">
                                        Total: KES <?= number_format($balance_data['totals']['total_assets'], 2) ?>
                                    </span>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <tbody>
                                            <tr>
                                                <td>Cash & Bank</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['assets']['cash'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Accounts Receivable</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['assets']['receivables'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Inventory</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['assets']['inventory'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Equipment & Property</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['assets']['equipment'], 2) ?></td>
                                            </tr>
                                            
                                            <!-- Manual Assets -->
                                            <?php if (!empty($balance_data['manual_assets'])): ?>
                                                <tr>
                                                    <td colspan="2" class="section-header">Additional Assets</td>
                                                </tr>
                                                <?php foreach($balance_data['manual_assets'] as $asset): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars($asset['asset_name']) ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                                                <button type="submit" name="delete_asset" class="btn btn-sm btn-outline-danger ms-2 no-print" onclick="return confirm('Are you sure you want to delete this asset?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                        <td class="amount-cell">KES <?= number_format($asset['asset_value'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <tr class="total-row">
                                                <td><strong>Total Assets</strong></td>
                                                <td class="amount-cell"><strong>KES <?= number_format($balance_data['totals']['total_assets'], 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="balance-sheet-section">
                            <div class="card">
                                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-file-invoice-dollar"></i> Liabilities & Equity
                                    </h5>
                                    <span class="badge bg-light text-dark">
                                        Total: KES <?= number_format($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'], 2) ?>
                                    </span>
                                </div>
                                <div class="card-body p-0">
                                    <table class="balance-table">
                                        <tbody>
                                            <tr>
                                                <td colspan="2" class="section-header">Liabilities</td>
                                            </tr>
                                            <tr>
                                                <td>Accounts Payable</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['liabilities']['accounts_payable'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Loans Payable</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['liabilities']['loans'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Tax Payable</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['liabilities']['tax_payable'], 2) ?></td>
                                            </tr>
                                            
                                            <!-- Manual Liabilities -->
                                            <?php if (!empty($balance_data['manual_liabilities'])): ?>
                                                <tr>
                                                    <td colspan="2" class="section-header">Additional Liabilities</td>
                                                </tr>
                                                <?php foreach($balance_data['manual_liabilities'] as $liability): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars($liability['liability_name']) ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="liability_id" value="<?= $liability['id'] ?>">
                                                                <button type="submit" name="delete_liability" class="btn btn-sm btn-outline-danger ms-2 no-print" onclick="return confirm('Are you sure you want to delete this liability?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                        <td class="amount-cell">KES <?= number_format($liability['liability_value'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <tr class="total-row">
                                                <td><strong>Total Liabilities</strong></td>
                                                <td class="amount-cell"><strong>KES <?= number_format($balance_data['totals']['total_liabilities'], 2) ?></strong></td>
                                            </tr>
                                            
                                            <tr>
                                                <td colspan="2" class="section-header">Equity</td>
                                            </tr>
                                            <tr>
                                                <td>Owner's Capital</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['equity']['capital'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Retained Earnings</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['equity']['retained_earnings'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <td>Owner's Drawings</td>
                                                <td class="amount-cell">KES <?= number_format($balance_data['equity']['drawings'], 2) ?></td>
                                            </tr>
                                            <tr class="total-row">
                                                <td><strong>Total Equity</strong></td>
                                                <td class="amount-cell"><strong>KES <?= number_format($balance_data['totals']['total_equity'], 2) ?></strong></td>
                                            </tr>
                                            
                                            <tr class="total-row" style="background-color: var(--primary-light); color: white;">
                                                <td><strong>Total Liabilities & Equity</strong></td>
                                                <td class="amount-cell"><strong>KES <?= number_format($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'], 2) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Balance Check -->
                <div class="card mt-4 <?= ($balance_data['totals']['total_assets'] == ($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'])) ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                    <div class="card-body text-center">
                        <h5 class="card-title">
                            <i class="fas fa-<?= ($balance_data['totals']['total_assets'] == ($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'])) ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                            Balance Check: 
                            <?= ($balance_data['totals']['total_assets'] == ($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'])) ? 
                                'Assets = Liabilities + Equity (Balanced)' : 
                                'Assets ≠ Liabilities + Equity (Not Balanced)' ?>
                        </h5>
                        <p class="mb-0">
                            Assets (KES <?= number_format($balance_data['totals']['total_assets'], 2) ?>) 
                            <?= ($balance_data['totals']['total_assets'] == ($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'])) ? '=' : '≠' ?> 
                            Liabilities + Equity (KES <?= number_format($balance_data['totals']['total_liabilities'] + $balance_data['totals']['total_equity'], 2) ?>)
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Add Asset Modal -->
    <div class="modal fade" id="addAssetModal" tabindex="-1" aria-labelledby="addAssetModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addAssetModalLabel">
                        <i class="fas fa-plus-circle"></i> Add New Asset
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="asset_name" class="form-label">Asset Name</label>
                            <input type="text" class="form-control" id="asset_name" name="asset_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="asset_value" class="form-label">Asset Value (KES)</label>
                            <input type="number" class="form-control" id="asset_value" name="asset_value" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="asset_type" class="form-label">Asset Type</label>
                            <select class="form-select" id="asset_type" name="asset_type">
                                <option value="cash">Cash</option>
                                <option value="receivable">Accounts Receivable</option>
                                <option value="inventory">Inventory</option>
                                <option value="equipment">Equipment</option>
                                <option value="other" selected>Other Asset</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_asset" class="btn btn-success">Add Asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Liability Modal -->
    <div class="modal fade" id="addLiabilityModal" tabindex="-1" aria-labelledby="addLiabilityModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="addLiabilityModalLabel">
                        <i class="fas fa-plus-circle"></i> Add New Liability
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="liability_name" class="form-label">Liability Name</label>
                            <input type="text" class="form-control" id="liability_name" name="liability_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="liability_value" class="form-label">Liability Value (KES)</label>
                            <input type="number" class="form-control" id="liability_value" name="liability_value" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="liability_type" class="form-label">Liability Type</label>
                            <select class="form-select" id="liability_type" name="liability_type">
                                <option value="payable">Accounts Payable</option>
                                <option value="loan">Loan</option>
                                <option value="tax">Tax Payable</option>
                                <option value="other" selected>Other Liability</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_liability" class="btn btn-danger">Add Liability</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function changePeriod(periodId) {
            if (periodId) {
                window.location.href = `balance_sheet.php?period_id=${periodId}`;
            }
        }
        
        // Auto-refresh if current period is active
        <?php if($current_period && $current_period['is_active'] && !$current_period['is_locked']): ?>
        setTimeout(function() {
            location.reload();
        }, 30000); // Refresh every 30 seconds if period is active
        <?php endif; ?>
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>