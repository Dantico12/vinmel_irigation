<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'admin';

$message = '';
$error = '';

// Function to verify and fix inventory periods
function verifyInventoryPeriods($db, $user_id) {
    error_log("DEBUG: Verifying inventory periods for user $user_id");
    
    // Get all time periods
    $periods_sql = "SELECT id, year, month, period_name FROM time_periods ORDER BY year, month";
    $stmt = $db->prepare($periods_sql);
    if ($stmt && $stmt->execute()) {
        $time_periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($time_periods as $time_period) {
            $period_month = sprintf('%04d-%02d', $time_period['year'], $time_period['month']);
            
            // Check if inventory period exists
            $check_sql = "SELECT * FROM inventory_periods WHERE user_id = ? AND period_month = ?";
            $check_stmt = $db->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("is", $user_id, $period_month);
                if ($check_stmt->execute()) {
                    $result = $check_stmt->get_result();
                    $inventory_period = $result->fetch_assoc();
                    
                    if (!$inventory_period) {
                        error_log("DEBUG: Missing inventory period for $period_month - creating it");
                        getOrCreateInventoryPeriod($db, $user_id, $time_period);
                    } else {
                        error_log("DEBUG: Inventory period exists for $period_month: opening={$inventory_period['opening_balance']}, closing={$inventory_period['closing_balance']}");
                    }
                }
            }
        }
    }
}

// Function to get or create inventory period with proper opening balance
function getOrCreateInventoryPeriod($db, $user_id, $time_period) {
    if (!$time_period || !$user_id) {
        error_log("Invalid parameters for getOrCreateInventoryPeriod: user_id=$user_id");
        return null;
    }
    
    // Create period_month from year and month
    $period_month = sprintf('%04d-%02d', $time_period['year'], $time_period['month']);
    
    // Check if inventory period exists
    $sql = "SELECT * FROM inventory_periods WHERE user_id = ? AND period_month = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare statement for inventory period check");
        return null;
    }
    
    $stmt->bind_param("is", $user_id, $period_month);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $inventory_period = $result->fetch_assoc();
        
        if ($inventory_period) {
            return $inventory_period;
        }
    }
    
    // Create new inventory period with opening balance from previous period's CLOSING balance
    $opening_balance = 0;
    $previous_period = null;
    
    // DEBUG: Log what we're looking for
    error_log("DEBUG: Looking for previous period for user $user_id before $period_month");
    
    // Get the MOST RECENT period's closing balance (regardless of month)
    $prev_sql = "SELECT id, closing_balance, period_month FROM inventory_periods 
                 WHERE user_id = ? 
                 AND period_month < ?
                 ORDER BY period_month DESC LIMIT 1";
    $prev_stmt = $db->prepare($prev_sql);
    if ($prev_stmt) {
        $prev_stmt->bind_param("is", $user_id, $period_month);
        if ($prev_stmt->execute()) {
            $prev_result = $prev_stmt->get_result();
            $previous_period = $prev_result->fetch_assoc();
            if ($previous_period) {
                $opening_balance = $previous_period['closing_balance'];
                error_log("DEBUG: Found previous period {$previous_period['period_month']} with closing balance {$previous_period['closing_balance']}");
                error_log("DEBUG: Setting opening balance for $period_month to {$previous_period['closing_balance']} from {$previous_period['period_month']}");
            } else {
                error_log("DEBUG: No previous period found for user $user_id before $period_month - using opening balance 0");
                
                // Let's check what periods actually exist for this user
                $check_sql = "SELECT period_month, closing_balance FROM inventory_periods WHERE user_id = ? ORDER BY period_month";
                $check_stmt = $db->prepare($check_sql);
                if ($check_stmt) {
                    $check_stmt->bind_param("i", $user_id);
                    if ($check_stmt->execute()) {
                        $check_result = $check_stmt->get_result();
                        $all_periods = $check_result->fetch_all(MYSQLI_ASSOC);
                        error_log("DEBUG: All periods for user $user_id: " . json_encode($all_periods));
                    }
                }
            }
        } else {
            error_log("DEBUG: Failed to execute previous period query");
        }
    } else {
        error_log("DEBUG: Failed to prepare previous period query");
    }
    
    // Insert new period - opening balance comes from previous period's closing balance
    $insert_sql = "INSERT INTO inventory_periods (user_id, period_month, opening_balance, closing_balance, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
    $insert_stmt = $db->prepare($insert_sql);
    if (!$insert_stmt) {
        error_log("Failed to prepare insert statement for inventory period");
        return null;
    }
    
    // Initially set closing balance same as opening balance - it will be updated later
    $insert_stmt->bind_param("isdd", $user_id, $period_month, $opening_balance, $opening_balance);
    
    if ($insert_stmt->execute()) {
        $new_period_id = $insert_stmt->insert_id;
        error_log("DEBUG: Created new inventory period $new_period_id for $period_month with opening balance $opening_balance");
        
        // Carry forward stock from previous period if it exists
        if ($previous_period) {
            carryForwardStock($db, $user_id, $previous_period['id'], $new_period_id);
        }
        
        return [
            'id' => $new_period_id,
            'user_id' => $user_id,
            'period_month' => $period_month,
            'opening_balance' => $opening_balance,
            'closing_balance' => $opening_balance, // Will be updated with actual calculation
            'total_sales' => 0,
            'total_profit' => 0
        ];
    } else {
        error_log("Failed to execute insert statement for inventory period");
    }
    
    return null;
}

