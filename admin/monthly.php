<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';



// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get current period or selected period
$current_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;

// If no period selected, get the active one
if (!$current_period_id) {
    $active_period_query = "SELECT id FROM time_periods WHERE is_active = 1 LIMIT 1";
    $active_result = $db->query($active_period_query);
    if ($active_result && $active_result->num_rows > 0) {
        $active_period = $active_result->fetch_assoc();
        $current_period_id = $active_period['id'];
    }
}

// Get available periods
$periods_query = "SELECT * FROM time_periods ORDER BY year DESC, month DESC";
$periods_result = $db->query($periods_query);

// Get selected period details
$period_details = null;
if ($current_period_id) {
    $period_query = "SELECT * FROM time_periods WHERE id = ?";
    $stmt = $db->prepare($period_query);
    $stmt->bind_param("i", $current_period_id);
    $stmt->execute();
    $period_details = $stmt->get_result()->fetch_assoc();
}

// Get transactions data for the period
$transactions_data = [];
$total_income = 0;
$total_tax = 0;
$total_discount = 0;
$transaction_count = 0;
$category_totals = [];
$daily_income = [];
$product_sales = [];

if ($current_period_id) {
    // Get all transactions with details
    $transactions_query = "
        SELECT 
            DATE(t.transaction_date) as date,
            t.total_amount,
            t.tax_amount,
            t.discount_amount,
            t.net_amount,
            t.payment_method,
            ti.quantity,
            ti.unit_price,
            ti.total_price,
            p.name as product_name,
            p.cost_price,
            c.name as category_name
        FROM transactions t
        LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
        LEFT JOIN products p ON ti.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE t.time_period_id = ?
        ORDER BY t.transaction_date DESC
    ";
    $stmt = $db->prepare($transactions_query);
    $stmt->bind_param("i", $current_period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transaction_ids = [];
    
    while ($row = $result->fetch_assoc()) {
        $transactions_data[] = $row;
        
        // Calculate totals (avoid duplicates by checking transaction_date + amount combination)
        $unique_key = $row['date'] . '-' . $row['net_amount'];
        if (!in_array($unique_key, $transaction_ids)) {
            $transaction_ids[] = $unique_key;
            $total_income += $row['net_amount'];
            $total_tax += $row['tax_amount'];
            $total_discount += $row['discount_amount'];
            $transaction_count++;
            
            // Daily income tracking
            if (!isset($daily_income[$row['date']])) {
                $daily_income[$row['date']] = 0;
            }
            $daily_income[$row['date']] += $row['net_amount'];
        }
        
        // Category totals
        if ($row['category_name']) {
            if (!isset($category_totals[$row['category_name']])) {
                $category_totals[$row['category_name']] = [
                    'amount' => 0,
                    'quantity' => 0,
                    'profit' => 0
                ];
            }
            $category_totals[$row['category_name']]['amount'] += $row['total_price'];
            $category_totals[$row['category_name']]['quantity'] += $row['quantity'];
            
            // Calculate profit (selling price - cost price)
            $profit = ($row['unit_price'] - $row['cost_price']) * $row['quantity'];
            $category_totals[$row['category_name']]['profit'] += $profit;
        }
        
        // Product sales tracking
        if ($row['product_name']) {
            if (!isset($product_sales[$row['product_name']])) {
                $product_sales[$row['product_name']] = [
                    'quantity' => 0,
                    'revenue' => 0,
                    'profit' => 0
                ];
            }
            $product_sales[$row['product_name']]['quantity'] += $row['quantity'];
            $product_sales[$row['product_name']]['revenue'] += $row['total_price'];
            
            $profit = ($row['unit_price'] - $row['cost_price']) * $row['quantity'];
            $product_sales[$row['product_name']]['profit'] += $profit;
        }
    }
}

// Calculate total profit
$total_profit = 0;
foreach ($category_totals as $cat_data) {
    $total_profit += $cat_data['profit'];
}

// Calculate profit margin
$profit_margin = $total_income > 0 ? ($total_profit / $total_income) * 100 : 0;

// Calculate average transaction value
$avg_transaction = $transaction_count > 0 ? $total_income / $transaction_count : 0;

// Sort daily income by date
ksort($daily_income);

// Get top 5 products
arsort($product_sales);
$top_products = array_slice($product_sales, 0, 5, true);

// Sort categories by amount
uasort($category_totals, function($a, $b) {
    return $b['amount'] - $a['amount'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Dashboard - Vinmel Irrigation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="main-content">
        <?php include 'nav_bar.php'; ?>
        
        <div class="content-area">
            <!-- Page Header -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fas fa-chart-bar"></i> Monthly Dashboard</h1>
                    <p class="text-muted">
                        <?php if ($period_details): ?>
                            <?php echo htmlspecialchars($period_details['period_name']); ?> 
                            (<?php echo date('M j, Y', strtotime($period_details['start_date'])); ?> - 
                            <?php echo date('M j, Y', strtotime($period_details['end_date'])); ?>)
                        <?php else: ?>
                            No period selected
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <select id="periodSelect" class="form-control" style="min-width: 250px;">
                        <option value="">Select Period</option>
                        <?php 
                        $periods_result->data_seek(0);
                        while ($period = $periods_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $period['id']; ?>" 
                                <?php echo $period['id'] == $current_period_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($period['period_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <?php if (!$current_period_id): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>No Period Selected:</strong> Please select a time period to view the dashboard.
                </div>
            <?php else: ?>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="metric-card success">
                        <div class="metric-label">
                            <i class="fas fa-money-bill-wave"></i> Total Income
                        </div>
                        <div class="metric-value">KSh <?php echo number_format($total_income, 2); ?></div>
                        <div class="text-light opacity-75" style="font-size: 0.85rem;">
                            Net amount after tax
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="metric-card info">
                        <div class="metric-label">
                            <i class="fas fa-chart-line"></i> Total Profit
                        </div>
                        <div class="metric-value">KSh <?php echo number_format($total_profit, 2); ?></div>
                        <div class="text-light opacity-75" style="font-size: 0.85rem;">
                            Gross profit margin
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="metric-card warning">
                        <div class="metric-label">
                            <i class="fas fa-percentage"></i> Profit Margin
                        </div>
                        <div class="metric-value"><?php echo number_format($profit_margin, 1); ?>%</div>
                        <div class="text-light opacity-75" style="font-size: 0.85rem;">
                            (Profit / Income) × 100
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">
                            <i class="fas fa-receipt"></i> Transactions
                        </div>
                        <div class="metric-value"><?php echo number_format($transaction_count); ?></div>
                        <div class="text-light opacity-75" style="font-size: 0.85rem;">
                            Avg: KSh <?php echo number_format($avg_transaction, 2); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Metrics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-tags fa-2x text-primary mb-2"></i>
                            <h5>Total Tax Collected</h5>
                            <h3 class="text-primary">KSh <?php echo number_format($total_tax, 2); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-percentage fa-2x text-success mb-2"></i>
                            <h5>Total Discounts</h5>
                            <h3 class="text-success">KSh <?php echo number_format($total_discount, 2); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-boxes fa-2x text-warning mb-2"></i>
                            <h5>Product Categories</h5>
                            <h3 class="text-warning"><?php echo count($category_totals); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Income by Category</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="categoryChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-line"></i> Daily Income Trend</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="dailyTrendChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown Table -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list"></i> Category Performance</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Revenue</th>
                                            <th>Profit</th>
                                            <th>Quantity Sold</th>
                                            <th>% of Total</th>
                                            <th>Profit Margin</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_totals as $category => $data): ?>
                                            <?php 
                                            $cat_percentage = $total_income > 0 ? ($data['amount'] / $total_income) * 100 : 0;
                                            $cat_margin = $data['amount'] > 0 ? ($data['profit'] / $data['amount']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($category); ?></strong></td>
                                                <td>KSh <?php echo number_format($data['amount'], 2); ?></td>
                                                <td class="<?php echo $data['profit'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                    KSh <?php echo number_format($data['profit'], 2); ?>
                                                </td>
                                                <td><?php echo number_format($data['quantity']); ?> units</td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span><?php echo number_format($cat_percentage, 1); ?>%</span>
                                                        <div class="ms-2" style="flex: 1; max-width: 100px;">
                                                            <div class="inventory-progress">
                                                                <div class="progress-fill progress-high" 
                                                                     style="width: <?php echo $cat_percentage; ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $cat_margin > 30 ? 'badge-success' : ($cat_margin > 15 ? 'badge-warning' : 'badge-danger'); ?>">
                                                        <?php echo number_format($cat_margin, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($category_totals)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    No sales data available for this period
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Products -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-star"></i> Top 5 Products</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Rank</th>
                                            <th>Product</th>
                                            <th>Quantity Sold</th>
                                            <th>Revenue</th>
                                            <th>Profit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rank = 1;
                                        foreach ($top_products as $product_name => $data): 
                                        ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?php echo $rank == 1 ? 'badge-warning' : 'badge-primary'; ?>">
                                                        #<?php echo $rank; ?>
                                                    </span>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($product_name); ?></strong></td>
                                                <td><?php echo number_format($data['quantity']); ?> units</td>
                                                <td>KSh <?php echo number_format($data['revenue'], 2); ?></td>
                                                <td class="text-success">
                                                    <strong>KSh <?php echo number_format($data['profit'], 2); ?></strong>
                                                </td>
                                            </tr>
                                        <?php 
                                        $rank++;
                                        endforeach; 
                                        ?>
                                        <?php if (empty($top_products)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    No product sales data available
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        // Period selection
        document.getElementById('periodSelect').addEventListener('change', function() {
            if (this.value) {
                window.location.href = 'monthly.php?period_id=' + this.value;
            }
        });

        <?php if ($current_period_id && !empty($category_totals)): ?>
        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($category_totals)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($category_totals, 'amount')); ?>,
                    backgroundColor: [
                        '#29AB87', '#3DAF9A', '#FF6F61', '#3C9D88',
                        '#1B6F5D', '#FF8A7A', '#5BC2B1', '#175C4F'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += 'KSh ' + context.parsed.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Daily Trend Chart
        const trendCtx = document.getElementById('dailyTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_keys($daily_income)); ?>,
                datasets: [{
                    label: 'Daily Income',
                    data: <?php echo json_encode(array_values($daily_income)); ?>,
                    borderColor: '#29AB87',
                    backgroundColor: 'rgba(41, 171, 135, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSh ' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Income: KSh ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
    </script>
</body>
</html>