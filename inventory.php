<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    // Check if this is an API call
    if (isset($_GET['action']) && $_GET['action'] !== '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    } else {
        header("Location: login.php");
        exit();
    }
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'admin';

if (!$user_id) {
    header("Location: login.php");
    exit();
}

$message = '';
$error = '';

/* -------------------------------------------------------
   INVENTORY PERIOD MANAGEMENT FUNCTIONS
-------------------------------------------------------- */

// Get all inventory periods (now global)
function getAllInventoryPeriods($db) {
    $periods = [];
    
    $sql = "SELECT 
                ip.*,
                tp.period_name,
                tp.year,
                tp.month,
                CONCAT(tp.year, '-', LPAD(tp.month, 2, '0')) as period_month_display,
                tp.is_locked
            FROM inventory_periods ip
            JOIN time_periods tp ON ip.time_period_id = tp.id
            ORDER BY tp.year DESC, tp.month DESC";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
    }
    
    return $periods;
}

// Get inventory period by ID
function getInventoryPeriodById($db, $period_id) {
    $sql = "SELECT 
                ip.*,
                tp.period_name,
                tp.year,
                tp.month,
                CONCAT(tp.year, '-', LPAD(tp.month, 2, '0')) as period_month_display
            FROM inventory_periods ip
            JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE ip.id = ?";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return null;
}

// Get current inventory period
function getCurrentInventoryPeriod($db) {
    $current_month = date('Y-m');
    
    $sql = "SELECT 
                ip.*,
                tp.period_name
            FROM inventory_periods ip
            JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE CONCAT(tp.year, '-', LPAD(tp.month, 2, '0')) = ?
            ORDER BY tp.year DESC, tp.month DESC
            LIMIT 1";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $current_month);
        $stmt->execute();
        $period = $stmt->get_result()->fetch_assoc();
        
        if (!$period) {
            // Get the most recent period
            $recent_sql = "SELECT 
                            ip.*,
                            tp.period_name
                          FROM inventory_periods ip
                          JOIN time_periods tp ON ip.time_period_id = tp.id
                          ORDER BY tp.year DESC, tp.month DESC
                          LIMIT 1";
            $recent_stmt = $db->prepare($recent_sql);
            $recent_stmt->execute();
            $period = $recent_stmt->get_result()->fetch_assoc();
        }
        
        return $period;
    }
    return null;
}

// Calculate inventory value for period
function calculatePeriodInventoryValue($db, $time_period_id) {
    $sql = "SELECT SUM(stock_quantity * cost_price) as total_value 
            FROM products 
            WHERE period_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $time_period_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['total_value'] ?? 0;
}

