<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';
require_once 'period_security.php';
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

if (!isLoggedIn() || !isAdmin()) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';

/* -------------------------------------------------------
   PERIOD MANAGEMENT AND BALANCE CALCULATIONS
-------------------------------------------------------- */

// Create inventory periods table if not exists
$create_periods_table = "
CREATE TABLE IF NOT EXISTS inventory_periods (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    period_month VARCHAR(7) NOT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    closing_balance DECIMAL(15,2) DEFAULT 0.00,
    total_sales DECIMAL(15,2) DEFAULT 0.00,
    total_profit DECIMAL(15,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_period (user_id, period_month)
)";

$create_carry_table = "
CREATE TABLE IF NOT EXISTS period_stock_carry (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_id INT(11) NOT NULL,
    product_id INT(11) NOT NULL,
    quantity INT(11) NOT NULL,
    cost_price DECIMAL(10,2) NOT NULL,
    carried_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (period_id) REFERENCES inventory_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)";

$db->query($create_periods_table);
$db->query($create_carry_table);

// Get all available periods for dropdown
function getAllPeriods($db, $user_id) {
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? 
            ORDER BY period_month DESC";
    $stmt = $db->prepare($sql);
    $periods = [];
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
    }
    
    return $periods;
}

// Calculate closing balance (sum of all products' stock value)
function calculateClosingBalance($db, $user_id) {
    $sql = "SELECT SUM(stock_quantity * cost_price) as total_stock_value 
            FROM products WHERE created_by = ?";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['total_stock_value'] ?? 0;
    }
    
    return 0;
}

// Get previous period
function getPreviousPeriod($db, $user_id, $current_period_month) {
    $previous_month = date('Y-m', strtotime($current_period_month . '-01 -1 month'));
    
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? AND period_month = ? 
            ORDER BY created_at DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ss", $user_id, $previous_month);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    return null;
}

// Get next period
function getNextPeriod($db, $user_id, $current_period_month) {
    $next_month = date('Y-m', strtotime($current_period_month . '-01 +1 month'));
    
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? AND period_month = ? 
            ORDER BY created_at DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ss", $user_id, $next_month);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    return null;
}

// Carry forward unsold stock to new period
function carryForwardStock($db, $user_id, $previous_period_id, $current_period_id) {
    // Get products with remaining stock from previous period
    $sql = "SELECT p.id, p.stock_quantity, p.cost_price 
            FROM products p 
            WHERE p.created_by = ? AND p.stock_quantity > 0";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($products as $product) {
            // Record carried forward stock
            $carry_sql = "INSERT INTO period_stock_carry (period_id, product_id, quantity, cost_price, carried_at) 
                          VALUES (?, ?, ?, ?, NOW())";
            $stmt_carry = $db->prepare($carry_sql);
            
            if ($stmt_carry) {
                $quantity = $product['stock_quantity'];
                $cost_price = $product['cost_price'];
                $stmt_carry->bind_param("iiid", $current_period_id, $product['id'], $quantity, $cost_price);
                $stmt_carry->execute();
            }
        }
    }
}

// Get or create current period
function getCurrentPeriod($db, $user_id) {
    $current_month = date('Y-m');
    
    // Check if current period exists
    $period_sql = "SELECT * FROM inventory_periods 
                   WHERE user_id = ? AND period_month = ? 
                   ORDER BY created_at DESC LIMIT 1";
    $stmt = $db->prepare($period_sql);
    
    $current_period = null;
    
    if ($stmt) {
        $stmt->bind_param("ss", $user_id, $current_month);
        $stmt->execute();
        $current_period = $stmt->get_result()->fetch_assoc();
    }
    
    if (!$current_period) {
        // Create new period
        $previous_period = getPreviousPeriod($db, $user_id, $current_month);
        $opening_balance = $previous_period ? ($previous_period['closing_balance'] ?? 0) : 0;
        
        $insert_sql = "INSERT INTO inventory_periods (user_id, period_month, opening_balance, created_at) 
                       VALUES (?, ?, ?, NOW())";
        $stmt = $db->prepare($insert_sql);
        
        if ($stmt) {
            $stmt->bind_param("ssd", $user_id, $current_month, $opening_balance);
            $stmt->execute();
            
            // Get the newly created period
            $current_period_id = $stmt->insert_id;
            $current_period = [
                'id' => $current_period_id,
                'period_month' => $current_month,
                'opening_balance' => $opening_balance,
                'closing_balance' => $opening_balance,
                'total_sales' => 0,
                'total_profit' => 0
            ];
            
            // Carry forward stock from previous period if exists
            if ($previous_period) {
                carryForwardStock($db, $user_id, $previous_period['id'], $current_period_id);
            }
        }
    }
    
    return $current_period;
}

