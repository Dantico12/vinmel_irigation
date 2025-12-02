<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
checkAuth();

// Get current user and period info
$current_user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Get current period
function getCurrentPeriod($db) {
    $query = "SELECT * FROM time_periods WHERE is_active = 1 ORDER BY year DESC, month DESC LIMIT 1";
    $result = $db->query($query);
    return $result ? $result->fetch_assoc() : null;
}

$current_period = getCurrentPeriod($db);
$current_period_id = $current_period ? $current_period['id'] : null;

// Get dashboard stats with period filtering
function getDashboardStats($db, $period_id = null) {
    $stats = [];
    
    // Build WHERE clause for period
    $period_where = $period_id ? "WHERE time_period_id = ?" : "";
    $period_params = $period_id ? [$period_id] : [];
    
    // Total Income
    $query = "SELECT SUM(total_amount) as total FROM transactions $period_where";
    $stmt = $db->prepare($query);
    if ($period_id) {
        $stmt->bind_param("i", $period_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_income'] = $row['total'] ?: 0;
    
    // Calculate real profit (total sales - total cost)
    $profit_query = "SELECT SUM(ti.quantity * (ti.unit_price - p.cost_price)) as profit 
                    FROM transaction_items ti 
                    JOIN products p ON ti.product_id = p.id 
                    JOIN transactions t ON ti.transaction_id = t.id";
    if ($period_id) {
        $profit_query .= " WHERE t.time_period_id = ?";
    }
    $stmt = $db->prepare($profit_query);
    if ($period_id) {
        $stmt->bind_param("i", $period_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_profit'] = $row['profit'] ?: 0;
    
    // Product Count
    $query = "SELECT COUNT(*) as count FROM products";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['product_count'] = $row['count'];
    
    // Transaction Count (current period or all time)
    $query = "SELECT COUNT(*) as count FROM transactions";
    if ($period_id) {
        $query .= " WHERE time_period_id = ?";
    }
    $stmt = $db->prepare($query);
    if ($period_id) {
        $stmt->bind_param("i", $period_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['transaction_count'] = $row['count'];
    
    // Low Stock Items
    $query = "SELECT COUNT(*) as count FROM products WHERE stock_quantity <= min_stock";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['low_stock_count'] = $row['count'];
    
    return $stats;
}

// Get real data for charts
function getChartData($db, $period_id = null) {
    $chart_data = [];
    
    // Monthly Income Data (last 6 months)
    $monthly_query = "SELECT 
        YEAR(transaction_date) as year,
        MONTH(transaction_date) as month,
        SUM(total_amount) as income
        FROM transactions 
        WHERE transaction_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
        GROUP BY YEAR(transaction_date), MONTH(transaction_date)
        ORDER BY year, month";
    
    $result = $db->query($monthly_query);
    $monthly_income = [];
    $month_names = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $month_names[] = date('M', mktime(0, 0, 0, $row['month'], 1));
            $monthly_income[] = (float)$row['income'];
        }
    }
    
    // Fill missing months with zero
    while (count($month_names) < 6) {
        array_unshift($month_names, date('M', strtotime('-' . (6 - count($month_names)) . ' months')));
        array_unshift($monthly_income, 0);
    }
    
    $chart_data['monthly_income'] = $monthly_income;
    $chart_data['month_names'] = array_slice($month_names, -6); // Last 6 months
    
    // Sales by Category
    $category_query = "SELECT 
        c.name as category_name,
        SUM(ti.total_price) as total_sales
        FROM transaction_items ti
        JOIN products p ON ti.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN transactions t ON ti.transaction_id = t.id";
    
    if ($period_id) {
        $category_query .= " WHERE t.time_period_id = ?";
    }
    
    $category_query .= " GROUP BY c.id, c.name ORDER BY total_sales DESC";
    
    $stmt = $db->prepare($category_query);
    if ($period_id) {
        $stmt->bind_param("i", $period_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $category_sales = [];
    $category_names = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $category_names[] = $row['category_name'];
            $category_sales[] = (float)$row['total_sales'];
        }
    }
    
    $chart_data['category_sales'] = $category_sales;
    $chart_data['category_names'] = $category_names;
    
    return $chart_data;
}

// Get low stock products
function getLowStockProducts($db) {
    $query = "SELECT name, stock_quantity, min_stock 
              FROM products 
              WHERE stock_quantity <= min_stock 
              ORDER BY stock_quantity ASC 
              LIMIT 5";
    
    $result = $db->query($query);
    $low_stock_products = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $low_stock_products[] = $row;
        }
    }
    
    return $low_stock_products;
}

$stats = getDashboardStats($db, $current_period_id);
$chart_data = getChartData($db, $current_period_id);
$low_stock_products = getLowStockProducts($db);

// Get recent transactions
$query = "SELECT t.*, u.name as user_name 
          FROM transactions t 
          JOIN users u ON t.user_id = u.id 
          ORDER BY t.transaction_date DESC 
          LIMIT 5";
$result = $db->query($query);
$recent_transactions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_transactions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VINMEL IRRIGATION - Admin Dashboard</title>
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="style.css">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Header Component -->
    <?php include 'header.php'; ?>
    
    <!-- Sidebar Navigation -->
    <?php include 'nav_bar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Content Header -->
        <div class="content-header">
            <div>
                <h1 class="content-title">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard Overview
                </h1>
                <p class="content-subtitle">
                    Welcome back, <?php echo htmlspecialchars($current_user['name']); ?>! 
                    Here's what's happening with your business today.
                </p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted">
                    <i class="fas fa-calendar me-1"></i>
                    <?php echo date('F j, Y'); ?>
                </span>
                <button class="btn btn-outline">
                    <i class="fas fa-download"></i>
                    Export Report
                </button>
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <!-- Total Income Card -->
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value">KSH <?php echo number_format($stats['total_income']); ?></div>
                <div class="stat-label">Total Income</div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i>
                    Current Period
                </div>
            </div>
            
            <!-- Total Profit Card -->
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value">KSH <?php echo number_format($stats['total_profit']); ?></div>
                <div class="stat-label">Total Profit</div>
                <div class="stat-change positive">
                    <i class="fas fa-chart-line"></i>
                    Real Profit
                </div>
            </div>
            
            <!-- Products Card -->
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-value"><?php echo $stats['product_count']; ?></div>
                <div class="stat-label">Active Products</div>
                <div class="stat-change positive">
                    <i class="fas fa-cube"></i>
                    In Inventory
                </div>
            </div>
            
            <!-- Transactions Card -->
            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['transaction_count']; ?></div>
                <div class="stat-label">Total Transactions</div>
                <div class="stat-change positive">
                    <i class="fas fa-receipt"></i>
                    All Time
                </div>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="d-grid" style="grid-template-columns: 2fr 1fr; gap: var(--spacing-lg);">
            <!-- Monthly Income Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-bar me-2"></i>
                        Monthly Income (Last 6 Months)
                    </h3>
                </div>
                <canvas id="incomeChart" height="300"></canvas>
            </div>
            
            <!-- Sales by Category Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-pie me-2"></i>
                        Sales by Category
                    </h3>
                </div>
                <canvas id="categoryChart" height="300"></canvas>
            </div>
        </div>
        
        <!-- Recent Activity & Low Stock -->
        <div class="d-grid" style="grid-template-columns: 2fr 1fr; gap: var(--spacing-lg); margin-top: var(--spacing-lg);">
            <!-- Recent Transactions -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock me-2"></i>
                        Recent Transactions
                    </h3>
                    <a href="transactions.php" class="btn btn-outline">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt #</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td><?php echo $transaction['receipt_number']; ?></td>
                                    <td>KSH <?php echo number_format($transaction['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check me-1"></i>
                                            Completed
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Low Stock Alert -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                        Low Stock Alert
                    </h3>
                </div>
                <div class="card-body">
                    <?php if($stats['low_stock_count'] > 0): ?>
                        <div class="text-center mb-4">
                            <div class="stat-value text-warning"><?php echo $stats['low_stock_count']; ?></div>
                            <div class="stat-label">Items Need Restocking</div>
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach($low_stock_products as $product): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span><?php echo htmlspecialchars($product['name']); ?></span>
                                <span class="badge badge-warning"><?php echo $product['stock_quantity']; ?> left</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <p>All items are well stocked!</p>
                        </div>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="products.php" class="btn btn-primary">
                            <i class="fas fa-boxes me-1"></i>
                            Manage Inventory
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- JavaScript -->
    <script src="main.js"></script>
    <script>
        // Initialize Charts with Real Data
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Income Chart
            const incomeCtx = document.getElementById('incomeChart').getContext('2d');
            new Chart(incomeCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_data['month_names']); ?>,
                    datasets: [{
                        label: 'Income',
                        data: <?php echo json_encode($chart_data['monthly_income']); ?>,
                        backgroundColor: '#27ae60',
                        borderColor: '#27ae60',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'KSH ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'KSH ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Category Chart
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_data['category_names']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_data['category_sales']); ?>,
                        backgroundColor: [
                            '#3498db', '#27ae60', '#f39c12', '#e74c3c', '#9b59b6',
                            '#34495e', '#1abc9c', '#d35400', '#c0392b', '#8e44ad'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': KSH ' + context.parsed.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>