// Get or create inventory period - FIXED VERSION
function getOrCreateInventoryPeriod($db, $time_period_id) {
    // First, get the time period details
    $time_period_sql = "SELECT * FROM time_periods WHERE id = ?";
    $time_stmt = $db->prepare($time_period_sql);
    $time_stmt->bind_param("i", $time_period_id);
    $time_stmt->execute();
    $current_time_period = $time_stmt->get_result()->fetch_assoc();
    
    if (!$current_time_period) {
        return null;
    }
    
    // Check if inventory period already exists
    $sql = "SELECT * FROM inventory_periods WHERE time_period_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $time_period_id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    
    if (!$period) {
        // Get previous period's closing balance - FIXED QUERY
        $prev_sql = "SELECT ip.closing_balance 
                     FROM inventory_periods ip
                     JOIN time_periods tp ON ip.time_period_id = tp.id
                     WHERE (tp.year < ?) OR (tp.year = ? AND tp.month < ?)
                     ORDER BY tp.year DESC, tp.month DESC 
                     LIMIT 1";
        $prev_stmt = $db->prepare($prev_sql);
        $prev_stmt->bind_param("iii", 
            $current_time_period['year'], 
            $current_time_period['year'], 
            $current_time_period['month']
        );
        $prev_stmt->execute();
        $prev_result = $prev_stmt->get_result();
        $prev_period = $prev_result->fetch_assoc();
        
        $opening_balance = $prev_period ? $prev_period['closing_balance'] : 0;
        
        // Calculate current inventory from products
        $current_inventory = calculatePeriodInventoryValue($db, $time_period_id);
        $closing_balance = $opening_balance + $current_inventory;
        
        // Create new period
        $insert_sql = "INSERT INTO inventory_periods 
                      (time_period_id, opening_balance, current_inventory, 
                       closing_balance, status) 
                      VALUES (?, ?, ?, ?, 'active')";
        $insert_stmt = $db->prepare($insert_sql);
        $insert_stmt->bind_param("iddd", 
            $time_period_id, 
            $opening_balance, 
            $current_inventory, 
            $closing_balance
        );
        
        if ($insert_stmt->execute()) {
            return [
                'id' => $insert_stmt->insert_id,
                'time_period_id' => $time_period_id,
                'opening_balance' => $opening_balance,
                'current_inventory' => $current_inventory,
                'closing_balance' => $closing_balance,
                'status' => 'active',
                'period_name' => $current_time_period['period_name'],
                'year' => $current_time_period['year'],
                'month' => $current_time_period['month']
            ];
        }
    } else {
        // If period exists, calculate current inventory and update it
        $current_inventory = calculatePeriodInventoryValue($db, $time_period_id);
        $closing_balance = $period['opening_balance'] + $current_inventory;
        
        // Update the period
        $update_sql = "UPDATE inventory_periods 
                      SET current_inventory = ?, 
                          closing_balance = ?,
                          updated_at = NOW()
                      WHERE time_period_id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("ddi", $current_inventory, $closing_balance, $time_period_id);
        $update_stmt->execute();
        
        // Get updated period
        $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month 
                FROM inventory_periods ip
                JOIN time_periods tp ON ip.time_period_id = tp.id
                WHERE ip.time_period_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $time_period_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    return $period;
}

// Update inventory period
function updateInventoryPeriod($db, $inventory_period_id) {
    $sql = "SELECT * FROM inventory_periods WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $inventory_period_id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    
    if ($period) {
        $current_inventory = calculatePeriodInventoryValue($db, $period['time_period_id']);
        $closing_balance = $period['opening_balance'] + $current_inventory;
        
        $update_sql = "UPDATE inventory_periods 
                       SET current_inventory = ?, 
                           closing_balance = ?,
                           updated_at = NOW()
                       WHERE id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("ddi", $current_inventory, $closing_balance, $inventory_period_id);
        $update_stmt->execute();
    }
}

// Get period sales data
function calculatePeriodSalesData($db, $time_period_id) {
    $sql = "SELECT 
                COALESCE(SUM(t.net_amount), 0) as total_sales,
                COALESCE(SUM(t.net_amount - (ti.quantity * p.cost_price)), 0) as total_profit,
                COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
            FROM transactions t
            JOIN transaction_items ti ON t.id = ti.transaction_id
            JOIN products p ON ti.product_id = p.id
            WHERE t.time_period_id = ?";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $time_period_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
}

// Update all inventory periods (call this when products change)
function updateAllInventoryPeriods($db) {
    // Get all time periods in chronological order
    $sql = "SELECT id, year, month FROM time_periods ORDER BY year, month";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $time_periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $previous_closing_balance = 0;
    
    foreach ($time_periods as $time_period) {
        $time_period_id = $time_period['id'];
        
        // Calculate current inventory value
        $current_inventory = calculatePeriodInventoryValue($db, $time_period_id);
        
        // Check if inventory period exists
        $check_sql = "SELECT id FROM inventory_periods WHERE time_period_id = ?";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->bind_param("i", $time_period_id);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc();
        
        if ($exists) {
            // Update existing period
            $update_sql = "UPDATE inventory_periods 
                          SET opening_balance = ?, 
                              current_inventory = ?,
                              closing_balance = ?,
                              updated_at = NOW()
                          WHERE time_period_id = ?";
            $update_stmt = $db->prepare($update_sql);
            $closing_balance = $previous_closing_balance + $current_inventory;
            $update_stmt->bind_param("dddi", 
                $previous_closing_balance, 
                $current_inventory, 
                $closing_balance, 
                $time_period_id
            );
            $update_stmt->execute();
        } else {
            // Create new period
            $insert_sql = "INSERT INTO inventory_periods 
                          (time_period_id, opening_balance, current_inventory, 
                           closing_balance, status) 
                          VALUES (?, ?, ?, ?, 'active')";
            $insert_stmt = $db->prepare($insert_sql);
            $closing_balance = $previous_closing_balance + $current_inventory;
            $insert_stmt->bind_param("iddd", 
                $time_period_id, 
                $previous_closing_balance, 
                $current_inventory, 
                $closing_balance
            );
            $insert_stmt->execute();
        }
        
        // Update previous closing balance for next iteration
        $previous_closing_balance = $closing_balance;
    }
}

// Sync inventory after product changes
function syncInventoryAfterProductChange($db, $time_period_id) {
    // Update current period
    if ($time_period_id) {
        // First, update the current period
        $period = getOrCreateInventoryPeriod($db, $time_period_id);
        
        // Get all time periods in chronological order starting from current period
        $sql = "SELECT id FROM time_periods 
                WHERE (year > (SELECT year FROM time_periods WHERE id = ?)) 
                   OR (year = (SELECT year FROM time_periods WHERE id = ?) 
                       AND month > (SELECT month FROM time_periods WHERE id = ?))
                ORDER BY year, month";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iii", $time_period_id, $time_period_id, $time_period_id);
        $stmt->execute();
        $subsequent_periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get opening balance for current period
        $opening_sql = "SELECT opening_balance FROM inventory_periods WHERE time_period_id = ?";
        $opening_stmt = $db->prepare($opening_sql);
        $opening_stmt->bind_param("i", $time_period_id);
        $opening_stmt->execute();
        $opening_result = $opening_stmt->get_result()->fetch_assoc();
        $current_opening_balance = $opening_result['opening_balance'] ?? 0;
        
        // Recalculate current period
        $current_inventory = calculatePeriodInventoryValue($db, $time_period_id);
        $current_closing = $current_opening_balance + $current_inventory;
        
        $update_current_sql = "UPDATE inventory_periods 
                              SET current_inventory = ?, 
                                  closing_balance = ?,
                                  updated_at = NOW()
                              WHERE time_period_id = ?";
        $update_current_stmt = $db->prepare($update_current_sql);
        $update_current_stmt->bind_param("ddi", $current_inventory, $current_closing, $time_period_id);
        $update_current_stmt->execute();
        
        $next_opening_balance = $current_closing;
        
        // Update all subsequent periods
        foreach ($subsequent_periods as $subsequent_period) {
            $sub_time_period_id = $subsequent_period['id'];
            $sub_inventory = calculatePeriodInventoryValue($db, $sub_time_period_id);
            $sub_closing = $next_opening_balance + $sub_inventory;
            
            $update_sub_sql = "UPDATE inventory_periods 
                              SET opening_balance = ?,
                                  current_inventory = ?, 
                                  closing_balance = ?,
                                  updated_at = NOW()
                              WHERE time_period_id = ?";
            $update_sub_stmt = $db->prepare($update_sub_sql);
            $update_sub_stmt->bind_param("dddi", 
                $next_opening_balance, 
                $sub_inventory, 
                $sub_closing, 
                $sub_time_period_id
            );
            $update_sub_stmt->execute();
            
            $next_opening_balance = $sub_closing;
        }
    }
}