// Function to carry forward stock to new period
function carryForwardStock($db, $user_id, $previous_period_id, $current_period_id) {
    // Get products with remaining stock from previous period
    $sql = "SELECT p.id, p.stock_quantity, p.cost_price 
            FROM products p 
            WHERE p.created_by = ? AND p.stock_quantity > 0";
    $stmt = $db->prepare($sql);
    if (!$stmt) return;
    
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) return;
    
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($products as $product) {
        // Record carried forward stock
        $carry_sql = "INSERT INTO period_stock_carry (period_id, product_id, quantity, cost_price, carried_at) 
                      VALUES (?, ?, ?, ?, NOW())";
        $stmt_carry = $db->prepare($carry_sql);
        if (!$stmt_carry) continue;
        
        $stmt_carry->bind_param("iiid", $current_period_id, $product['id'], 
                               $product['stock_quantity'], $product['cost_price']);
        $stmt_carry->execute();
    }
}

// NEW FUNCTION: Calculate current stock value for a specific period - FIXED VERSION
function calculateCurrentStockValue($db, $user_id, $user_role, $selected_year = null, $selected_month = null) {
    if (!$db || !$user_id) return 0;
    
    // If specific period is selected
    if ($selected_year && $selected_month) {
        if ($user_role === 'super_admin') {
            $sql = "SELECT SUM(stock_quantity * cost_price) as total_stock_value 
                    FROM products 
                    WHERE period_year = ? AND period_month = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $selected_year, $selected_month);
            }
        } else {
            $sql = "SELECT SUM(stock_quantity * cost_price) as total_stock_value 
                    FROM products 
                    WHERE created_by = ? AND period_year = ? AND period_month = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iii", $user_id, $selected_year, $selected_month);
            }
        }
    } else {
        // All periods - get total across all periods
        if ($user_role === 'super_admin') {
            $sql = "SELECT SUM(stock_quantity * cost_price) as total_stock_value FROM products";
            $stmt = $db->prepare($sql);
        } else {
            $sql = "SELECT SUM(stock_quantity * cost_price) as total_stock_value 
                    FROM products WHERE created_by = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
            }
        }
    }
    
    if (!$stmt) {
        error_log("Failed to prepare statement for calculateCurrentStockValue");
        return 0;
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result()->fetch_assoc();
        $value = $result['total_stock_value'] ?? 0;
        error_log("DEBUG: Calculated current stock value: $value for period $selected_year-$selected_month");
        return $value;
    } else {
        error_log("Failed to execute statement for calculateCurrentStockValue");
    }
    return 0;
}

// Function to update period closing balance - IMPROVED VERSION
function updatePeriodClosingBalance($db, $period_id, $user_id, $user_role, $selected_year = null, $selected_month = null) {
    if (!$db || !$period_id || !$user_id) {
        error_log("Invalid parameters for updatePeriodClosingBalance");
        return false;
    }
    
    // Get the period data first to know the opening balance
    $period_sql = "SELECT opening_balance, period_month FROM inventory_periods WHERE id = ?";
    $period_stmt = $db->prepare($period_sql);
    if (!$period_stmt) return false;
    
    $period_stmt->bind_param("i", $period_id);
    if (!$period_stmt->execute()) return false;
    
    $period_result = $period_stmt->get_result();
    $period_data = $period_result->fetch_assoc();
    if (!$period_data) return false;
    
    $opening_balance = $period_data['opening_balance'];
    $period_month = $period_data['period_month'];
    
    // Parse year and month from period_month
    list($year, $month) = explode('-', $period_month);
    
    // Calculate current stock value for the period
    $current_stock_value = calculateCurrentStockValue($db, $user_id, $user_role, $year, $month);
    
    // NEW LOGIC: Closing balance = Opening balance + Current inventory value
    $closing_balance = $opening_balance + $current_stock_value;
    
    error_log("DEBUG: Updating period $period_id ($period_month): Opening=$opening_balance, Current=$current_stock_value, Closing=$closing_balance");
    
    $update_sql = "UPDATE inventory_periods 
                   SET closing_balance = ?, updated_at = NOW()
                   WHERE id = ?";
    $stmt = $db->prepare($update_sql);
    if (!$stmt) return false;
    
    $stmt->bind_param("di", $closing_balance, $period_id);
    
    $result = $stmt->execute();
    
    if ($result) {
        error_log("DEBUG: Successfully updated period $period_id closing balance to $closing_balance");
    } else {
        error_log("DEBUG: Failed to update period $period_id closing balance");
    }
    
    return $result;
}

