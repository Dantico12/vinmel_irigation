<?php
// Enable full error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once 'config.php';

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

// Validate and sanitize input
$selected_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
if (!$selected_year || $selected_year < 2000 || $selected_year > 2100) {
    $selected_year = (int)date('Y');
}

// Initialize data arrays
$monthly_data = [];
$category_totals = [];
$month_names = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
    7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
];

// Fetch monthly data for the selected year
try {
    $annual_query = "
        SELECT 
            tp.month,
            SUM(t.net_amount) as monthly_income,
            COUNT(t.id) as transaction_count,
            SUM(t.tax_amount) as monthly_tax
        FROM time_periods tp
        LEFT JOIN transactions t ON tp.id = t.time_period_id
        WHERE tp.year = ?
        GROUP BY tp.month
        ORDER BY tp.month
    ";
    $stmt = $conn->prepare($annual_query);
    $stmt->bind_param("i", $selected_year);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $month = (int)$row['month'];
        $monthly_data[$month] = [
            'monthly_income' => (float)$row['monthly_income'],
            'transaction_count' => (int)$row['transaction_count'],
            'monthly_tax' => (float)$row['monthly_tax']
        ];
    }
} catch (Exception $e) {
    error_log("Annual query failed: " . $e->getMessage());
    $monthly_data = [];
}

// Calculate annual totals
$annual_income = 0;
$annual_tax = 0;
$annual_transactions = 0;

foreach ($monthly_data as $data) {
    $annual_income += $data['monthly_income'];
    $annual_tax += $data['monthly_tax'];
    $annual_transactions += $data['transaction_count'];
}

// Fetch category breakdown for the year
try {
    $category_query = "
        SELECT 
            c.name as category_name,
            SUM(ti.total_price) as category_total
        FROM transaction_items ti
        JOIN transactions t ON ti.transaction_id = t.id
        JOIN products p ON ti.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN time_periods tp ON t.time_period_id = tp.id
        WHERE tp.year = ? AND c.type = 'income'
        GROUP BY c.name
        ORDER BY category_total DESC
    ";
    $stmt = $conn->prepare($category_query);
    $stmt->bind_param("i", $selected_year);
    $stmt->execute();
    $category_result = $stmt->get_result();

    while ($row = $category_result->fetch_assoc()) {
        $category_totals[] = [
            'category_name' => htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8'),
            'category_total' => (float)$row['category_total']
        ];
    }
} catch (Exception $e) {
    error_log("Category query failed: " . $e->getMessage());
    $category_totals = [];
}

// --- CRITICAL: Define variables that header.php expects ---
// Get current user from the existing function in config.php
$current_user = getCurrentUser();

// Get current user and period info
$database = new Database();
$db = $database->getConnection();
$user_id = $current_user['id'] ?? 1; // Use default ID if not available

// REMOVED THE FUNCTION DECLARATION - NOW USING THE ONE FROM CONFIG.PHP
$current_period = getCurrentTimePeriod($db);

// Create period info array for display
if ($current_period) {
    $period_info = [
        'exists' => true,
        'period_name' => $current_period['period_name'] ?? 'Unknown Period',
        'date_range' => date('M j, Y', strtotime($current_period['start_date'] ?? date('Y-m-d'))) . " - " . 
                       date('M j, Y', strtotime($current_period['end_date'] ?? date('Y-m-d'))),
        'is_locked' => (bool)($current_period['is_locked'] ?? false),
        'is_active' => (bool)($current_period['is_active'] ?? false)
    ];
} else {
    $period_info = [
        'exists' => false,
        'period_name' => "No Active Period",
        'date_range' => "N/A",
        'is_locked' => true,
        'is_active' => false
    ];
}

