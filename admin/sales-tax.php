<?php
require_once 'config.php'; // Your config file
requireLogin();

$database = new Database();
$db = $database->getConnection();

// Get current user and time period
$current_user = getCurrentUser();
$current_period = getCurrentTimePeriod($db);
$all_periods = getAllTimePeriods($db);

// Handle period selection
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : ($current_period ? $current_period['id'] : null);
$selected_period = $selected_period_id ? getTimePeriodById($db, $selected_period_id) : $current_period;

// Fetch tax data for selected period
$tax_data = [];
$period_comparison = [];
$category_tax_data = [];

if ($selected_period_id) {
    // Get transactions for selected period
    $query = "SELECT t.*, u.name as user_name 
              FROM transactions t 
              LEFT JOIN users u ON t.user_id = u.id 
              WHERE t.time_period_id = ? 
              ORDER BY t.transaction_date DESC";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $selected_period_id);
    $stmt->execute();
    $transactions_result = $stmt->get_result();
    
    while ($transaction = $transactions_result->fetch_assoc()) {
        $tax_data[] = $transaction;
    }
    
    // Get tax by category for selected period
    $category_query = "SELECT 
                        c.name as category_name,
                        SUM(ti.total_price) as taxable_sales,
                        SUM(t.tax_amount) as tax_collected
                      FROM transaction_items ti
                      JOIN transactions t ON ti.transaction_id = t.id
                      JOIN products p ON ti.product_id = p.id
                      JOIN categories c ON p.category_id = c.id
                      WHERE t.time_period_id = ?
                      GROUP BY c.name
                      ORDER BY tax_collected DESC";
    $stmt = $db->prepare($category_query);
    $stmt->bind_param("i", $selected_period_id);
    $stmt->execute();
    $category_result = $stmt->get_result();
    
    while ($category = $category_result->fetch_assoc()) {
        $category_tax_data[] = $category;
    }
}

// Get period comparison data
$comparison_query = "SELECT 
                        tp.id,
                        tp.period_name,
                        tp.year,
                        tp.month,
                        COUNT(t.id) as transaction_count,
                        SUM(t.total_amount) as total_sales,
                        SUM(t.tax_amount) as total_tax
                     FROM time_periods tp
                     LEFT JOIN transactions t ON tp.id = t.time_period_id
                     GROUP BY tp.id, tp.period_name, tp.year, tp.month
                     ORDER BY tp.year DESC, tp.month DESC
                     LIMIT 6";
$stmt = $db->prepare($comparison_query);
$stmt->execute();
$comparison_result = $stmt->get_result();

while ($period = $comparison_result->fetch_assoc()) {
    $period_comparison[] = $period;
}

// Calculate summary statistics
$total_tax_collected = 0;
$total_taxable_sales = 0;
$transaction_count = count($tax_data);

foreach ($tax_data as $transaction) {
    $total_tax_collected += floatval($transaction['tax_amount']);
    $total_taxable_sales += floatval($transaction['total_amount']) - floatval($transaction['tax_amount']);
}

$avg_tax_per_transaction = $transaction_count > 0 ? $total_tax_collected / $transaction_count : 0;

// Calculate tax rate (assuming 16% from your data)
$tax_rate = 0.16; // 16% VAT

// Calculate period comparison for trends
$previous_period_tax = 0;
if (count($period_comparison) > 1) {
    $previous_period_tax = floatval($period_comparison[1]['total_tax'] ?? 0);
}

$tax_trend = $previous_period_tax > 0 ? 
    (($total_tax_collected - $previous_period_tax) / $previous_period_tax) * 100 : 0;

$sales_trend = $previous_period_tax > 0 ? 
    (($total_taxable_sales - ($previous_period_tax / $tax_rate)) / ($previous_period_tax / $tax_rate)) * 100 : 0;