// Handle period selection
$selected_period_id = $_GET['period_id'] ?? null;

// Get all periods for the dropdown
$all_periods = getAllTimePeriods($db);

// Initialize current period with default values
$current_time_period = null;
$current_inventory_period = null;
$current_period = [
    'id' => 0,
    'period_month' => date('Y-m'),
    'period_name' => date('F Y'),
    'opening_balance' => 0,
    'closing_balance' => 0,
    'total_sales' => 0,
    'total_profit' => 0
];

try {
    // Call this function to verify periods
    verifyInventoryPeriods($db, $user_id);
    
    // If a specific period is selected, use it
    if ($selected_period_id && $selected_period_id > 0) {
        $current_time_period = getTimePeriodById($db, $selected_period_id);
        if ($current_time_period) {
            $current_inventory_period = getOrCreateInventoryPeriod($db, $user_id, $current_time_period);
            if ($current_inventory_period) {
                $current_period = array_merge($current_time_period, $current_inventory_period);
                
                // Update closing balance for the period with the new logic
                updatePeriodClosingBalance($db, $current_inventory_period['id'], $user_id, $user_role, $current_time_period['year'], $current_time_period['month']);
                
                // Refresh the inventory period data
                $current_inventory_period = getOrCreateInventoryPeriod($db, $user_id, $current_time_period);
                $current_period = array_merge($current_time_period, $current_inventory_period);
            }
        }
    } else {
        // Otherwise get the current active period
        $current_time_period = getCurrentTimePeriod($db);
        if ($current_time_period) {
            $current_inventory_period = getOrCreateInventoryPeriod($db, $user_id, $current_time_period);
            if ($current_inventory_period) {
                $current_period = array_merge($current_time_period, $current_inventory_period);
                
                // Update closing balance for current period with new logic
                updatePeriodClosingBalance($db, $current_inventory_period['id'], $user_id, $user_role, $current_time_period['year'], $current_time_period['month']);
                
                // Refresh the inventory period data
                $current_inventory_period = getOrCreateInventoryPeriod($db, $user_id, $current_time_period);
                $current_period = array_merge($current_time_period, $current_inventory_period);
            }
        }
    }
} catch (Exception $e) {
    error_log("Error getting current period: " . $e->getMessage());
    $error = "Unable to load period data: " . $e->getMessage();
}

// Calculate closing balance (sum of all products' stock value) - KEEP THIS FOR DISPLAY
function calculateClosingBalance($db, $user_id, $user_role, $selected_year = null, $selected_month = null) {
    return calculateCurrentStockValue($db, $user_id, $user_role, $selected_year, $selected_month);
}

// Calculate sales data for period
function calculatePeriodSalesData($db, $user_id, $user_role, $selected_year = null, $selected_month = null) {
    if (!$db) return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
    
    // If specific period is selected
    if ($selected_year && $selected_month) {
        $period_month = sprintf('%04d-%02d', $selected_year, $selected_month);
        
        if ($user_role === 'super_admin') {
            $sales_sql = "SELECT 
                            COALESCE(SUM(ti.total_price), 0) as total_sales,
                            COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                            COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
                          FROM transaction_items ti
                          JOIN products p ON ti.product_id = p.id
                          JOIN transactions t ON ti.transaction_id = t.id
                          WHERE DATE_FORMAT(t.transaction_date, '%Y-%m') = ?";
            $stmt = $db->prepare($sales_sql);
            if ($stmt) {
                $stmt->bind_param("s", $period_month);
            }
        } else {
            $sales_sql = "SELECT 
                            COALESCE(SUM(ti.total_price), 0) as total_sales,
                            COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                            COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
                          FROM transaction_items ti
                          JOIN products p ON ti.product_id = p.id
                          JOIN transactions t ON ti.transaction_id = t.id
                          WHERE p.created_by = ? AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?";
            $stmt = $db->prepare($sales_sql);
            if ($stmt) {
                $stmt->bind_param("is", $user_id, $period_month);
            }
        }
    } else {
        // All periods - get total across all periods
        if ($user_role === 'super_admin') {
            $sales_sql = "SELECT 
                            COALESCE(SUM(ti.total_price), 0) as total_sales,
                            COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                            COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
                          FROM transaction_items ti
                          JOIN products p ON ti.product_id = p.id
                          JOIN transactions t ON ti.transaction_id = t.id";
            $stmt = $db->prepare($sales_sql);
        } else {
            $sales_sql = "SELECT 
                            COALESCE(SUM(ti.total_price), 0) as total_sales,
                            COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                            COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
                          FROM transaction_items ti
                          JOIN products p ON ti.product_id = p.id
                          JOIN transactions t ON ti.transaction_id = t.id
                          WHERE p.created_by = ?";
            $stmt = $db->prepare($sales_sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
            }
        }
    }
    
    if (!$stmt) return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
    
    if ($stmt->execute()) {
        $result = $stmt->get_result()->fetch_assoc();
        return [
            'total_sales' => $result['total_sales'] ?? 0,
            'total_profit' => $result['total_profit'] ?? 0,
            'total_sold_quantity' => $result['total_sold_quantity'] ?? 0
        ];
    }
    
    return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
}

