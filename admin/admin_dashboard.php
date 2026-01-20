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
                <button class="btn btn-outline-primary">
                    <i class="fas fa-download"></i>
                    Export Report
                </button>
            </div>
        </div>
        
        <!-- Current Period Status -->
        <?php if ($current_period): ?>
        <div class="period-status-display">
            <div class="period-info-text">
                <div class="period-name">Current Period: <?php echo date('F Y', strtotime($current_period['year'] . '-' . $current_period['month'] . '-01')); ?></div>
                <div class="period-dates">
                    <?php if ($current_period['start_date'] && $current_period['end_date']): ?>
                        <?php echo date('M j', strtotime($current_period['start_date'])); ?> - <?php echo date('M j, Y', strtotime($current_period['end_date'])); ?>
                    <?php else: ?>
                        Full month period
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge badge-success">
                <i class="fas fa-circle me-1"></i>
                Active
            </span>
        </div>
        <?php endif; ?>
        
        <!-- Stats Grid -->
        <div class="stats-grid-enhanced">
            <!-- Total Income Card -->
            <div class="stat-card-enhanced income">
                <div class="stat-header">
                    <div class="stat-icon-large">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <span class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        Current Period
                    </span>
                </div>
                <div class="stat-content">
                    <div class="stat-value-enhanced">KSH <?php echo number_format($stats['total_income']); ?></div>
                    <div class="stat-label-enhanced">Total Income</div>
                    <div class="stat-period">
                        <i class="fas fa-chart-line me-1"></i>
                        <?php echo $current_period ? date('F Y', strtotime($current_period['year'] . '-' . $current_period['month'] . '-01')) : 'All Time'; ?>
                    </div>
                </div>
            </div>
            
            <!-- Total Profit Card -->
            <div class="stat-card-enhanced profit">
                <div class="stat-header">
                    <div class="stat-icon-large">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <span class="stat-trend">
                        <i class="fas fa-chart-line"></i>
                        Real Profit
                    </span>
                </div>
                <div class="stat-content">
                    <div class="stat-value-enhanced">KSH <?php echo number_format($stats['total_profit']); ?></div>
                    <div class="stat-label-enhanced">Total Profit</div>
                    <div class="stat-period">
                        <i class="fas fa-calculator me-1"></i>
                        Sales - Cost
                    </div>
                </div>
            </div>
            
            <!-- Products Card -->
            <div class="stat-card-enhanced products">
                <div class="stat-header">
                    <div class="stat-icon-large">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <span class="stat-trend">
                        <i class="fas fa-cube"></i>
                        In Inventory
                    </span>
                </div>
                <div class="stat-content">
                    <div class="stat-value-enhanced"><?php echo $stats['product_count']; ?></div>
                    <div class="stat-label-enhanced">Active Products</div>
                    <div class="stat-period">
                        <i class="fas fa-warehouse me-1"></i>
                        Total Inventory Items
                    </div>
                </div>
            </div>
            
            <!-- Transactions Card -->
            <div class="stat-card-enhanced transactions">
                <div class="stat-header">
                    <div class="stat-icon-large">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <span class="stat-trend">
                        <i class="fas fa-receipt"></i>
                        <?php echo $current_period_id ? 'Current Period' : 'All Time'; ?>
                    </span>
                </div>
                <div class="stat-content">
                    <div class="stat-value-enhanced"><?php echo $stats['transaction_count']; ?></div>
                    <div class="stat-label-enhanced">Total Transactions</div>
                    <div class="stat-period">
                        <i class="fas fa-history me-1"></i>
                        <?php echo $current_period ? date('F Y', strtotime($current_period['year'] . '-' . $current_period['month'] . '-01')) : 'All Time'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Grid -->
        <div class="charts-grid-enhanced">
            <!-- Monthly Income Chart -->
            <div class="chart-card-enhanced">
                <div class="chart-header-enhanced">
                    <h3 class="chart-title-enhanced">
                        <i class="fas fa-chart-bar me-2"></i>
                        Monthly Income (Last 6 Months)
                    </h3>
                </div>
                <div class="chart-body-enhanced">
                    <canvas id="incomeChart"></canvas>
                </div>
            </div>
            
            <!-- Sales by Category Chart -->
            <div class="chart-card-enhanced">
                <div class="chart-header-enhanced">
                    <h3 class="chart-title-enhanced">
                        <i class="fas fa-chart-pie me-2"></i>
                        Sales by Category
                    </h3>
                </div>
                <div class="chart-body-enhanced">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Activity Grid -->
        <div class="activity-grid">
            <!-- Recent Transactions -->
            <div class="activity-card">
                <div class="activity-card-header">
                    <h3 class="activity-card-title">
                        <i class="fas fa-clock me-2"></i>
                        Recent Transactions
                    </h3>
                    <a href="transactions.php" class="btn btn-outline-primary btn-sm">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="activity-card-body p-0">
                    <div class="table-responsive">
                        <table class="recent-transactions-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt #</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $transaction['receipt_number']; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['user_name']); ?></td>
                                    <td class="transaction-amount">KSH <?php echo number_format($transaction['total_amount'], 2); ?></td>
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
            <div class="activity-card low-stock-alert">
                <div class="activity-card-header">
                    <h3 class="activity-card-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Low Stock Alert
                    </h3>
                </div>
                <div class="activity-card-body">
                    <?php if($stats['low_stock_count'] > 0): ?>
                        <div class="text-center mb-4">
                            <div class="low-stock-count"><?php echo $stats['low_stock_count']; ?></div>
                            <div class="stat-label">Items Need Restocking</div>
                        </div>
                        <div class="mb-4">
                            <?php foreach($low_stock_products as $product): 
                                $stock_percentage = ($product['stock_quantity'] / $product['min_stock']) * 100;
                            ?>
                            <div class="low-stock-item">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="text-muted small">
                                        Stock: <?php echo $product['stock_quantity']; ?> / <?php echo $product['min_stock']; ?>
                                    </div>
                                    <div class="progress mt-1" style="height: 4px;">
                                        <div class="progress-bar bg-warning" role="progressbar" 
                                             style="width: <?php echo min(100, $stock_percentage); ?>%"
                                             aria-valuenow="<?php echo $stock_percentage; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </div>
                                <span class="stock-warning-badge">
                                    <?php echo $product['stock_quantity']; ?> left
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <h5 class="mb-2">All Stock Levels Good</h5>
                            <p class="text-muted">All items are well stocked and ready for sale!</p>
                        </div>
                    <?php endif; ?>
                    <div class="text-center">
                        <a href="products.php" class="btn btn-primary">
                            <i class="fas fa-boxes me-1"></i>
                            Manage Inventory
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="add_transaction.php" class="quick-action-btn">
                <i class="fas fa-plus-circle"></i>
                <span class="quick-action-label">New Sale</span>
            </a>
            <a href="add_product.php" class="quick-action-btn">
                <i class="fas fa-box"></i>
                <span class="quick-action-label">Add Product</span>
            </a>
            <a href="reports.php" class="quick-action-btn">
                <i class="fas fa-chart-pie"></i>
                <span class="quick-action-label">View Reports</span>
            </a>
            <a href="expenses.php" class="quick-action-btn">
                <i class="fas fa-file-invoice-dollar"></i>
                <span class="quick-action-label">Manage Expenses</span>
            </a>
            <a href="monthly.php" class="quick-action-btn">
                <i class="fas fa-chart-line"></i>
                <span class="quick-action-label">Analytics</span>
            </a>
        </div>
    </main>
    
    <!-- JavaScript -->
    <script src="main.js"></script>
    <script>
        // Initialize Charts with Real Data
        document.addEventListener('DOMContentLoaded', function() {
            // Color Scheme
            const colors = {
                primary: '#3498db',
                success: '#27ae60',
                warning: '#f39c12',
                danger: '#e74c3c',
                info: '#17a2b8',
                dark: '#2c3e50'
            };
            
            // Chart Background Colors
            const chartBackgrounds = [
                '#3498db', '#27ae60', '#f39c12', '#e74c3c', '#9b59b6',
                '#34495e', '#1abc9c', '#d35400', '#c0392b', '#8e44ad'
            ];
            
            // Monthly Income Chart
            const incomeCtx = document.getElementById('incomeChart').getContext('2d');
            const incomeChart = new Chart(incomeCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_data['month_names']); ?>,
                    datasets: [{
                        label: 'Monthly Income',
                        data: <?php echo json_encode($chart_data['monthly_income']); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: colors.primary,
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                        hoverBackgroundColor: 'rgba(52, 152, 219, 1)',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: colors.dark,
                                font: {
                                    size: 12,
                                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                },
                                padding: 20
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: colors.dark,
                            bodyColor: colors.dark,
                            borderColor: '#ecf0f1',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return 'KSH ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#7f8c8d',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(236, 240, 241, 0.5)'
                            },
                            ticks: {
                                color: '#7f8c8d',
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    if (value >= 1000) {
                                        return 'KSH ' + (value / 1000).toFixed(0) + 'K';
                                    }
                                    return 'KSH ' + value;
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
            
            // Category Chart
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            const categoryChart = new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_data['category_names']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_data['category_sales']); ?>,
                        backgroundColor: chartBackgrounds,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 15,
                        hoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: colors.dark,
                                font: {
                                    size: 11,
                                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                },
                                padding: 20,
                                boxWidth: 12,
                                boxHeight: 12
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: colors.dark,
                            bodyColor: colors.dark,
                            borderColor: '#ecf0f1',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: KSH ${value.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
            
            // Add animation to stats cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observe stat cards
            document.querySelectorAll('.stat-card-enhanced').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
            
            // Observe chart cards
            document.querySelectorAll('.chart-card-enhanced').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease 0.2s, transform 0.6s ease 0.2s';
                observer.observe(card);
            });
        });
    </script>
</body>
</html>