$transaction_trend = count($period_comparison) > 1 ? 
    (($transaction_count - intval($period_comparison[1]['transaction_count'] ?? 0)) / 
     max(1, intval($period_comparison[1]['transaction_count'] ?? 0))) * 100 : 0;
     include 'header.php';
     include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Tax Report - Vinmel Irrigation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css"> <!-- Your global CSS -->
    <style>
        /* Your global CSS is already included */
        /* Additional styles specific to the tax report page */
        
        .tax-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        
        .tax-breakdown {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
        }
        
        .tax-period-selector {
            display: flex;
            gap: var(--spacing-md);
            align-items: center;
            margin-bottom: var(--spacing-lg);
            flex-wrap: wrap;
        }
        
        .tax-rate-badge {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: var(--font-weight-semibold);
        }
        
        .tax-chart-container {
            height: 300px;
            margin-top: var(--spacing-lg);
        }
        
        .tax-trend {
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
            font-size: 0.85rem;
        }
        
        .empty-state {
            text-align: center;
            padding: var(--spacing-xl);
            color: var(--muted-text);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: var(--spacing-md);
            opacity: 0.5;
        }
    </style>
</head>
<body>
    
    <!-- Main Content -->
    <main class="main-content">
        <div class="content-area">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-percentage"></i>
                        Sales Tax Report
                    </h1>
                    <p class="content-subtitle">Track and analyze sales tax collection and liabilities</p>
                </div>
                <div class="export-controls">
                    <button class="btn btn-outline" onclick="exportTaxData()">
                        <i class="fas fa-file-export"></i>
                        Export
                    </button>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i>
                        Print
                    </button>
                </div>
            </div>

            <!-- Period Selector -->
            <div class="tax-period-selector">
                <div class="form-group">
                    <label class="form-label">Select Period</label>
                    <select class="form-select" id="period-select" onchange="changePeriod(this.value)">
                        <?php foreach ($all_periods as $period): ?>
                            <option value="<?php echo $period['id']; ?>" 
                                <?php echo $selected_period_id == $period['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($period['period_name']); ?>
                                <?php echo $period['is_locked'] ? ' (Locked)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tax Rate</label>
                    <div class="tax-rate-badge"><?php echo ($tax_rate * 100) . '% VAT'; ?></div>
                </div>
                <?php if ($selected_period): ?>
                <div class="form-group">
                    <label class="form-label">Selected Period</label>
                    <div class="period-info">
                        <strong><?php echo htmlspecialchars($selected_period['period_name']); ?></strong>
                        (<?php echo date('M j, Y', strtotime($selected_period['start_date'])) . ' - ' . 
                              date('M j, Y', strtotime($selected_period['end_date'])); ?>)
                        <?php if ($selected_period['is_locked']): ?>
                            <span class="badge badge-warning ms-2">Locked</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tax Summary Cards -->
            <div class="tax-summary-grid">
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-value">KSh <?php echo number_format($total_tax_collected, 2); ?></div>
                    <div class="stat-label">Total Tax Collected</div>
                    <?php if ($tax_trend != 0): ?>
                    <div class="stat-change <?php echo $tax_trend > 0 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-arrow-<?php echo $tax_trend > 0 ? 'up' : 'down'; ?>"></i>
                        <span><?php echo number_format(abs($tax_trend), 1); ?>% from last period</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-value"><?php echo $transaction_count; ?></div>
                    <div class="stat-label">Taxable Transactions</div>
                    <?php if ($transaction_trend != 0): ?>
                    <div class="stat-change <?php echo $transaction_trend > 0 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-arrow-<?php echo $transaction_trend > 0 ? 'up' : 'down'; ?>"></i>
                        <span><?php echo number_format(abs($transaction_trend), 1); ?>% from last period</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value">KSh <?php echo number_format($total_taxable_sales, 2); ?></div>
                    <div class="stat-label">Taxable Sales Amount</div>
                    <?php if ($sales_trend != 0): ?>
                    <div class="stat-change <?php echo $sales_trend > 0 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-arrow-<?php echo $sales_trend > 0 ? 'up' : 'down'; ?>"></i>
                        <span><?php echo number_format(abs($sales_trend), 1); ?>% from last period</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tax Breakdown by Period -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        Tax Breakdown by Period
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($period_comparison)): ?>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Taxable Sales</th>
                                    <th>Tax Collected</th>
                                    <th>Tax Rate</th>
                                    <th>Transactions</th>
                                    <th>Avg. Tax per Transaction</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($period_comparison as $period): 
                                    $period_taxable_sales = floatval($period['total_sales'] ?? 0) - floatval($period['total_tax'] ?? 0);
                                    $avg_tax = $period['transaction_count'] > 0 ? floatval($period['total_tax'] ?? 0) / $period['transaction_count'] : 0;
                                ?>
                                <tr>
                                    <td>
                                        <a href="sales_tax.php?period_id=<?php echo $period['id']; ?>" 
                                           class="<?php echo $selected_period_id == $period['id'] ? 'fw-bold text-primary' : ''; ?>">
                                            <?php echo htmlspecialchars($period['period_name']); ?>
                                        </a>
                                    </td>
                                    <td>KSh <?php echo number_format($period_taxable_sales, 2); ?></td>
                                    <td>KSh <?php echo number_format(floatval($period['total_tax'] ?? 0), 2); ?></td>
                                    <td><?php echo ($tax_rate * 100) . '%'; ?></td>
                                    <td><?php echo $period['transaction_count']; ?></td>
                                    <td>KSh <?php echo number_format($avg_tax, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h5>No Tax Data Available</h5>
                        <p>No transaction data found for analysis.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Taxable Transactions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Taxable Transactions - <?php echo $selected_period ? htmlspecialchars($selected_period['period_name']) : 'Selected Period'; ?>
                    </h3>
                    <div class="form-group mb-0">
                        <input type="text" class="form-control" id="transaction-search" placeholder="Search transactions..." onkeyup="filterTransactions()">
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($tax_data)): ?>
                    <div class="table-responsive">
                        <table class="table data-table" id="transactions-table">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Date</th>
                                    <th>Taxable Amount</th>
                                    <th>Tax Amount</th>
                                    <th>Gross Amount</th>
                                    <th>Payment Method</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tax_data as $transaction): 
                                    $taxable_amount = floatval($transaction['total_amount']) - floatval($transaction['tax_amount']);
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['receipt_number']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($transaction['transaction_date'])); ?></td>
                                    <td>KSh <?php echo number_format($taxable_amount, 2); ?></td>
                                    <td>KSh <?php echo number_format(floatval($transaction['tax_amount']), 2); ?></td>
                                    <td>KSh <?php echo number_format(floatval($transaction['net_amount']), 2); ?></td>
                                    <td>
                                        <span class="badge badge-outline badge-primary">
                                            <?php echo ucfirst($transaction['payment_method'] ?? 'cash'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar-sm me-2">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <span><?php echo htmlspecialchars($transaction['user_name'] ?? 'Unknown'); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <h5>No Transactions Found</h5>
                        <p>No taxable transactions found for the selected period.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tax by Product Category -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tags"></i>
                        Tax by Product Category - <?php echo $selected_period ? htmlspecialchars($selected_period['period_name']) : 'Selected Period'; ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($category_tax_data)): ?>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Taxable Sales</th>
                                    <th>Tax Collected</th>
                                    <th>% of Total Tax</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($category_tax_data as $category): 
                                    $tax_percentage = $total_tax_collected > 0 ? 
                                        (floatval($category['tax_collected']) / $total_tax_collected) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                    <td>KSh <?php echo number_format(floatval($category['taxable_sales']), 2); ?></td>
                                    <td>KSh <?php echo number_format(floatval($category['tax_collected']), 2); ?></td>
                                    <td><?php echo number_format($tax_percentage, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tags"></i>
                        <h5>No Category Data</h5>
                        <p>No tax data available by product category for the selected period.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('mobile-open');
        });

        // Change period function
        function changePeriod(periodId) {
            window.location.href = 'sales_tax.php?period_id=' + periodId;
        }

        // Filter transactions function
        function filterTransactions() {
            const input = document.getElementById('transaction-search');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('transactions-table');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }

        // Export tax data function
        function exportTaxData() {
            // In a real application, this would generate a CSV or PDF
            alert('Export functionality would be implemented here. This would generate a CSV file with all tax data.');
        }
    </script>
</body>
</html>