// Calculate stock alerts for period
function calculatePeriodStockAlerts($db, $user_id, $user_role, $selected_year = null, $selected_month = null) {
    if (!$db) return ['low_stock_count' => 0, 'out_of_stock_count' => 0];
    
    // If specific period is selected
    if ($selected_year && $selected_month) {
        if ($user_role === 'super_admin') {
            $sql = "SELECT 
                        SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock THEN 1 ELSE 0 END) as low_stock_count
                    FROM products 
                    WHERE period_year = ? AND period_month = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $selected_year, $selected_month);
            }
        } else {
            $sql = "SELECT 
                        SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock THEN 1 ELSE 0 END) as low_stock_count
                    FROM products 
                    WHERE created_by = ? AND period_year = ? AND period_month = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iii", $user_id, $selected_year, $selected_month);
            }
        }
    } else {
        // All periods - get total across all periods
        if ($user_role === 'super_admin') {
            $sql = "SELECT 
                        SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock THEN 1 ELSE 0 END) as low_stock_count
                    FROM products";
            $stmt = $db->prepare($sql);
        } else {
            $sql = "SELECT 
                        SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock THEN 1 ELSE 0 END) as low_stock_count
                    FROM products 
                    WHERE created_by = ?";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
            }
        }
    }
    
    if (!$stmt) return ['low_stock_count' => 0, 'out_of_stock_count' => 0];
    
    if ($stmt->execute()) {
        $result = $stmt->get_result()->fetch_assoc();
        return [
            'low_stock_count' => $result['low_stock_count'] ?? 0,
            'out_of_stock_count' => $result['out_of_stock_count'] ?? 0
        ];
    }
    
    return ['low_stock_count' => 0, 'out_of_stock_count' => 0];
}

// Calculate sales trend
function calculateSalesTrend($db, $user_id, $user_role, $current_year = null, $current_month = null) {
    if (!$db) return 0;
    
    // If specific period is selected, calculate trend vs previous period
    if ($current_year && $current_month) {
        $current_sales_data = calculatePeriodSalesData($db, $user_id, $user_role, $current_year, $current_month);
        $current_sales = $current_sales_data['total_sales'];
        
        $prev_month = $current_month - 1;
        $prev_year = $current_year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year = $current_year - 1;
        }
        
        $previous_sales_data = calculatePeriodSalesData($db, $user_id, $user_role, $prev_year, $prev_month);
        $previous_sales = $previous_sales_data['total_sales'];
        
        if ($previous_sales > 0) {
            return (($current_sales - $previous_sales) / $previous_sales) * 100;
        } elseif ($current_sales > 0) {
            return 100;
        }
    } else {
        // For all periods, show overall growth
        $current_sales_data = calculatePeriodSalesData($db, $user_id, $user_role);
        $current_sales = $current_sales_data['total_sales'];
        
        return $current_sales > 0 ? 100 : 0;
    }
    
    return 0;
}

/* -------------------------------------------------------
   INVENTORY DATA FETCHING - FIXED VERSION
-------------------------------------------------------- */

// Initialize variables with default values
$products = [];
$carried_products = [];
$transactions = [];
$total_products = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = 0;
$total_potential_revenue = 0;
$total_sold_quantity = 0;
$total_sales_value = 0;
$total_profit = 0;
$cogs = 0;
$total_carried_value = 0;
$sales_trend = 0;