// Get specific period by ID
function getPeriodById($db, $period_id, $user_id) {
    $sql = "SELECT * FROM inventory_periods 
            WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ii", $period_id, $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    return null;
}

// Update period with current financial data
function updatePeriodFinancials($db, $period_id, $user_id, $period_month) {
    $closing_balance = calculateClosingBalance($db, $user_id);
    
    // Calculate total sales and profit for the period
    $sales_sql = "SELECT 
                    SUM(ti.total_price) as total_sales,
                    SUM(ti.total_price - (ti.quantity * p.cost_price)) as total_profit
                  FROM transaction_items ti
                  JOIN products p ON ti.product_id = p.id
                  JOIN transactions t ON ti.transaction_id = t.id
                  WHERE p.created_by = ? AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?";
    $stmt = $db->prepare($sales_sql);
    
    // Ensure values are not null before binding
    $total_sales = 0;
    $total_profit = 0;
    
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $period_month);
        $stmt->execute();
        $sales_data = $stmt->get_result()->fetch_assoc();
        
        $total_sales = $sales_data['total_sales'] ?? 0;
        $total_profit = $sales_data['total_profit'] ?? 0;
    }
    
    $update_sql = "UPDATE inventory_periods 
                   SET closing_balance = ?, total_sales = ?, total_profit = ?, updated_at = NOW()
                   WHERE id = ?";
    $stmt = $db->prepare($update_sql);
    
    if ($stmt) {
        $stmt->bind_param("dddi", $closing_balance, $total_sales, $total_profit, $period_id);
        $stmt->execute();
    }
    
    return [
        'closing_balance' => $closing_balance,
        'total_sales' => $total_sales,
        'total_profit' => $total_profit
    ];
}

/* -------------------------------------------------------
   PERIOD SELECTION AND DATA FETCHING
-------------------------------------------------------- */

// Get all periods for dropdown
$all_periods = getAllPeriods($db, $user_id);

// Handle period selection
$selected_period_id = $_GET['period_id'] ?? null;
$selected_period = null;

if ($selected_period_id) {
    $selected_period = getPeriodById($db, $selected_period_id, $user_id);
}

// If no period selected or selected period doesn't exist, use current period
if (!$selected_period) {
    $selected_period = getCurrentPeriod($db, $user_id);
    $selected_period_id = $selected_period['id'] ?? null;
}

// Update financial data for selected period
if ($selected_period) {
    $financial_data = updatePeriodFinancials($db, $selected_period['id'], $user_id, $selected_period['period_month']);
    
    // Update selected period with latest financial data
    $selected_period['closing_balance'] = $financial_data['closing_balance'];
    $selected_period['total_sales'] = $financial_data['total_sales'];
    $selected_period['total_profit'] = $financial_data['total_profit'];
}

// Get adjacent periods for navigation
$previous_period = $selected_period ? getPreviousPeriod($db, $user_id, $selected_period['period_month']) : null;
$next_period = $selected_period ? getNextPeriod($db, $user_id, $selected_period['period_month']) : null;

// Fetch products with detailed information for selected period
$products_sql = "SELECT 
    p.id, p.name, p.sku, p.category_id, p.cost_price, p.selling_price,
    p.stock_quantity, p.min_stock, p.supplier, p.description,
    c.name as category_name,
    (p.stock_quantity * p.cost_price) as stock_value,
    (p.stock_quantity * p.selling_price) as potential_revenue
FROM products p 
LEFT JOIN categories c ON p.category_id = c.id 
WHERE p.created_by = ? 
ORDER BY p.name ASC";

$stmt_products = $db->prepare($products_sql);
$products = [];

