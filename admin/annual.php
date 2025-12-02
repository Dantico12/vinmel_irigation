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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annual Dashboard - Vinmel Business</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="style.css" rel="stylesheet">
    <style>
        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: center;
        }
        .stat-card h6 {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .stat-card h3 {
            font-size: 1.75rem;
            margin: 0;
            color: #212529;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Annual Dashboard - <?= (int)$selected_year ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
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

                <!-- Annual Summary -->
                <div class="summary-stats">
                    <div class="stat-card">
                        <h6>Total Annual Income</h6>
                        <h3>KSH <?= number_format($annual_income, 2) ?></h3>
                        <small class="text-muted">Net income for <?= (int)$selected_year ?></small>
                    </div>
                    <div class="stat-card">
                        <h6>Total Tax Collected</h6>
                        <h3>KSH <?= number_format($annual_tax, 2) ?></h3>
                        <small class="text-muted">VAT collected</small>
                    </div>
                    <div class="stat-card">
                        <h6>Total Transactions</h6>
                        <h3><?= number_format($annual_transactions) ?></h3>
                        <small class="text-muted">Sales transactions</small>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="chart-container">
                            <h5>Monthly Income Trend - <?= (int)$selected_year ?></h5>
                            <canvas id="monthlyTrendChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="chart-container">
                            <h5>Income by Category</h5>
                            <canvas id="categoryChart" height="200"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Monthly Breakdown -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="chart-container">
                            <h5>Monthly Breakdown</h5>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th>Income</th>
                                            <th>Tax</th>
                                            <th>Transactions</th>
                                            <th>Avg. Transaction</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($month = 1; $month <= 12; $month++): 
                                            $data = $monthly_data[$month] ?? ['monthly_income' => 0, 'monthly_tax' => 0, 'transaction_count' => 0];
                                            $avg_transaction = $data['transaction_count'] > 0 
                                                ? $data['monthly_income'] / $data['transaction_count'] 
                                                : 0;
                                        ?>
                                            <tr>
                                                <td><?= $month_names[$month] ?></td>
                                                <td>KSH <?= number_format($data['monthly_income'], 2) ?></td>
                                                <td>KSH <?= number_format($data['monthly_tax'], 2) ?></td>
                                                <td><?= number_format($data['transaction_count']) ?></td>
                                                <td>KSH <?= number_format($avg_transaction, 2) ?></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Year selection
        document.getElementById('yearSelect').addEventListener('change', function() {
            window.location.href = 'annual.php?year=' + this.value;
        });

        // Prepare chart data
        const monthlyIncome = [];
        const monthlyLabels = [];
        for (let i = 1; i <= 12; i++) {
            const monthData = <?php echo json_encode($monthly_data); ?>;
            monthlyIncome.push(monthData[i] ? monthData[i].monthly_income : 0);
            monthlyLabels.push(<?php echo json_encode(array_values($month_names)); ?>[i-1]);
        }

        // Monthly Trend Chart
        const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Monthly Income',
                    data: monthlyIncome,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSH ' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'KSH ' + context.parsed.y.toLocaleString();
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
            type: 'pie',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryValues,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
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