try {
    // Get the selected period's year and month (if specific period is selected)
    $selected_year = $selected_period_id ? ($current_time_period['year'] ?? date('Y')) : null;
    $selected_month = $selected_period_id ? ($current_time_period['month'] ?? date('m')) : null;
    
    // Calculate all financial data (will handle both specific period and all periods)
    $total_stock_value = calculateClosingBalance($db, $user_id, $user_role, $selected_year, $selected_month);
    
    $sales_data = calculatePeriodSalesData($db, $user_id, $user_role, $selected_year, $selected_month);
    $total_sales_value = $sales_data['total_sales'];
    $total_profit = $sales_data['total_profit'];
    $total_sold_quantity = $sales_data['total_sold_quantity'];
    
    $stock_alerts = calculatePeriodStockAlerts($db, $user_id, $user_role, $selected_year, $selected_month);
    $low_stock_count = $stock_alerts['low_stock_count'];
    $out_of_stock_count = $stock_alerts['out_of_stock_count'];
    
    $sales_trend = calculateSalesTrend($db, $user_id, $user_role, $selected_year, $selected_month);
    
    // Calculate COGS
    $cogs = $total_sales_value - $total_profit;

    // DEBUG: Log what we're trying to fetch
    error_log("DEBUG: Fetching products for period: year=$selected_year, month=$selected_month, user_id=$user_id, role=$user_role");

    // Fetch products with detailed information - FIXED VERSION
    if ($user_role === 'super_admin') {
        if ($selected_period_id) {
            // Specific period selected
            $products_sql = "SELECT 
                p.id, p.name, p.sku, p.category_id, p.cost_price, p.selling_price,
                p.stock_quantity, p.min_stock, p.supplier, p.description,
                c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue,
                u.name as created_by_name,
                p.period_year,
                p.period_month
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.period_year = ? AND p.period_month = ?
            ORDER BY p.name ASC";
            
            $stmt_products = $db->prepare($products_sql);
            if ($stmt_products) {
                $stmt_products->bind_param("ii", $selected_year, $selected_month);
                if ($stmt_products->execute()) {
                    $result = $stmt_products->get_result();
                    $products = $result->fetch_all(MYSQLI_ASSOC);
                    $total_products = count($products);
                    error_log("DEBUG: Found $total_products products for super_admin in period $selected_year-$selected_month");
                } else {
                    error_log("DEBUG: Failed to execute products query for super_admin");
                }
            } else {
                error_log("DEBUG: Failed to prepare products query for super_admin");
            }
        } else {
            // All periods selected - show ALL products
            $products_sql = "SELECT 
                p.id, p.name, p.sku, p.category_id, p.cost_price, p.selling_price,
                p.stock_quantity, p.min_stock, p.supplier, p.description,
                c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue,
                u.name as created_by_name,
                p.period_year,
                p.period_month
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN users u ON p.created_by = u.id
            ORDER BY p.period_year DESC, p.period_month DESC, p.name ASC";
            
            $stmt_products = $db->prepare($products_sql);
            if ($stmt_products) {
                if ($stmt_products->execute()) {
                    $result = $stmt_products->get_result();
                    $products = $result->fetch_all(MYSQLI_ASSOC);
                    $total_products = count($products);
                    error_log("DEBUG: Found $total_products products for super_admin (all periods)");
                } else {
                    error_log("DEBUG: Failed to execute all products query for super_admin");
                }
            } else {
                error_log("DEBUG: Failed to prepare all products query for super_admin");
            }
        }
    } else {
        if ($selected_period_id) {
            // Specific period selected
            $products_sql = "SELECT 
                p.id, p.name, p.sku, p.category_id, p.cost_price, p.selling_price,
                p.stock_quantity, p.min_stock, p.supplier, p.description,
                c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue,
                p.period_year,
                p.period_month
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.created_by = ? 
            AND p.period_year = ? 
            AND p.period_month = ?
            ORDER BY p.name ASC";
            
            $stmt_products = $db->prepare($products_sql);
            if ($stmt_products) {
                $stmt_products->bind_param("iii", $user_id, $selected_year, $selected_month);
                if ($stmt_products->execute()) {
                    $result = $stmt_products->get_result();
                    $products = $result->fetch_all(MYSQLI_ASSOC);
                    $total_products = count($products);
                    error_log("DEBUG: Found $total_products products for user $user_id in period $selected_year-$selected_month");
                } else {
                    error_log("DEBUG: Failed to execute products query for user $user_id");
                }
            } else {
                error_log("DEBUG: Failed to prepare products query for user $user_id");
            }
        } else {
            // All periods selected - show all their products across all periods
            $products_sql = "SELECT 
                p.id, p.name, p.sku, p.category_id, p.cost_price, p.selling_price,
                p.stock_quantity, p.min_stock, p.supplier, p.description,
                c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue,
                p.period_year,
                p.period_month
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.created_by = ?
            ORDER BY p.period_year DESC, p.period_month DESC, p.name ASC";
            
            $stmt_products = $db->prepare($products_sql);
            if ($stmt_products) {
                $stmt_products->bind_param("i", $user_id);
                if ($stmt_products->execute()) {
                    $result = $stmt_products->get_result();
                    $products = $result->fetch_all(MYSQLI_ASSOC);
                    $total_products = count($products);
                    error_log("DEBUG: Found $total_products products for user $user_id (all periods)");
                } else {
                    error_log("DEBUG: Failed to execute all products query for user $user_id");
                }
            } else {
                error_log("DEBUG: Failed to prepare all products query for user $user_id");
            }
        }
    }

    // Fetch carried forward products for current period
    if ($current_inventory_period && $current_inventory_period['id'] > 0) {
        $carry_sql = "SELECT 
            psc.*, p.name, p.sku, c.name as category_name,
            (psc.quantity * psc.cost_price) as carried_value
        FROM period_stock_carry psc
        JOIN products p ON psc.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE psc.period_id = ?
        ORDER BY p.name ASC";

        $stmt_carry = $db->prepare($carry_sql);
        if ($stmt_carry) {
            $stmt_carry->bind_param("i", $current_inventory_period['id']);
            if ($stmt_carry->execute()) {
                $carried_products = $stmt_carry->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }
    }

    // Calculate carried forward value
    foreach ($carried_products as $carried) {
        $total_carried_value += $carried['carried_value'] ?? 0;
    }

} catch (Exception $e) {
    error_log("Error in inventory data processing: " . $e->getMessage());
    $error = "Unable to load inventory data: " . $e->getMessage();
}

// Stock status classification
function getStockStatus($current, $min) {
    if ($current <= 0) return 'out';
    if ($current <= $min) return 'low';
    if ($current <= ($min * 2)) return 'medium';
    return 'high';
}

// Helper function to safely format numbers
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return number_format(0, $decimals);
    }
    return number_format((float)$value, $decimals);
}