if ($stmt_products) {
    $stmt_products->bind_param("i", $user_id);
    $stmt_products->execute();
    $products = $stmt_products->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch carried forward products for selected period
$carry_sql = "SELECT 
    psc.*, p.name, p.sku, c.name as category_name,
    (psc.quantity * psc.cost_price) as carried_value
FROM period_stock_carry psc
JOIN products p ON psc.product_id = p.id
LEFT JOIN categories c ON p.category_id = c.id
WHERE psc.period_id = ?
ORDER BY p.name ASC";

$stmt_carry = $db->prepare($carry_sql);
$carried_products = [];

if ($stmt_carry && $selected_period_id) {
    $stmt_carry->bind_param("i", $selected_period_id);
    $stmt_carry->execute();
    $carried_products = $stmt_carry->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch transactions for selected period
$transactions_sql = "SELECT 
    ti.id, ti.transaction_id, ti.product_id, ti.quantity, ti.unit_price, ti.total_price,
    p.name, p.sku, p.category_id, c.name as category_name,
    t.transaction_date, t.payment_method,
    (ti.quantity * p.cost_price) as cost_value,
    (ti.total_price - (ti.quantity * p.cost_price)) as profit
FROM transaction_items ti
JOIN products p ON ti.product_id = p.id
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN transactions t ON ti.transaction_id = t.id
WHERE p.created_by = ? AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?
ORDER BY t.transaction_date DESC, ti.id DESC";

$stmt_transactions = $db->prepare($transactions_sql);
$transactions = [];
$total_sold_quantity = 0;

if ($stmt_transactions && $selected_period) {
    $period_month = $selected_period['period_month'];
    $stmt_transactions->bind_param("is", $user_id, $period_month);
    $stmt_transactions->execute();
    $transactions = $stmt_transactions->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($transactions as $transaction) {
        $total_sold_quantity += $transaction['quantity'];
    }
}

/* -------------------------------------------------------
   FINANCIAL CALCULATIONS AND KPIs
-------------------------------------------------------- */

// Products Statistics
$total_products = count($products);
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = $selected_period['closing_balance'] ?? 0;
$total_potential_revenue = 0;

foreach ($products as $product) {
    if ($product['stock_quantity'] <= 0) {
        $out_of_stock_count++;
    } elseif ($product['stock_quantity'] <= $product['min_stock']) {
        $low_stock_count++;
    }
    $total_potential_revenue += $product['potential_revenue'];
}

// Sales Statistics
$total_sales_value = $selected_period['total_sales'] ?? 0;
$total_profit = $selected_period['total_profit'] ?? 0;

// Calculate Cost of Goods Sold (COGS)
$cogs = $total_sales_value - $total_profit;

// Calculate carried forward value
$total_carried_value = 0;
foreach ($carried_products as $carried) {
    $total_carried_value += $carried['carried_value'];
}

// Sales trend calculation (current vs previous period)
if ($previous_period && ($previous_period['total_sales'] ?? 0) > 0) {
    $sales_trend = (($total_sales_value - $previous_period['total_sales']) / $previous_period['total_sales']) * 100;
} else {
    $sales_trend = $total_sales_value > 0 ? 100 : 0;
}

// Stock status classification
function getStockStatus($current, $min) {
    if ($current <= 0) return 'out';
    if ($current <= $min) return 'low';
    if ($current <= ($min * 2)) return 'medium';
    return 'high';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Inventory Management - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .period-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .kpi-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
            height: 100%;
        }
        .kpi-card:hover {
            transform: translateY(-5px);
        }
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .kpi-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
        }
        .trend-positive {
            color: #28a745;
        }
        .trend-negative {
            color: #dc3545;
        }
        .balance-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .carry-forward-card {
            border-left: 4px solid #17a2b8;
        }
        .stock-status-high { color: #28a745; }
        .stock-status-medium { color: #ffc107; }
        .stock-status-low { color: #fd7e14; }
        .stock-status-out { color: #dc3545; }
        .financial-summary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        .inventory-progress {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        .progress-high { background-color: #28a745; }
        .progress-medium { background-color: #ffc107; }
        .progress-low { background-color: #dc3545; }
        .stock-indicator {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .stock-high { background-color: #d4edda; color: #155724; }
        .stock-medium { background-color: #fff3cd; color: #856404; }
        .stock-low { background-color: #f8d7da; color: #721c24; }
        .stock-out { background-color: #f8f9fa; color: #6c757d; }
        .product-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .period-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .period-selector {
            min-width: 250px;
        }
        .period-nav-btn {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .period-nav-btn:hover {
            transform: translateY(-2px);
        }
        .period-info-row {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Period Information -->
                <div class="period-info-card">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h2 class="mb-1">Inventory Period Management</h2>
                            <p class="mb-0">Track opening and closing balances across monthly periods</p>
                        </div>
                        <div class="col-md-6">
                            <div class="period-navigation">
                                <!-- Previous Period Button -->
                                <?php if ($previous_period): ?>
                                    <a href="?period_id=<?= $previous_period['id'] ?>" class="btn btn-outline-light period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        <?= date('F Y', strtotime($previous_period['period_month'] . '-01')) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-outline-light disabled period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        No Previous Period
                                    </span>
                                <?php endif; ?>

                                <!-- Period Selector -->
                                <div class="period-selector">
                                    <select class="form-select" id="periodSelect" onchange="changePeriod(this.value)">
                                        <option value="">Select Period</option>
                                        <?php foreach($all_periods as $period): ?>
                                            <option value="<?= $period['id'] ?>" 
                                                <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>>
                                                <?= date('F Y', strtotime($period['period_month'] . '-01')) ?>
                                                <?= $period['period_month'] == date('Y-m') ? ' (Current)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Next Period Button -->
                                <?php if ($next_period): ?>
                                    <a href="?period_id=<?= $next_period['id'] ?>" class="btn btn-outline-light period-nav-btn">
                                        <?= date('F Y', strtotime($next_period['period_month'] . '-01')) ?>
                                        <i class="fas fa-chevron-right ms-2"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-outline-light disabled period-nav-btn">
                                        No Next Period
                                        <i class="fas fa-chevron-right ms-2"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Period Details -->
                    <?php if ($selected_period): ?>
                    <div class="row period-info-row mt-3">
                        <div class="col-md-4">
                            <strong>Selected Period:</strong>
                            <h4 class="mb-0 mt-1"><?= date('F Y', strtotime($selected_period['period_month'] . '-01')) ?></h4>
                        </div>
                        <div class="col-md-4">
                            <strong>Period Status:</strong>
                            <div class="mt-1">
                                <?php if ($selected_period['period_month'] == date('Y-m')): ?>
                                    <span class="badge bg-success">Current Period</span>
                                <?php elseif ($selected_period['period_month'] < date('Y-m')): ?>
                                    <span class="badge bg-info">Past Period</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Future Period</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group">
                                <button class="btn btn-light" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel me-2"></i>Export Report
                                </button>
                                <button class="btn btn-light" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Print
                                </button>
                            </div>
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

                <?php if (!$selected_period): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No inventory periods found. The system will create a new period automatically.
                    </div>
                <?php else: ?>

                <!-- Financial KPIs -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-primary">KSH <?= number_format($total_stock_value, 2) ?></div>
                                        <div class="kpi-label text-muted">Total Stock Value</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-boxes fa-2x text-primary opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Across <?= $total_products ?> products</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-success">KSH <?= number_format($total_sales_value, 2) ?></div>
                                        <div class="kpi-label text-muted">Total Sales</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-chart-line fa-2x text-success opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted"><?= $total_sold_quantity ?> items sold</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-warning border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-warning">KSH <?= number_format($total_profit, 2) ?></div>
                                        <div class="kpi-label text-muted">Gross Profit</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-coins fa-2x text-warning opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <span class="<?= $sales_trend >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                                        <i class="fas fa-arrow-<?= $sales_trend >= 0 ? 'up' : 'down' ?>"></i>
                                        <?= number_format(abs($sales_trend), 1) ?>%
                                    </span>
                                    vs previous period
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-danger border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-danger"><?= $low_stock_count + $out_of_stock_count ?></div>
                                        <div class="kpi-label text-muted">Stock Alerts</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x text-danger opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted"><?= $low_stock_count ?> low, <?= $out_of_stock_count ?> out</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Balance Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="balance-section">
                            <h5 class="mb-3">
                                <i class="fas fa-play-circle me-2 text-success"></i>
                                Opening Balance
                                <?php if ($previous_period): ?>
                                    <small class="text-muted">(from <?= date('M Y', strtotime($previous_period['period_month'] . '-01')) ?>)</small>
                                <?php endif; ?>
                            </h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fs-4 fw-bold text-success">KSH <?= number_format($selected_period['opening_balance'] ?? 0, 2) ?></span>
                                <i class="fas fa-arrow-right text-muted fa-2x"></i>
                            </div>
                            <small class="text-muted">
                                <?php if ($previous_period): ?>
                                    Carried from <?= date('F Y', strtotime($previous_period['period_month'] . '-01')) ?>
                                <?php else: ?>
                                    Initial opening balance
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="balance-section">
                            <h5 class="mb-3"><i class="fas fa-stop-circle me-2 text-primary"></i>Closing Balance</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fs-4 fw-bold text-primary">KSH <?= number_format($selected_period['closing_balance'] ?? 0, 2) ?></span>
                                <i class="fas fa-equals text-muted fa-2x"></i>
                            </div>
                            <small class="text-muted">Current stock value as of period end</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="financial-summary">
                            <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Financial Summary</h5>
                            <div class="row text-center">
                                <div class="col-6 border-end">
                                    <div class="fw-bold fs-5">KSH <?= number_format($total_sales_value, 2) ?></div>
                                    <small>Total Revenue</small>
                                </div>
                                <div class="col-6">
                                    <div class="fw-bold fs-5">KSH <?= number_format($cogs, 2) ?></div>
                                    <small>COGS</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Carried Forward Products -->
                <?php if (!empty($carried_products)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card carry-forward-card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-forward me-2"></i>
                                    Carried Forward Stock
                                    <span class="badge bg-light text-dark ms-2"><?= count($carried_products) ?> products</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>SKU</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Cost Price</th>
                                                <th>Total Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($carried_products as $carried): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($carried['name']) ?></td>
                                                <td><code><?= htmlspecialchars($carried['sku']) ?></code></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($carried['category_name'] ?? 'Uncategorized') ?></span></td>
                                                <td><?= $carried['quantity'] ?></td>
                                                <td>KSH <?= number_format($carried['cost_price'], 2) ?></td>
                                                <td class="fw-bold">KSH <?= number_format($carried['carried_value'], 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active">
                                                <td colspan="5" class="text-end fw-bold">Total Carried Value:</td>
                                                <td class="fw-bold text-primary">KSH <?= number_format($total_carried_value, 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Current Inventory -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    Current Inventory - <?= date('F Y', strtotime($selected_period['period_month'] . '-01')) ?>
                                    <small class="text-muted">(<?= $total_products ?> products)</small>
                                </h5>
                                <span class="badge bg-primary">Total Value: KSH <?= number_format($total_stock_value, 2) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($products)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="inventoryTable">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>SKU</th>
                                                    <th>Category</th>
                                                    <th>Cost Price</th>
                                                    <th>Selling Price</th>
                                                    <th>Stock Level</th>
                                                    <th>Stock Value</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): 
                                                    $stock_status = getStockStatus($product['stock_quantity'], $product['min_stock']);
                                                    $progress_width = min(($product['stock_quantity'] / max($product['min_stock'] * 3, 1)) * 100, 100);
                                                    $progress_class = [
                                                        'high' => 'progress-high',
                                                        'medium' => 'progress-medium', 
                                                        'low' => 'progress-low',
                                                        'out' => 'progress-low'
                                                    ][$stock_status];
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                                    <?php if ($product['supplier']): ?>
                                                                        <br><small class="text-muted">Supplier: <?= htmlspecialchars($product['supplier']) ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars($product['sku']) ?></code>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['cost_price'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= number_format($product['selling_price'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="stock-status-<?= $stock_status ?> fw-bold">
                                                                    <?= $product['stock_quantity'] ?>
                                                                </span>
                                                                <small class="text-muted">/ min: <?= $product['min_stock'] ?></small>
                                                            </div>
                                                            <div class="inventory-progress mt-1">
                                                                <div class="progress-fill <?= $progress_class ?>" style="width: <?= $progress_width ?>%"></div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['stock_value'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="stock-indicator stock-<?= $stock_status ?>">
                                                                <i class="fas fa-<?= [
                                                                    'high' => 'check-circle',
                                                                    'medium' => 'exclamation-circle',
                                                                    'low' => 'exclamation-triangle',
                                                                    'out' => 'times-circle'
                                                                ][$stock_status] ?>"></i>
                                                                <?= ucfirst($stock_status) ?> Stock
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                        <h4 class="text-muted">No Products in Inventory</h4>
                                        <p class="text-muted">Add products to your inventory to see them here.</p>
                                        <a href="products.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Add Products
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        function changePeriod(periodId) {
            if (periodId) {
                window.location.href = '?period_id=' + periodId;
            }
        }

        function exportToExcel() {
            const table = document.getElementById('inventoryTable');
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, 'Inventory Report');
            
            // Get period name for filename
            const periodSelect = document.getElementById('periodSelect');
            const selectedOption = periodSelect.options[periodSelect.selectedIndex];
            const periodName = selectedOption.text.replace(' (Current)', '').trim();
            
            XLSX.writeFile(wb, `inventory_${periodName.replace(' ', '_')}_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        // Auto-refresh for current period
        <?php if ($selected_period && $selected_period['period_month'] == date('Y-m')): ?>
        setTimeout(function() {
            location.reload();
        }, 30000); // Refresh every 30 seconds for current period
        <?php endif; ?>
    </script>
</body>
</html>