/* -------------------------------------------------------
   HANDLE AJAX SYNC REQUESTS
-------------------------------------------------------- */

// Check if this is an AJAX request
if (isset($_GET['action']) && $_GET['action'] !== '') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'sync_all':
            try {
                updateAllInventoryPeriods($db);
                echo json_encode(['success' => true, 'message' => 'All inventory periods synced successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'sync_period':
            $time_period_id = $_GET['time_period_id'] ?? null;
            if (!$time_period_id) {
                echo json_encode(['success' => false, 'message' => 'No time period ID provided']);
                exit();
            }
            
            try {
                syncInventoryAfterProductChange($db, $time_period_id);
                echo json_encode(['success' => true, 'message' => 'Period inventory synced successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'recalculate':
            $period_id = $_GET['period_id'] ?? null;
            if (!$period_id) {
                echo json_encode(['success' => false, 'message' => 'No period ID provided']);
                exit();
            }
            
            try {
                // Get the period details
                $period = getInventoryPeriodById($db, $period_id);
                if (!$period) {
                    echo json_encode(['success' => false, 'message' => 'Period not found']);
                    exit();
                }
                
                // Recalculate this period
                updateInventoryPeriod($db, $period_id);
                
                // Also update subsequent periods
                syncInventoryAfterProductChange($db, $period['time_period_id']);
                
                echo json_encode(['success' => true, 'message' => 'Period recalculated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
    }
}

/* -------------------------------------------------------
   MAIN LOGIC FOR PAGE DISPLAY
-------------------------------------------------------- */

// Update all inventory periods to ensure they're in sync
updateAllInventoryPeriods($db);

// Handle period selection
$selected_period_id = $_GET['period_id'] ?? null;

// Get all periods
$all_periods = getAllInventoryPeriods($db);

// Get current or selected period
if ($selected_period_id) {
    $selected_period = getInventoryPeriodById($db, $selected_period_id);
} else {
    $selected_period = getCurrentInventoryPeriod($db);
}

// If no period found, create for current time period
if (!$selected_period) {
    // Get current time period
    $current_month = date('Y-m');
    $time_period_sql = "SELECT * FROM time_periods 
                       WHERE CONCAT(year, '-', LPAD(month, 2, '0')) = ?
                       LIMIT 1";
    $time_stmt = $db->prepare($time_period_sql);
    $time_stmt->bind_param("s", $current_month);
    $time_stmt->execute();
    $time_period = $time_stmt->get_result()->fetch_assoc();
    
    if ($time_period) {
        $selected_period = getOrCreateInventoryPeriod($db, $time_period['id']);
    }
}

// Get products for selected period
$products = [];
if ($selected_period && isset($selected_period['time_period_id'])) {
    $products_sql = "SELECT p.*, c.name as category_name,
                    (p.stock_quantity * p.cost_price) as stock_value,
                    (p.stock_quantity * p.selling_price) as potential_revenue
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.period_id = ?
                    ORDER BY p.name ASC";
    
    $stmt_products = $db->prepare($products_sql);
    $stmt_products->bind_param("i", $selected_period['time_period_id']);
    $stmt_products->execute();
    $products = $stmt_products->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Calculate sales data
$sales_data = ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
if ($selected_period && isset($selected_period['time_period_id'])) {
    $sales_data = calculatePeriodSalesData($db, $selected_period['time_period_id']);
    
    // Update period with sales data
    if ($selected_period['id']) {
        $update_sql = "UPDATE inventory_periods 
                       SET total_sales = ?, total_profit = ?, updated_at = NOW()
                       WHERE id = ?";
        $stmt = $db->prepare($update_sql);
        if ($stmt) {
            $stmt->bind_param("ddi", $sales_data['total_sales'], $sales_data['total_profit'], $selected_period['id']);
            $stmt->execute();
        }
    }
}

// Calculate inventory statistics
$total_products = count($products);
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = 0;
$total_potential_revenue = 0;

foreach ($products as $product) {
    $total_stock_value += $product['stock_value'] ?? 0;
    $total_potential_revenue += $product['potential_revenue'] ?? 0;
    if (($product['stock_quantity'] ?? 0) <= 0) {
        $out_of_stock_count++;
    } elseif (($product['stock_quantity'] ?? 0) <= ($product['min_stock'] ?? 0)) {
        $low_stock_count++;
    }
}

// Calculate balance metrics
if ($selected_period) {
    $balance_change = ($selected_period['closing_balance'] ?? 0) - ($selected_period['opening_balance'] ?? 0);
    $balance_change_percent = ($selected_period['opening_balance'] ?? 0) > 0 ? 
                            ($balance_change / $selected_period['opening_balance']) * 100 : 0;
    $current_inventory_value = calculatePeriodInventoryValue($db, $selected_period['time_period_id']);
} else {
    $balance_change = 0;
    $balance_change_percent = 0;
    $current_inventory_value = 0;
}

// Stock status helper
function getStockStatus($current, $min) {
    if ($current <= 0) return 'out';
    if ($current <= $min) return 'low';
    if ($current <= ($min * 2)) return 'medium';
    return 'high';
}

// Safe display functions to prevent warnings
function safeDisplay($data, $key, $default = '') {
    return isset($data[$key]) ? htmlspecialchars($data[$key]) : $default;
}

function safeDisplayDate($year, $month) {
    if ($year && $month) {
        try {
            $date = DateTime::createFromFormat('Y-m', $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT));
            return $date ? $date->format('F Y') : 'Invalid Date';
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }
    return 'N/A';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Vinmel Irrigation</title>
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
        .balance-flow {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .balance-step {
            text-align: center;
            padding: 0.5rem;
        }
        .period-selector {
            min-width: 250px;
        }
        .product-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stock-status-high { color: #28a745; }
        .stock-status-medium { color: #ffc107; }
        .stock-status-low { color: #fd7e14; }
        .stock-status-out { color: #dc3545; }
        .sync-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .sync-btn:hover {
            color: white;
            opacity: 0.9;
        }
        #loadingOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
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
                            <p class="mb-0">Global inventory tracking across monthly periods</p>
                        </div>
                        <div class="col-md-6">
                            <div class="period-navigation">
                                <!-- Period Selector -->
                                <div class="period-selector">
                                    <select class="form-select" id="periodSelect" onchange="changePeriod(this.value)">
                                        <option value="">Select Period</option>
                                        <?php foreach($all_periods as $period): 
                                            $is_current = ($period['period_month_display'] ?? '') == date('Y-m');
                                            $selected = ($selected_period && ($selected_period['id'] ?? '') == $period['id']);
                                        ?>
                                            <option value="<?= $period['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($period['period_name'] ?? '') ?>
                                                <?= $is_current ? ' (Current)' : '' ?>
                                                <?= ($period['is_locked'] ?? 0) ? ' (Locked)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Period Details -->
                    <?php if ($selected_period): ?>
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <strong>Selected Period:</strong>
                            <h4 class="mb-0 mt-1"><?= safeDisplay($selected_period, 'period_name', 'N/A') ?></h4>
                            <small class="text-white-50">
                                <?= safeDisplayDate($selected_period['year'] ?? null, $selected_period['month'] ?? null) ?>
                            </small>
                        </div>
                        <div class="col-md-3">
                            <strong>Period Status:</strong>
                            <div class="mt-1">
                                <?php if (($selected_period['period_month_display'] ?? '') == date('Y-m')): ?>
                                    <span class="badge bg-success">Current Period</span>
                                <?php elseif (($selected_period['period_month_display'] ?? '') < date('Y-m')): ?>
                                    <span class="badge bg-info">Past Period</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Future Period</span>
                                <?php endif; ?>
                                <?php if (($selected_period['is_locked'] ?? 0)): ?>
                                    <span class="badge bg-danger ms-1">Locked</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="btn-group">
                                <button class="btn btn-light" onclick="syncInventory()" title="Sync inventory calculations">
                                    <i class="fas fa-sync-alt me-2"></i>Sync Inventory
                                </button>
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

                <!-- Balance Flow Visualization -->
                <?php if ($selected_period): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="balance-flow">
                            <div class="row align-items-center">
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Opening Balance</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($selected_period['opening_balance'] ?? 0, 2) ?></div>
                                    <small>
                                        <?php if (($selected_period['opening_balance'] ?? 0) == 0): ?>
                                            First period - starting from zero
                                        <?php else: ?>
                                            From previous period's closing balance
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-md-1">
                                    <div class="balance-arrow text-center">
                                        <i class="fas fa-plus"></i>
                                    </div>
                                </div>
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Current Inventory Value</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($current_inventory_value, 2) ?></div>
                                    <small>Inventory added this period</small>
                                </div>
                                <div class="col-md-1">
                                    <div class="balance-arrow text-center">
                                        <i class="fas fa-equals"></i>
                                    </div>
                                </div>
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Closing Balance</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($selected_period['closing_balance'] ?? 0, 2) ?></div>
                                    <small>Will carry to next period</small>
                                </div>
                                <div class="col-md-1 text-center">
                                    <button class="btn sync-btn btn-sm" onclick="recalculatePeriod('<?= $selected_period['id'] ?>')" 
                                            title="Recalculate this period">
                                        <i class="fas fa-calculator"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Financial KPIs -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-primary">
                                            KSH <?= number_format($selected_period ? ($selected_period['closing_balance'] ?? 0) : $total_stock_value, 2) ?>
                                        </div>
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
                                        <div class="kpi-value text-success">
                                            KSH <?= number_format($sales_data['total_sales'], 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Total Sales</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-chart-line fa-2x text-success opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted"><?= $sales_data['total_sold_quantity'] ?> items sold</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-warning border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-warning">
                                            KSH <?= number_format($sales_data['total_profit'], 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Gross Profit</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-coins fa-2x text-warning opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Sales profit margin</small>
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

                <!-- Current Inventory -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">
                                        <i class="fas fa-boxes me-2"></i>
                                        Inventory for 
                                        <?= $selected_period ? safeDisplay($selected_period, 'period_name', 'Current Period') : 'Current Period' ?>
                                        <small class="text-muted">(<?= $total_products ?> products)</small>
                                    </h5>
                                </div>
                                <div>
                                    <span class="badge bg-primary me-2">Total Value: KSH <?= number_format($total_stock_value, 2) ?></span>
                                    <button class="btn btn-sm btn-outline-primary" onclick="syncPeriodInventory('<?= $selected_period['time_period_id'] ?? '' ?>')">
                                        <i class="fas fa-redo-alt me-1"></i>Refresh
                                    </button>
                                </div>
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
                                                    $stock_quantity = $product['stock_quantity'] ?? 0;
                                                    $min_stock = $product['min_stock'] ?? 0;
                                                    $stock_status = getStockStatus($stock_quantity, $min_stock);
                                                    $progress_width = min(($stock_quantity / max($min_stock * 3, 1)) * 100, 100);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= safeDisplay($product, 'name') ?></strong>
                                                                    <?php if (!empty($product['supplier'])): ?>
                                                                        <br><small class="text-muted">Supplier: <?= htmlspecialchars($product['supplier']) ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <code><?= safeDisplay($product, 'sku') ?></code>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= safeDisplay($product, 'category_name', 'Uncategorized') ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['cost_price'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= number_format($product['selling_price'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="stock-status-<?= $stock_status ?> fw-bold">
                                                                    <?= $stock_quantity ?>
                                                                </span>
                                                                <small class="text-muted">/ min: <?= $min_stock ?></small>
                                                            </div>
                                                            <div class="progress mt-1">
                                                                <div class="progress-bar 
                                                                    <?= $stock_status === 'high' ? 'bg-success' : '' ?>
                                                                    <?= $stock_status === 'medium' ? 'bg-warning' : '' ?>
                                                                    <?= $stock_status === 'low' ? 'bg-danger' : '' ?>
                                                                    <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>"
                                                                    style="width: <?= $progress_width ?>%">
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['stock_value'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge 
                                                                <?= $stock_status === 'high' ? 'bg-success' : '' ?>
                                                                <?= $stock_status === 'medium' ? 'bg-warning' : '' ?>
                                                                <?= $stock_status === 'low' ? 'bg-danger' : '' ?>
                                                                <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>">
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
                                        <p class="text-muted">No products found for the selected period.</p>
                                        <a href="products.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Add Products
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
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
            if (table) {
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.table_to_sheet(table);
                XLSX.utils.book_append_sheet(wb, ws, 'Inventory Report');
                
                const periodElement = document.querySelector('.period-info-card h4');
                const periodName = periodElement ? periodElement.textContent : 'Inventory';
                XLSX.writeFile(wb, `inventory_${periodName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.xlsx`);
            }
        }

        function syncInventory() {
            if (confirm('Are you sure you want to sync all inventory periods? This will recalculate all opening and closing balances.')) {
                showLoading('Syncing inventory...');
                
                fetch('inventory.php?action=sync_all')
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            alert('Inventory synced successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        alert('Error syncing inventory: ' + error);
                    });
            }
        }

        function syncPeriodInventory(timePeriodId) {
            if (!timePeriodId) return;
            
            showLoading('Syncing period inventory...');
            
            fetch(`inventory.php?action=sync_period&time_period_id=${timePeriodId}`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        alert('Period inventory synced successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    alert('Error syncing period inventory: ' + error);
                });
        }

        function recalculatePeriod(periodId) {
            if (!periodId) return;
            
            if (confirm('Recalculate this period? This will update the opening and closing balances.')) {
                showLoading('Recalculating period...');
                
                fetch(`inventory.php?action=recalculate&period_id=${periodId}`)
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            alert('Period recalculated successfully!');
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        alert('Error recalculating period: ' + error);
                    });
            }
        }

        function showLoading(message) {
            // Remove existing overlay if any
            hideLoading();
            
            // Create loading overlay
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            
            const spinner = document.createElement('div');
            spinner.style.cssText = 'text-align: center; color: white;';
            
            spinner.innerHTML = `
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p>${message}</p>
            `;
            
            overlay.appendChild(spinner);
            document.body.appendChild(overlay);
        }

        function hideLoading() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.remove();
            }
        }
    </script>
</body>
</html>