// Helper function to safely access array values
function safeArrayGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// Debug function to format period name
function getPeriodDisplayName($period) {
    if (isset($period['period_name'])) {
        return $period['period_name'];
    } elseif (isset($period['year']) && isset($period['month'])) {
        return date('F Y', strtotime($period['year'] . '-' . $period['month'] . '-01'));
    } elseif (isset($period['period_month'])) {
        return date('F Y', strtotime($period['period_month'] . '-01'));
    }
    return date('F Y');
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
        .product-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .progress {
            height: 6px;
        }
        .period-selector {
            max-width: 300px;
        }
        .current-period-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }
        .super-admin-badge {
            background: #ffc107;
            color: #000;
        }
        .all-periods-badge {
            background: #17a2b8;
            color: white;
        }
        .balance-change-positive {
            color: #28a745;
            font-weight: bold;
        }
        .balance-change-negative {
            color: #dc3545;
            font-weight: bold;
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
        .balance-arrow {
            font-size: 1.5rem;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        .debug-info {
            background: #f8f9fa;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .balance-explanation {
            background: #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            border-left: 4px solid #28a745;
        }
        .data-correction-alert {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
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
                            <h2 class="mb-1">
                                <?php if (!$selected_period_id): ?>
                                    All Periods - Complete Inventory Overview
                                <?php else: ?>
                                    Inventory Period: <?= getPeriodDisplayName($current_period) ?>
                                <?php endif; ?>
                            </h2>
                            <p class="mb-0">
                                <?php if (!$selected_period_id): ?>
                                    Complete inventory overview across all time periods
                                <?php else: ?>
                                    Period-based inventory management with automatic stock carry-forward
                                <?php endif; ?>
                                <?php if ($user_role === 'super_admin'): ?>
                                    <span class="badge super-admin-badge ms-2">Super Admin View</span>
                                <?php endif; ?>
                                <?php if (!$selected_period_id): ?>
                                    <span class="badge all-periods-badge ms-2">All Periods Summary</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-end align-items-center gap-3">
                                <!-- Period Selector -->
                                <div class="period-selector">
                                    <form method="GET" action="" class="d-flex gap-2">
                                        <select name="period_id" class="form-select" onchange="this.form.submit()">
                                            <option value="">All Periods</option>
                                            <?php foreach ($all_periods as $period): ?>
                                                <option value="<?= $period['id'] ?>" 
                                                    <?= ($selected_period_id == $period['id']) ? 'selected' : '' ?>>
                                                    <?= getPeriodDisplayName($period) ?>
                                                    <?= ($period['is_locked'] ?? 0) ? ' (Locked)' : '' ?>
                                                    <?= ($period['is_active'] ?? 0) ? ' (Active)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($selected_period_id): ?>
                                            <a href="?" class="btn btn-outline-light">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <div class="btn-group">
                                    <button class="btn btn-light" onclick="exportToExcel()">
                                        <i class="fas fa-file-excel me-2"></i>Export
                                    </button>
                                    <button class="btn btn-light" onclick="window.print()">
                                        <i class="fas fa-print me-2"></i>Print
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Current Period Indicator -->
                    <?php if (!$selected_period_id): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <span class="current-period-badge">
                                    <i class="fas fa-layer-group me-1"></i>
                                    Viewing complete inventory data across all periods
                                    <?php if ($user_role === 'super_admin'): ?>
                                        (All Users)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <span class="current-period-badge">
                                    <i class="fas fa-calendar me-1"></i>
                                    Viewing data for <?= getPeriodDisplayName($current_period) ?>
                                    <?php if ($user_role === 'super_admin'): ?>
                                        (All Users)
                                    <?php endif; ?>
                                </span>
                                <a href="?" class="btn btn-sm btn-light ms-2">
                                    <i class="fas fa-layer-group me-1"></i>View All Periods
                                </a>
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


                <!-- Balance Summary Section -->
                <?php if ($selected_period_id && $current_inventory_period): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-balance-scale me-2"></i>
                                    Period Balance Summary - <?= getPeriodDisplayName($current_period) ?>
                                    <?php if ($current_inventory_period['opening_balance'] == 0): ?>
                                        <span class="badge bg-warning ms-2">First Period</span>
                                    <?php else: ?>
                                        <span class="badge bg-success ms-2">Balance Carried Forward</span>
                                    <?php endif; ?>
                                    <?php if ($current_inventory_period['opening_balance'] == 9500.00): ?>
                                        <span class="badge bg-danger ms-2">Data Issue Detected</span>
                                    <?php endif; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Balance Flow Visualization -->
                                <div class="balance-flow mb-4">
                                    <div class="row align-items-center">
                                        <div class="col-md-3 balance-step">
                                            <div class="fw-bold">Opening Balance</div>
                                            <div class="fs-4 fw-bold">KSH <?= safeNumberFormat(safeArrayGet($current_inventory_period, 'opening_balance')) ?></div>
                                            <small>
                                                <?php if ($current_inventory_period['opening_balance'] == 0): ?>
                                                    First period - starting from zero
                                                <?php else: ?>
                                                    From previous period's closing balance
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="col-md-1">
                                            <div class="balance-arrow">
                                                <i class="fas fa-plus"></i>
                                            </div>
                                        </div>
                                        <div class="col-md-3 balance-step">
                                            <div class="fw-bold">Current Inventory</div>
                                            <div class="fs-4 fw-bold">KSH <?= safeNumberFormat($total_stock_value) ?></div>
                                            <small>Inventory added this period</small>
                                        </div>
                                        <div class="col-md-1">
                                            <div class="balance-arrow">
                                                <i class="fas fa-equals"></i>
                                            </div>
                                        </div>
                                        <div class="col-md-3 balance-step">
                                            <div class="fw-bold">Closing Balance</div>
                                            <div class="fs-4 fw-bold">KSH <?= safeNumberFormat(safeArrayGet($current_inventory_period, 'closing_balance')) ?></div>
                                            <small>Will carry to next period</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="balance-section text-center">
                                            <h5 class="mb-3"><i class="fas fa-play-circle me-2 text-success"></i>Opening Balance</h5>
                                            <div class="d-flex justify-content-center align-items-center mb-2">
                                                <span class="fs-4 fw-bold text-success">KSH <?= safeNumberFormat(safeArrayGet($current_inventory_period, 'opening_balance')) ?></span>
                                            </div>
                                            <small class="text-muted">
                                                <?php if ($current_inventory_period['opening_balance'] == 0): ?>
                                                    First period - starting fresh
                                                <?php else: ?>
                                                    Previous period's closing balance
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="balance-section text-center">
                                            <h5 class="mb-3"><i class="fas fa-plus-circle me-2 text-info"></i>Current Inventory Value</h5>
                                            <div class="d-flex justify-content-center align-items-center mb-2">
                                                <span class="fs-4 fw-bold text-info">KSH <?= safeNumberFormat($total_stock_value) ?></span>
                                            </div>
                                            <small class="text-muted">Inventory added this period</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="balance-section text-center">
                                            <h5 class="mb-3"><i class="fas fa-stop-circle me-2 text-primary"></i>Closing Balance</h5>
                                            <div class="d-flex justify-content-center align-items-center mb-2">
                                                <span class="fs-4 fw-bold text-primary">KSH <?= safeNumberFormat(safeArrayGet($current_inventory_period, 'closing_balance')) ?></span>
                                            </div>
                                            <small class="text-muted">Opening + Current Inventory</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="balance-section text-center">
                                            <h5 class="mb-3"><i class="fas fa-chart-line me-2 
                                                <?= ($current_inventory_period['closing_balance'] - $current_inventory_period['opening_balance']) >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                                                Balance Change
                                            </h5>
                                            <div class="d-flex justify-content-center align-items-center mb-2">
                                                <span class="fs-4 fw-bold 
                                                    <?= ($current_inventory_period['closing_balance'] - $current_inventory_period['opening_balance']) >= 0 ? 'balance-change-positive' : 'balance-change-negative' ?>">
                                                    <?= (($current_inventory_period['closing_balance'] - $current_inventory_period['opening_balance']) >= 0 ? '+' : '') ?>
                                                    KSH <?= safeNumberFormat($current_inventory_period['closing_balance'] - $current_inventory_period['opening_balance']) ?>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <?= (($current_inventory_period['closing_balance'] - $current_inventory_period['opening_balance']) >= 0 ? 'Increase' : 'Decrease') ?> from opening
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Carried Forward Products -->
                <?php if (!empty($carried_products)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card carry-forward-card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-forward me-2"></i>
                                    Carried Forward Stock from Previous Period
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
                                                <th>Quantity Carried</th>
                                                <th>Cost Price</th>
                                                <th>Carried Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($carried_products as $carried): ?>
                                            <tr>
                                                <td><?= htmlspecialchars(safeArrayGet($carried, 'name')) ?></td>
                                                <td><code><?= htmlspecialchars(safeArrayGet($carried, 'sku')) ?></code></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars(safeArrayGet($carried, 'category_name')) ?></span></td>
                                                <td><?= safeArrayGet($carried, 'quantity') ?></td>
                                                <td>KSH <?= safeNumberFormat(safeArrayGet($carried, 'cost_price')) ?></td>
                                                <td class="fw-bold">KSH <?= safeNumberFormat(safeArrayGet($carried, 'carried_value')) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active">
                                                <td colspan="5" class="text-end fw-bold">Total Carried Value:</td>
                                                <td class="fw-bold text-primary">KSH <?= safeNumberFormat($total_carried_value) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
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
                                        <div class="kpi-value text-primary">KSH <?= safeNumberFormat($total_stock_value) ?></div>
                                        <div class="kpi-label text-muted">
                                            <?php if (!$selected_period_id): ?>
                                                Total Stock Value (All Periods)
                                            <?php else: ?>
                                                Current Inventory Value
                                            <?php endif; ?>
                                        </div>
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
                                        <div class="kpi-value text-success">KSH <?= safeNumberFormat($total_sales_value) ?></div>
                                        <div class="kpi-label text-muted">
                                            <?php if (!$selected_period_id): ?>
                                                Total Sales (All Periods)
                                            <?php else: ?>
                                                Period Sales
                                            <?php endif; ?>
                                        </div>
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
                                        <div class="kpi-value text-warning">KSH <?= safeNumberFormat($total_profit) ?></div>
                                        <div class="kpi-label text-muted">
                                            <?php if (!$selected_period_id): ?>
                                                Gross Profit (All Periods)
                                            <?php else: ?>
                                                Gross Profit
                                            <?php endif; ?>
                                        </div>
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
                                    <?php if (!$selected_period_id): ?>
                                        overall performance
                                    <?php else: ?>
                                        sales trend
                                    <?php endif; ?>
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
                                        <div class="kpi-label text-muted">
                                            <?php if (!$selected_period_id): ?>
                                                Stock Alerts (All Periods)
                                            <?php else: ?>
                                                Stock Alerts
                                            <?php endif; ?>
                                        </div>
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
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    <?php if ($selected_period_id): ?>
                                        Inventory for <?= getPeriodDisplayName($current_period) ?>
                                    <?php else: ?>
                                        Complete Inventory - All Periods
                                    <?php endif; ?>
                                    <small class="text-muted">(<?= $total_products ?> products)</small>
                                    <?php if ($user_role === 'super_admin'): ?>
                                        <span class="badge super-admin-badge ms-2">All Users</span>
                                    <?php endif; ?>
                                    <?php if (!$selected_period_id): ?>
                                        <span class="badge all-periods-badge ms-2">All Periods</span>
                                    <?php endif; ?>
                                </h5>
                                <span class="badge bg-primary">Total Value: KSH <?= safeNumberFormat($total_stock_value) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($products)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="inventoryTable">
                                            <thead>
                                                <tr>
                                                    <?php if ($user_role === 'super_admin'): ?>
                                                        <th>Added By</th>
                                                    <?php endif; ?>
                                                    <th>Period</th>
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
                                                    $stock_quantity = safeArrayGet($product, 'stock_quantity', 0);
                                                    $min_stock = safeArrayGet($product, 'min_stock', 0);
                                                    $stock_status = getStockStatus($stock_quantity, $min_stock);
                                                    $progress_width = min(($stock_quantity / max($min_stock * 3, 1)) * 100, 100);
                                                ?>
                                                    <tr>
                                                        <?php if ($user_role === 'super_admin'): ?>
                                                            <td>
                                                                <span class="badge bg-info"><?= htmlspecialchars(safeArrayGet($product, 'created_by_name', 'Unknown')) ?></span>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?= safeArrayGet($product, 'period_year', 'N/A') ?>-<?= str_pad(safeArrayGet($product, 'period_month', 'N/A'), 2, '0', STR_PAD_LEFT) ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= htmlspecialchars(safeArrayGet($product, 'name')) ?></strong>
                                                                    <?php if (safeArrayGet($product, 'supplier')): ?>
                                                                        <br><small class="text-muted">Supplier: <?= htmlspecialchars(safeArrayGet($product, 'supplier')) ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars(safeArrayGet($product, 'sku')) ?></code>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars(safeArrayGet($product, 'category_name', 'Uncategorized')) ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= safeNumberFormat(safeArrayGet($product, 'cost_price')) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= safeNumberFormat(safeArrayGet($product, 'selling_price')) ?></strong>
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
                                                            <strong>KSH <?= safeNumberFormat(safeArrayGet($product, 'stock_value')) ?></strong>
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
                                        <p class="text-muted">
                                            <?php if ($selected_period_id): ?>
                                                No products found for <?= getPeriodDisplayName($current_period) ?>.
                                            <?php else: ?>
                                                No products found in the system.
                                            <?php endif; ?>
                                        </p>
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
        function exportToExcel() {
            const table = document.getElementById('inventoryTable');
            if (!table) {
                alert('No inventory data to export');
                return;
            }
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, 'Inventory Report');
            
            const periodName = document.querySelector('.period-info-card h2').textContent;
            XLSX.writeFile(wb, `inventory_report_${periodName.replace(/[^a-zA-Z0-9]/g, '_')}.xlsx`);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const periodSelect = document.querySelector('select[name="period_id"]');
            if (periodSelect) {
                periodSelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
        });
    </script>
</body>
</html>