// Include header and navigation
include 'header.php'; 
include 'nav_bar.php'; 
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annual Dashboard - Vinmel Business</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <div class="content-area">
            <div class="container-fluid">
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div>
                        <h1><i class="fas fa-chart-bar me-2"></i>Annual Dashboard - <?= (int)$selected_year ?></h1>
                        <p class="text-muted">Yearly financial overview and analytics</p>
                    </div>
                    <div>
                        <div class="annual-year-selector">
                            <select class="form-select" id="yearSelect">
                                <?php for ($year = 2024; $year <= 2028; $year++): ?>
                                    <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Annual Summary Stats -->
                <div class="summary-stats">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value">KSH <?= number_format($annual_income, 2) ?></div>
                        <div class="stat-label">Total Annual Income</div>
                        <small class="text-muted">Net income for <?= (int)$selected_year ?></small>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-value">KSH <?= number_format($annual_tax, 2) ?></div>
                        <div class="stat-label">Total Tax Collected</div>
                        <small class="text-muted">VAT collected</small>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-value"><?= number_format($annual_transactions) ?></div>
                        <div class="stat-label">Total Transactions</div>
                        <small class="text-muted">Sales transactions</small>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mt-4">
                    <div class="col-lg-8">
                        <div class="annual-chart-card">
                            <div class="chart-header">
                                <h5><i class="fas fa-chart-line me-2"></i>Monthly Income Trend - <?= (int)$selected_year ?></h5>
                            </div>
                            <div class="chart-body">
                                <canvas id="monthlyTrendChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="annual-chart-card">
                            <div class="chart-header">
                                <h5><i class="fas fa-chart-pie me-2"></i>Income by Category</h5>
                            </div>
                            <div class="chart-body">
                                <canvas id="categoryChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Breakdown Table -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="annual-chart-card">
                            <div class="chart-header">
                                <h5><i class="fas fa-table me-2"></i>Monthly Breakdown</h5>
                            </div>
                            <div class="chart-body">
                                <div class="table-responsive">
                                    <table class="table annual-table">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Income</th>
                                                <th>Tax</th>
                                                <th>Transactions</th>
                                                <th>Avg. Transaction</th>
                                                <th>Tax %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php for ($month = 1; $month <= 12; $month++): 
                                                $data = $monthly_data[$month] ?? ['monthly_income' => 0, 'monthly_tax' => 0, 'transaction_count' => 0];
                                                $avg_transaction = $data['transaction_count'] > 0 
                                                    ? $data['monthly_income'] / $data['transaction_count'] 
                                                    : 0;
                                                $tax_percentage = $data['monthly_income'] > 0 
                                                    ? ($data['monthly_tax'] / $data['monthly_income']) * 100 
                                                    : 0;
                                            ?>
                                                <tr>
                                                    <td><strong><?= $month_names[$month] ?></strong></td>
                                                    <td class="text-success fw-bold">KSH <?= number_format($data['monthly_income'], 2) ?></td>
                                                    <td class="text-info">KSH <?= number_format($data['monthly_tax'], 2) ?></td>
                                                    <td><span class="badge bg-primary"><?= number_format($data['transaction_count']) ?></span></td>
                                                    <td>KSH <?= number_format($avg_transaction, 2) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $tax_percentage > 15 ? 'danger' : 'success' ?>">
                                                            <?= number_format($tax_percentage, 1) ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active">
                                                <td><strong>TOTAL</strong></td>
                                                <td class="text-success fw-bold">KSH <?= number_format($annual_income, 2) ?></td>
                                                <td class="text-info">KSH <?= number_format($annual_tax, 2) ?></td>
                                                <td><span class="badge bg-primary"><?= number_format($annual_transactions) ?></span></td>
                                                <td>KSH <?= number_format($annual_income / max($annual_transactions, 1), 2) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= ($annual_tax / max($annual_income, 1) * 100) > 15 ? 'danger' : 'success' ?>">
                                                        <?= number_format($annual_tax / max($annual_income, 1) * 100, 1) ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Breakdown -->
                <?php if (!empty($category_totals)): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="annual-chart-card">
                            <div class="chart-header">
                                <h5><i class="fas fa-tags me-2"></i>Category Breakdown</h5>
                            </div>
                            <div class="chart-body">
                                <div class="table-responsive">
                                    <table class="table annual-table">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Total Income</th>
                                                <th>% of Total</th>
                                                <th>Avg. Monthly</th>
                                                <th>Trend</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_category_income = array_sum(array_column($category_totals, 'category_total'));
                                            foreach ($category_totals as $category): 
                                                $percentage = $total_category_income > 0 ? ($category['category_total'] / $total_category_income) * 100 : 0;
                                                $monthly_avg = $category['category_total'] / 12;
                                            ?>
                                                <tr>
                                                    <td><strong><?= $category['category_name'] ?></strong></td>
                                                    <td class="text-success fw-bold">KSH <?= number_format($category['category_total'], 2) ?></td>
                                                    <td>
                                                        <div class="progress">
                                                            <div class="progress-bar" style="width: <?= $percentage ?>%">
                                                                <?= number_format($percentage, 1) ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>KSH <?= number_format($monthly_avg, 2) ?></td>
                                                    <td>
                                                        <span class="trend-indicator positive">
                                                            <i class="fas fa-arrow-up"></i>
                                                            <?= number_format(($percentage / 100) * 10, 1) ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
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
    </div>

    <script>
        // Year selection
        document.getElementById('yearSelect').addEventListener('change', function() {
            window.location.href = 'annual.php?year=' + this.value;
        });

        // Prepare chart data
        const monthlyIncome = [];
        const monthlyTax = [];
        const monthlyLabels = [];
        for (let i = 1; i <= 12; i++) {
            const monthData = <?php echo json_encode($monthly_data); ?>;
            monthlyIncome.push(monthData[i] ? monthData[i].monthly_income : 0);
            monthlyTax.push(monthData[i] ? monthData[i].monthly_tax : 0);
            monthlyLabels.push(<?php echo json_encode(array_values($month_names)); ?>[i-1]);
        }

        // Monthly Trend Chart
        const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [
                    {
                        label: 'Monthly Income',
                        data: monthlyIncome,
                        backgroundColor: 'rgba(40, 167, 69, 0.8)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Monthly Tax',
                        data: monthlyTax,
                        backgroundColor: 'rgba(23, 162, 184, 0.8)',
                        borderColor: 'rgba(23, 162, 184, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        type: 'line'
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSH ' + value.toLocaleString();
                            }
                        },
                        title: {
                            display: true,
                            text: 'Income (KSH)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSH ' + value.toLocaleString();
                            }
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        title: {
                            display: true,
                            text: 'Tax (KSH)'
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': KSH ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Category Chart
        const categoryData = <?php echo json_encode($category_totals); ?>;
        const categoryLabels = categoryData.map(item => item.category_name);
        const categoryValues = categoryData.map(item => item.category_total);

        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryChart = new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryValues,
                    backgroundColor: [
                        '#2c3e50', '#3498db', '#e74c3c', '#2ecc71',
                        '#9b59b6', '#f39c12', '#1abc9c', '#34495e'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: KSH ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>